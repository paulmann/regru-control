# 05 · API

Базовый URL: `https://<хост>/<префикс>/api/v1/`. Машиночитаемая спецификация — `/api/v1/openapi.yaml`.

## Аутентификация

| Способ | Заголовки | Для кого |
|---|---|---|
| Bearer | `Authorization: Bearer rg_…` | интеграции, cron, AI-агент |
| Cookie-сессия | cookie `rg_session` + `X-CSRF-Token` | веб-интерфейс |

CSRF-токен выдаёт `GET /api/v1/bootstrap`. Bearer-запросы CSRF не требуют.

## Scopes

`read` → `write` → `dns:write`; отдельно `secrets:read`, `admin`, `regru:raw`.

| Scope | Что разрешает |
|---|---|
| `read` | инвентарь, баланс, журналы |
| `write` | продление, автопродление, синхронизация, доступы |
| `dns:write` | DNS-записи и SOA |
| `secrets:read` | расшифровка паролей (каждое чтение — в журнал) |
| `admin` | токены, настройки, очистка зоны, сертификат |
| `regru:raw` | произвольные команды REG.RU API |

## Формат

Успех:

```json
{ "data": { }, "meta": { "total": 68, "page": 1, "per_page": 50 } }
```

Ошибка:

```json
{
  "error": { "code": "VALIDATION_FAILED", "message": "…", "details": { } },
  "request_id": "8d65ccf249db974a"
}
```

`request_id` = заголовок `X-Request-Id` = строка в журнале.

## Локализация

- `Accept-Language: de-DE,de;q=0.9` — язык ответа (`en`, `ru`, `zh`, `hi`, `de`, `es`); по умолчанию `en`.
- `GET /api/v1/locales` — список языков.
- `POST /api/v1/locale` `{"locale":"es"}` — сохранить выбор в cookie.
- Коды ошибок стабильны и не зависят от языка.

## Эндпоинты

### Система

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/health` | — | статус, версия, PHP, БД |
| GET | `/bootstrap` | опционально | актор, CSRF, enum'ы, локаль |
| GET | `/locales` | — | список языков |
| POST | `/locale` | — | сохранить язык |
| POST | `/auth/login` | — | вход |
| POST | `/auth/logout` | сессия | выход |
| GET | `/auth/me` | любое | текущий актор |
| POST | `/auth/password` | сессия | смена пароля |
| GET | `/dashboard` | read | агрегаты обзора |
| GET | `/inventory` | read | инвентарь без секретов |
| GET | `/audit` | admin | журнал |

### Домены и DNS

| Метод | Путь | Право |
|---|---|---|
| GET | `/domains` | read |
| GET | `/domains/{name}` | read |
| POST | `/domains/{name}/renew` | write |
| POST | `/domains/{name}/autorenew` | write |
| PUT | `/domains/{name}/ns` | write |
| POST | `/domains/{name}/refresh` | read |
| POST | `/domains/{name}/certificate` | admin |
| GET/POST | `/domains/{name}/dnssec` | read / admin |
| GET | `/domains/{name}/dns` | read |
| POST/PUT/DELETE | `/domains/{name}/dns/records` | dns:write |
| PUT | `/domains/{name}/dns/soa` | dns:write |
| POST | `/domains/{name}/dns/clear` | admin |

Типы записей: `A, AAAA, CNAME, MX, TXT, NS, SRV, CAA`. Для SRV `content` — `"<вес> <порт> <цель>"`; для CAA — `"[flags] <tag> <value>"`.

### Хостинги, доступы, биллинг

| Метод | Путь | Право |
|---|---|---|
| GET | `/hostings` | read |
| GET | `/hostings/{id}` | read |
| POST | `/hostings/sync` | write |
| POST | `/hostings/{id}/reveal` | secrets:read |
| GET/POST/PUT/DELETE | `/credentials…` | read/write |
| POST | `/credentials/{id}/reveal` | secrets:read |
| POST | `/credentials/generate` | write |
| GET | `/billing`, `/billing/unpaid`, `/billing/bills` | read |
| GET/POST | `/sync` | read / write |
| GET/POST | `/tokens` | admin |
| DELETE | `/tokens/{id}` | admin |
| GET | `/meta`, `/meta/commands`, `/meta/prices` | read |
| POST | `/regru/call` | regru:raw |

## Примеры

```bash
# инвентарь
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://example.com/regru/api/v1/inventory" | jq

# DNS-запись
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"subname":"api","rectype":"A","content":"203.0.113.10"}' \
  "https://example.com/regru/api/v1/domains/example.com/dns/records"

# любая команда REG.RU
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"command":"user/get_balance","input":{}}' \
  "https://example.com/regru/api/v1/regru/call"
```

## Лимиты

- Вход: 10 попыток на аккаунт за 15 минут + лимит по IP.
- API панели: 600 запросов в минуту на токен/IP.
- Исходящие REG.RU: локальный предохранитель 1100/час; официально 1200/час.
- Ошибка лимита — `429` с `Retry-After`.

Дальше: [MCP](06-MCP) · [Неполадки](09-Troubleshooting)
