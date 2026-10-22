# API

Базовый URL: `https://<хост>/<префикс>/api/v1/`. Машиночитаемая спецификация — `/api/v1/openapi.yaml`.

## MCP-сервер

Для AI-агентов есть MCP-сервер (stdio): `php mcp/server.php`. 13 инструментов — инвентарь, домены, DNS, хостинги, доступы, биллинг, синхронизация, raw-вызов. По умолчанию только чтение; изменения включаются `REGRU_MCP_ALLOW_WRITE=1`. Переменные: `REGRU_PANEL_URL`, `REGRU_API_TOKEN`; шаблон конфигурации — `.mcp.json.example` в репозитории, подробности — `docs/MCP.md`.

## Аутентификация

| Способ | Заголовки | Для кого |
|---|---|---|
| Bearer | `Authorization: Bearer rg_…` | интеграции, cron, AI-агент |
| Cookie-сессия | cookie + `X-CSRF-Token` | веб-интерфейс |

CSRF-токен выдаёт `GET /api/v1/bootstrap`.

## Scopes

`read` → `write` → `dns:write`; отдельно `secrets:read`, `admin`, `regru:raw`.

- `read` — инвентарь, баланс, журналы;
- `write` — изменения и синхронизация;
- `dns:write` — DNS-записи и SOA;
- `secrets:read` — расшифровка паролей (каждое чтение — в журнал);
- `admin` — токены, настройки, очистка зоны, сертификат;
- `regru:raw` — произвольные команды REG.RU API.

## Формат

Успех: `{"data":…,"meta":{…}}`. Ошибка: `{"error":{"code","message","details"},"request_id":"…"}`.

## Основные эндпоинты

| Метод | Путь | Право |
|---|---|---|
| GET | `/health` | — |
| GET | `/inventory` | read |
| GET | `/domains` | read |
| POST | `/domains/{name}/renew` | write |
| PUT | `/domains/{name}/ns` | write |
| GET | `/domains/{name}/dns` | read |
| POST | `/domains/{name}/dns/records` | dns:write |
| PUT | `/domains/{name}/dns/records` | dns:write |
| DELETE | `/domains/{name}/dns/records` | dns:write |
| GET | `/hostings` | read |
| POST | `/hostings/{id}/reveal` | secrets:read |
| GET/POST/PUT/DELETE | `/credentials…` | read/write |
| POST | `/credentials/{id}/reveal` | secrets:read |
| GET | `/billing` | read |
| POST | `/sync` | write |
| GET/POST | `/tokens` | admin |
| POST | `/regru/call` | regru:raw |

## Примеры

```bash
# инвентарь для агента
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

Вход — 10 попыток/15 минут; API — 600 запросов/минуту на токен; исходящие REG.RU — 1100/час. При превышении — `429` и `Retry-After`.

Полное описание — `docs/API.md` в репозитории.
