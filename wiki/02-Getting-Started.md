# 02 · Установка и первый запуск

## Требования

| Компонент | Требование |
|---|---|
| PHP | 8.5 (CLI + FPM), расширения `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`, `sodium` |
| Веб-сервер | nginx (рекомендуется) или Apache с rewrite |
| TLS | обязателен для продакшена |
| Внешний доступ | IP сервера в белом списке REG.RU API |
| Composer, Node, MySQL | не требуются |

## Локальный запуск

```bash
cp .env.example .env
php backend/bin/regru key:generate
php backend/bin/regru migrate
php backend/bin/regru user:create --login=admin
php backend/bin/regru serve --port=8099
```

Откройте `http://127.0.0.1:8099`.

## Установка на сервер

1. Соберите дерево публикации без `.git`, `var/` и тестов:

   ```bash
   php tools/build.php --target=../deploy
   ```

2. Скопируйте `deploy/` в каталог сайта; веб-корень — каталог с `index.php`.
3. Создайте `.env` на сервере и примените миграции:

   ```bash
   php backend/bin/regru key:generate
   php backend/bin/regru migrate
   ```

4. Настройте nginx: `try_files`, PHP только для `index.php`, `deny all` для `backend/`, `frontend/`, `var/`, `docs/`, dotfiles. Пример — `docs/deploy/nginx-regru-control.conf`.
5. Ограничьте доступ списком `allow`/`deny` и включите HTTPS.
6. Создайте администратора и подключите аккаунт REG.RU.

## Первый результат

```bash
# пароль API — через stdin, чтобы не попасть в историю shell
php backend/bin/regru account:set --login=<API_LOGIN> --password-stdin

php backend/bin/regru doctor --online
php backend/bin/regru sync --scope=all
```

Проверка:

```bash
curl -s https://example.com/regru/api/v1/health
# {"data":{"status":"ok","version":"1.0.0","db":{"ok":true},"regru_configured":true}}
```

Токен для агента:

```bash
php backend/bin/regru token:create --name=agent --scopes=read --ttl-days=90
```

## Cron

```cron
*/30 * * * * cd <APP_DIR> && php backend/bin/regru sync --scope=all >/dev/null 2>&1
15 3 * * *   cd <APP_DIR> && php backend/bin/regru backup >/dev/null 2>&1
```

## Обновление

```bash
git pull
php tools/lint.php
php backend/tests/run.php
php backend/bin/regru backup
php tools/build.php --target=../deploy
# выкладка и миграции
php backend/bin/regru migrate
php backend/bin/regru doctor --online
```

Перед обновлением сохраните `.env`; предыдущий релиз на сервере архивируется в `/root/regru-backup/`.

Дальше: [Конфигурация](03-Configuration-Reference) · [Эксплуатация](08-Operations)
