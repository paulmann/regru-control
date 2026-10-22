# Развёртывание

Панель — обычное PHP-приложение: статические файлы + один front controller. Ниже — проверенный сценарий для Debian 12 + nginx + PHP-FPM 8.5 (FastPanel/ISPmanager), но подойдёт любой хостинг с PHP 8.5.

## 1. Требования

- PHP 8.5 (FPM и CLI) с расширениями `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`, `sodium`;
- nginx (или Apache) с поддержкой `try_files`/rewrite;
- ~10 МБ диска + место под резервные копии;
- исходящий HTTPS к `api.reg.ru`;
- IP сервера добавлен в белый список REG.RU API (иначе будет `ACCESS_DENIED_FROM_IP`).

Проверка окружения:

```bash
php -v
php -m | grep -E 'curl|json|mbstring|openssl|pdo_sqlite|sodium'
```

## 2. Сборка

Репозиторий содержит исходники; веб-корнем становится результат `tools/build.php`.

```bash
php tools/build.php --target=../deploy
```

Скрипт собирает `index.php`, `assets/`, `backend/`, `frontend/index.html`, `docs/openapi.yaml`, `.env.example`, создаёт `var/` и не трогает существующие `.env`/`var`.

## 3. Деплой по ssh

`tools/deploy.ps1` (PowerShell 7) читает настройки из `.env.deploy` (см. `.env.deploy.example`), собирает архив без `.env`/`var`, загружает его, распаковывает, сохраняет резервную копию предыдущего релиза в `/root/regru-backup/`, применяет миграции и проверяет health-эндпоинт.

```powershell
Copy-Item .env.deploy.example .env.deploy
notepad .env.deploy          # хост, ключ, путь, URL, сокет FPM, список сетей
pwsh -NoProfile -File tools/deploy.ps1
```

Ключевые параметры `.env.deploy`:

| Переменная | Пример | Смысл |
|---|---|---|
| `REGRU_HOST` | `server.example.com` | SSH-хост |
| `REGRU_SSH_KEY` | `C:\Users\you\.ssh\id_ed25519` | приватный ключ |
| `REGRU_REMOTE_PATH` | `/var/www/site/data/www/example.com/regru` | каталог приложения |
| `REGRU_SITE_USER` | `site_user` | владелец файлов и PHP-FPM |
| `REGRU_APP_URL` | `https://example.com/regru/` | публичный адрес |
| `REGRU_PHP_BIN` | `/opt/php85/bin/php` | CLI PHP |
| `REGRU_FPM_SOCKET` | `/var/run/example.com.sock` | сокет пула сайта |
| `REGRU_ALLOW_CIDR` / `REGRU_ALLOW_IP` | `192.0.2.0/24` / `203.0.113.10` | кому открыт доступ |

Первый запуск создаёт `.env` с новым `APP_KEY` и production-настройками; повторные — сохраняют `.env` и `var/`.

## 4. nginx

Пример блока — [`docs/deploy/nginx-regru-control.conf`](deploy/nginx-regru-control.conf). Главное:

- `location ^~ /regru/` с `allow`/`deny`, `try_files … /regru/index.php`;
- исполняется только `index.php`; `backend/`, `frontend/`, `var/`, `docs/` и dotfiles закрыты;
- статика отдаётся nginx напрямую.

FastPanel: собственные директивы — только в `/etc/nginx/fastpanel2-sites/<owner>/<domain>.includes` (файл `<domain>.conf` панель перезаписывает). После правки:

```bash
nginx -t && systemctl reload nginx
```

Проверка:

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://example.com/regru/                     # 200
curl -sk -o /dev/null -w '%{http_code}\n' https://example.com/regru/.env                 # 403
curl -sk -o /dev/null -w '%{http_code}\n' https://example.com/regru/backend/bootstrap.php # 403
curl -sk https://example.com/regru/api/v1/health
```

## 5. Первичная настройка

```bash
cd /var/www/site/data/www/example.com/regru

php backend/bin/regru migrate
php backend/bin/regru user:create --login=admin            # пароль сгенерируется
php backend/bin/regru account:set --login=ВАШ_ЛОГИН --password-stdin <<< 'пароль API'
php backend/bin/regru token:create --name=agent --scopes=read,secrets:read,regru:raw --ttl-days=365
php backend/bin/regru sync --scope=all
```

Секреты через stdin не попадают в `ps` и историю shell.

## 6. Cron

```cron
# инвентарь каждые 30 минут, резервная копия в 03:15
*/30 * * * * cd /var/www/site/data/www/example.com/regru && /opt/php85/bin/php backend/bin/regru sync --scope=all >/dev/null 2>&1
15 3 * * *   cd /var/www/site/data/www/example.com/regru && /opt/php85/bin/php backend/bin/regru backup >/dev/null 2>&1
```

Полная синхронизация — около 15 запросов к REG.RU, это далеко от лимита 1200/час. Резервные копии складываются в `var/backups` и ротируются (14 последних).

## 7. Обновление и откат

```powershell
pwsh -NoProfile -File tools/deploy.ps1            # сборка + деплой + миграции
```

Перед распаковкой на сервере создаётся архив предыдущего релиза. Откат:

```bash
ls /root/regru-backup/
tar -xzf /root/regru-backup/regru-YYYYmmdd-HHMMSS.tgz -C /var/www/site/data/www/example.com/regru
# при необходимости откатить схему БД — восстановить var/regru.sqlite из var/backups
chown -R site_user:site_user /var/www/site/data/www/example.com/regru
```

Миграции только добавляют объекты, поэтому откат кода без отката БД обычно безопасен.

## 8. Чек-лист продакшена

- [ ] `APP_ENV=production`, `APP_DEBUG=0`, `APP_KEY` создан, `.env` 0600;
- [ ] HTTPS и `SESSION_SECURE=1`;
- [ ] панель закрыта списком сетей, служебные каталоги отдают 403;
- [ ] IP сервера разрешён в REG.RU API;
- [ ] создан администратор, пароль сохранён в менеджере секретов;
- [ ] настроен аккаунт REG.RU и выполнена синхронизация;
- [ ] создан отдельный токен для агента, права минимальны;
- [ ] cron синхронизации и бэкапов установлен;
- [ ] `php backend/bin/regru doctor --online` без ошибок;
- [ ] проверены `audit_log` и health-эндпоинт.
