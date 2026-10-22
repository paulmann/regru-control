# AGENTS.md — инструкции для агентов и администраторов

Практическое руководство: как скачать, установить, настроить и обслуживать **REG.RU Control** — самостоятельную панель управления доменами, DNS и хостингами REG.RU (PHP 8.5 + SQLite, без Composer и Node).

Документация: [README](README.md) · [архитектура](docs/ARCHITECTURE.md) · [API](docs/API.md) · [MCP-сервер](docs/MCP.md) · [развёртывание](docs/DEPLOY.md) · [эксплуатация](docs/TROUBLESHOOTING.md) · [руководство пользователя](docs/USER-GUIDE.md) · [разработка](docs/DEVELOPMENT.md).

---

## 1. Что это

- Инвентарь аккаунта REG.RU: домены, хостинги, VPS, выделенные серверы, лицензии, баланс.
- Управление доменами: продление, автопродление, DNS-серверы, DNSSEC, сертификат владения.
- DNS-редактор: A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, SOA/TTL.
- Шифрованное хранилище доступов (libsodium) с аудитом раскрытия.
- JSON API с Bearer-токенами и scopes; **MCP-сервер** для AI-агентов.
- Шесть языков: `en` (по умолчанию), `ru`, `zh`, `hi`, `de`, `es`.

Панель — приватный административный инструмент. Не публикуйте её в открытый интернет без списка разрешённых сетей и HTTPS.

---

## 2. Скачивание

```bash
git clone https://github.com/<owner>/regru-control.git
cd regru-control
```

Без git: скачайте архив релиза и распакуйте. Репозиторий не содержит `.env`, базы и секретов — они создаются при установке.

Проверка целостности после клонирования:

```bash
php tools/lint.php
php backend/tests/run.php
php tools/secret-scan.php --repo=.
```

---

## 3. Требования

| Компонент | Версия |
|---|---|
| PHP CLI + FPM | 8.5 |
| Расширения PHP | `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`, `sodium` |
| Веб-сервер | nginx (рекомендуется) или Apache с rewrite |
| TLS | обязателен для продакшена |
| Внешний доступ | IP сервера в белом списке REG.RU API |
| Composer / Node / MySQL | **не нужны** |

Проверка окружения:

```bash
php backend/bin/regru doctor
```

---

## 4. Установка

### 4.1 Локально

```bash
cp .env.example .env
php backend/bin/regru key:generate
php backend/bin/regru migrate
php backend/bin/regru user:create --login=admin
php backend/bin/regru serve --port=8099
```

Откройте `http://127.0.0.1:8099`.

### 4.2 На сервере

1. Соберите дерево публикации (без `.git`, `var/`, тестов):

   ```bash
   php tools/build.php --target=../deploy
   ```

2. Скопируйте `deploy/` в каталог сайта; веб-корень — каталог с `index.php`.
3. Создайте `.env` (или запустите `key:generate` на сервере), примените `migrate`.
4. Настройте nginx: `try_files`, PHP только для `index.php`, `deny all` для `backend/`, `frontend/`, `var/`, `docs/`, dotfiles.
5. Ограничьте доступ списком сетей (`allow`/`deny`) и включите HTTPS.
6. Создайте администратора, подключите аккаунт REG.RU, выполните синхронизацию.

Готовый пример конфигурации: [`docs/deploy/nginx-regru-control.conf`](docs/deploy/nginx-regru-control.conf) (плейсхолдеры), полная процедура — [`docs/DEPLOY.md`](docs/DEPLOY.md).

### 4.3 Подкаталог

Панель работает под префиксом (`https://example.com/regru/`). Базовый путь вычисляется из `SCRIPT_NAME` автоматически; дополнительных настроек в коде нет. Убедитесь, что nginx передаёт правильный `SCRIPT_NAME`/`SCRIPT_FILENAME` и статика отдаётся по `/regru/assets/...`.

---

## 5. Настройка

Основные переменные `.env` (полный список — [`.env.example`](.env.example)):

| Переменная | Назначение | Пример |
|---|---|---|
| `APP_ENV` | окружение | `production` |
| `APP_DEBUG` | подробные ошибки | `0` |
| `APP_URL` | публичный адрес | `https://example.com/regru` |
| `APP_KEY` | ключ шифрования (base64, 32 байта) | генерируется |
| `DB_PATH` | путь к SQLite | `var/regru.sqlite` |
| `SESSION_SECURE` | cookie только по HTTPS | `1` |
| `REGRU_TIMEOUT` | таймаут API, сек | `20` |
| `REGRU_RETRIES` | повторы при сетевых ошибках | `3` |
| `REGRU_RATE_LIMIT_PER_HOUR` | локальный предохранитель | `1100` |

`APP_KEY` — единственный ключ к расшифровке паролей. Резервная копия `.env` обязательна; при потере ключа пароли восстановить нельзя.

### 5.1 Аккаунт REG.RU

1. В личном кабинете REG.RU создайте пароль API (раздел настроек API; он отличается от пароля входа).
2. Разрешите API-доступ только с IP сервера.
3. Подключите аккаунт:

   ```bash
   php backend/bin/regru account:set --login=<API_LOGIN> --password-stdin
   ```

4. Первичная синхронизация:

   ```bash
   php backend/bin/regru sync --scope=all
   php backend/bin/regru doctor --online
   ```

### 5.2 Язык

- Автоопределение: `?lang=` → cookie `rg_lang` → `Accept-Language` → английский.
- Переключатель — в шапке и на экране входа; выбор сохраняется.
- API отвечает на языке `Accept-Language`; коды ошибок не зависят от языка.
- Каталоги: `frontend/assets/js/locales/` и `backend/resources/locales/`. Обновление: `php tools/i18n-extract.php`, проверка: `php tools/i18n-check.php`.

---

## 6. Управление

### 6.1 CLI

```bash
php backend/bin/regru routes                 # список маршрутов API
php backend/bin/regru doctor [--online]      # окружение и связь с REG.RU
php backend/bin/regru sync --scope=all      # синхронизация инвентаря
php backend/bin/regru backup                 # снимок SQLite (14 копий)
php backend/bin/regru user:list              # пользователи
php backend/bin/regru token:list             # API-токены
php backend/bin/regru token:revoke --id=3    # отзыв токена
php backend/bin/regru account:show           # подключённый аккаунт REG.RU
php backend/bin/regru api:call <command> --input='{...}'   # raw-вызов REG.RU
php backend/bin/regru serve --port=8099      # локальный сервер
```

### 6.2 Cron

```cron
*/30 * * * * cd <APP_DIR> && php backend/bin/regru sync --scope=all >/dev/null 2>&1
15 3 * * *   cd <APP_DIR> && php backend/bin/regru backup >/dev/null 2>&1
```

### 6.3 Обновление

```bash
git pull
php tools/lint.php
php backend/tests/run.php
php tools/build.php --target=../deploy
# выкладка: rsync/scp или tools/deploy.ps1 (из Windows)
php backend/bin/regru migrate
php backend/bin/regru doctor --online
```

Перед обновлением: `php backend/bin/regru backup`, сохранить `.env`.

### 6.4 Мониторинг

- `GET /api/v1/health` — публичный статус (version, PHP, DB).
- `GET /api/v1/sync` — когда была синхронизация, есть ли ошибки этапов.
- Журнал: `var/log/app.log` (JSON, без секретов), UI «Журнал», `GET /api/v1/audit`.
- `request_id` в ответе API = `X-Request-Id` = строка в логе.

---

## 7. API и MCP для агентов

### 7.1 HTTP API

```bash
php backend/bin/regru token:create --name=agent --scopes=read --ttl-days=90
```

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://example.com/regru/api/v1/inventory" | jq
```

- Базовый URL: `/api/v1/`; OpenAPI: `/api/v1/openapi.yaml`.
- Scopes: `read`, `write`, `dns:write`, `secrets:read`, `admin`, `regru:raw`.
- Раскрытие пароля — `POST /api/v1/credentials/{id}/reveal`, scope `secrets:read`, каждое чтение в журнале.
- Ошибки: `{"error":{"code","message","details"},"request_id"}`; ветвление — по `code`.
- Лимиты: вход 10/15 мин, API 600/мин, REG.RU 1200/час (локально 1100).

Подробно: [`docs/API.md`](docs/API.md).

### 7.2 MCP-сервер

```bash
REGRU_PANEL_URL=https://example.com/regru \
REGRU_API_TOKEN=rg_... \
php mcp/server.php
```

- 13 инструментов: инвентарь, домены, DNS, хостинги, доступы, биллинг, синхронизация, продление, raw-вызов.
- По умолчанию только чтение; изменения — `REGRU_MCP_ALLOW_WRITE=1`.
- Конфигурация клиента: [`.mcp.json.example`](.mcp.json.example), описание: [`docs/MCP.md`](docs/MCP.md).
- Проверка: `php mcp/server.php --self-test`.
- На сервере (вне веб-корня): `/usr/local/bin/regru-mcp`, конфигурация `/var/www/<site_user>/data/regru-mcp/mcp.env` (0600).

---

## 8. Разработка

```bash
php tools/lint.php --text-files
php backend/tests/run.php
php tools/i18n-check.php
php tools/secret-scan.php --repo=.
```

- Архитектура и точки расширения: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
- Соглашения и добавление эндпоинта: [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md).
- Новые строки интерфейса: русская строка — message id; после правок `php tools/i18n-extract.php`, затем переводы в `frontend/assets/js/locales/*.json`, затем `php tools/i18n-check.php`.
- Все текстовые файлы — UTF-8 без BOM, LF.

---

## 9. Безопасность (обязательно)

1. `APP_ENV=production`, `APP_DEBUG=0`, HTTPS, `SESSION_SECURE=1`.
2. Закройте панель списком сетей в nginx; служебные каталоги — `deny all`.
3. Разрешите API REG.RU только с IP сервера.
4. `.env` — права `0600`, вне git; бэкап `.env` отдельно от БД.
5. Агентам — отдельный токен с минимальными scopes; отзывайте неиспользуемые.
6. Не передавайте `APP_KEY`, пароль API и токены в чаты/логи/скриншоты.
7. При компрометации: отозвать токены, сменить пароль API и панели, проверить журнал, при утечке `APP_KEY` — сменить сохранённые пароли.

Подробно: [`SECURITY.md`](SECURITY.md).

---

## 10. Структура репозитория

```
backend/        PHP: ядро, HTTP, REG.RU, модели, репозитории, сервисы, CLI
frontend/       SPA без сборки: ES-модули, CSS, i18n-каталоги
mcp/            MCP-сервер (stdio)
public/         единственная точка входа index.php
docs/           архитектура, API, MCP, деплой, эксплуатация, OpenAPI
tools/          lint, build, i18n, secret-scan, deploy
backend/tests/  автотесты без сети
```

---

## 11. Диагностика

| Проблема | Что проверить |
|---|---|
| `ACCESS_DENIED_FROM_IP` | IP сервера в белом списке REG.RU |
| Пустые DNS-зоны | домен делегирован на `ns1.reg.ru`/`ns2.reg.ru`? |
| Нет пароля хостинга | REG.RU API не отдаёт его для части услуг — сохраните вручную в «Доступах» |
| 502/500 | `php backend/bin/regru doctor`, `var/log/app.log`, права на `var/` |
| MCP: SSL error | `REGRU_MCP_CAINFO` или системный CA |
| MCP: `UNAUTHORIZED` | токен/URL; проверьте `curl /api/v1/health` |

Полный список: [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md).

---

## 12. Лицензия

MIT — см. [LICENSE](LICENSE).
