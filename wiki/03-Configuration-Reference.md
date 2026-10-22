# 03 · Справочник конфигурации

## Переменные `.env`

| Переменная | Назначение | По умолчанию |
|---|---|---|
| `APP_ENV` | `production`, `development`, `test` | `production` |
| `APP_DEBUG` | подробные ошибки | `0` |
| `APP_URL` | публичный адрес панели | — |
| `APP_KEY` | base64-ключ шифрования (32 байта) | генерируется |
| `DB_PATH` | путь к SQLite | `var/regru.sqlite` |
| `SESSION_SECURE` | cookie только по HTTPS | `1` |
| `SESSION_IDLE` | таймаут бездействия, секунды | `1800` |
| `REGRU_TIMEOUT` | таймаут API REG.RU, секунды | `20` |
| `REGRU_RETRIES` | повторы при транспортных ошибках | `3` |
| `REGRU_RATE_LIMIT_PER_HOUR` | локальный предохранитель | `1100` |
| `APP_TRUSTED_PROXIES` | доверенные прокси для `X-Forwarded-For` | пусто |

`APP_KEY` — единственный ключ к расшифровке сохранённых паролей. Храните резервную копию `.env` отдельно от базы: без ключа пароли не восстановить.

## Ключи конфигурации

| Ключ | Смысл |
|---|---|
| `app.url`, `app.timezone`, `app.debug` | общие параметры приложения |
| `session.name`, `session.idle`, `session.secure` | cookie и таймаут сессии |
| `regru.base_url`, `regru.timeout`, `regru.retries` | клиент REG.RU API2 |
| `sync.ttl`, `sync.details_batch` | синхронизация |
| `security.login_attempts`, `security.login_window` | защита входа |
| `security.api_rate_limit`, `security.api_rate_window` | лимит API панели |
| `security.reveal_ttl` | окно повторного раскрытия секрета |

## Аккаунт REG.RU

1. В личном кабинете REG.RU создайте пароль API (он отличается от пароля входа).
2. Разрешите API-доступ только с IP сервера.
3. Подключите аккаунт:

   ```bash
   php backend/bin/regru account:set --login=<API_LOGIN> --password-stdin
   php backend/bin/regru account:test
   ```

4. Выполните синхронизацию: `php backend/bin/regru sync --scope=all`.

## Язык

Локаль определяется цепочкой: `?lang=` → cookie `rg_lang` → `Accept-Language` → `en`.

- Переключатель — в шапке и на экране входа.
- API отвечает на языке `Accept-Language`; коды ошибок не зависят от языка.
- Каталоги: `backend/resources/locales/*.json`, `frontend/assets/js/locales/*.json`.
- Проверка: `php tools/i18n-check.php`.

## MCP-сервер

| Переменная | Смысл |
|---|---|
| `REGRU_PANEL_URL` | база панели, например `https://example.com/regru` |
| `REGRU_API_TOKEN` | Bearer-токен |
| `REGRU_MCP_ALLOW_WRITE` | `1` включает изменяющие инструменты |
| `REGRU_MCP_LOCALE` | язык ответов API |
| `REGRU_MCP_TIMEOUT` | таймаут HTTP, секунды |
| `REGRU_MCP_CAINFO` | путь к CA-бандлу |
| `REGRU_MCP_INSECURE` | `1` отключает проверку TLS (только LAN) |

## Файлы и каталоги

| Путь | Содержимое |
|---|---|
| `var/regru.sqlite` | база (WAL) |
| `var/log/app.log` | JSON-лог без секретов |
| `var/sessions/` | файлы сессий |
| `var/backups/` | снимки базы (14 копий) |
| `.env` | ключи и настройки (0600) |

Дальше: [API](05-API) · [MCP](06-MCP) · [Безопасность](07-Security)
