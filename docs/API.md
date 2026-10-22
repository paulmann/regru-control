# API

Базовый URL: `https://<хост>/<префикс>/api/v1/`. В репозитории есть машиночитаемая спецификация [`openapi.yaml`](openapi.yaml), она же отдаётся по `GET /api/v1/openapi.yaml`.

## Локализация

- `Accept-Language: de-DE,de;q=0.9` — язык ответа (`en`, `ru`, `zh`, `hi`, `de`, `es`); по умолчанию `en`;
- `GET /api/v1/locales` — список языков;
- `POST /api/v1/locale` `{"locale":"es"}` — сохранить выбор в cookie `rg_lang`;
- `?lang=ru` — разовый выбор языка (удобно для отладки);
- ответы содержат `Content-Language` и `Vary: Accept-Language, Cookie`;
- коды ошибок (`error.code`) стабильны и не зависят от языка — используйте их для ветвления.

## Аутентификация

| Способ | Заголовки | Кому |
|---|---|---|
| Bearer-токен | `Authorization: Bearer rg_…` | интеграции, cron-скрипты, AI-агенты |
| Cookie-сессия | cookie `rg_session` + `X-CSRF-Token: …` | SPA |

CSRF-токен выдаёт `GET /api/v1/bootstrap` (поле `csrf_token`). Запросы с Bearer-токеном CSRF не требуют: браузер не отправляет их автоматически.

### Scopes

| Scope | Что разрешает | Что подразумевает |
|---|---|---|
| `read` | чтение инвентаря, баланса, журналов | — |
| `write` | продление, автопродление, синхронизация, доступы | `read` |
| `dns:write` | изменение DNS-записей и SOA | `read`, `write` |
| `secrets:read` | расшифровка паролей (каждое чтение — в журнал) | — |
| `admin` | токены, настройки, очистка зоны, сертификат | все остальные |
| `regru:raw` | произвольные команды REG.RU API | — |

## Формат ответа

Успех:

```json
{ "data": { }, "meta": { "total": 68, "page": 1, "per_page": 50 } }
```

Ошибка:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Проверьте заполнение полей.",
    "details": { "fields": { "content": "Для A-записи нужен корректный IPv4-адрес." } }
  },
  "request_id": "8d65ccf249db974a"
}
```

`request_id` совпадает с заголовком `X-Request-Id` и со строкой в журнале — по нему можно найти инцидент.

## Эндпоинты

### Система

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/health` | — | статус, версия, PHP, БД |
| GET | `/bootstrap` | опционально | актор, CSRF, enum'ы, локаль, размер каталога команд |
| GET | `/locales` | — | список поддерживаемых языков |
| POST | `/locale` | — | `{locale}` → cookie `rg_lang` |
| POST | `/auth/login` | — | вход `{login,password}` (+CSRF) |
| POST | `/auth/logout` | сессия | выход |
| GET | `/auth/me` | любое | текущий пользователь/токен |
| POST | `/auth/password` | сессия | смена пароля |
| GET | `/dashboard` | read | агрегаты обзора |
| GET | `/inventory` | read | компактный инвентарь без секретов |
| GET | `/audit` | admin | журнал (`action`, `actor`, `limit`, `offset`) |

### Домены

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/domains` | read | фильтры `q,state,expiring_in,sort,dir,page,per_page` |
| GET | `/domains/{name}` | read | домен + число DNS-записей в кэше |
| POST | `/domains/{name}/renew` | write | `{period}` 1–10 лет |
| POST | `/domains/{name}/autorenew` | write | `{enabled}` |
| PUT | `/domains/{name}/ns` | write | `{nss:[…], undelegate}` |
| POST | `/domains/{name}/refresh` | read | перечитать NS из REG.RU |
| POST | `/domains/{name}/certificate` | admin | PDF-сертификат владения (партнёрам) |
| GET | `/domains/{name}/dnssec` | read | статус DNSSEC |
| POST | `/domains/{name}/dnssec` | admin | `{enabled}` |

### DNS

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/domains/{name}/dns` | read | записи + SOA; `?refresh=1` — из API |
| POST | `/domains/{name}/dns/records` | dns:write | `{subname,rectype,content,prio}` |
| PUT | `/domains/{name}/dns/records` | dns:write | `{old:{…}, subname, rectype, content, prio}` |
| DELETE | `/domains/{name}/dns/records` | dns:write | `{record:{…}}` |
| PUT | `/domains/{name}/dns/soa` | dns:write | `{ttl, minimum_ttl}` |
| POST | `/domains/{name}/dns/clear` | admin | `{confirm:"DELETE"}` |

Поддерживаемые типы: `A, AAAA, CNAME, MX, TXT, NS, SRV, CAA`. Для SRV `content` — `"<вес> <порт> <цель>"`; для CAA — `"[flags] <tag> <value>"`, например `0 issue letsencrypt.org`.

### Хостинги

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/hostings` | read | услуги хостинга/серверов |
| GET | `/hostings/{id}` | read | карточка + детали, если API их отдал |
| POST | `/hostings/sync` | write | `{force}` — повторный `service/get_details` |
| POST | `/hostings/{id}/reveal` | secrets:read | пароль панели (если сохранён) |

### Хранилище доступов

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/credentials` | read | `kind`, `q` |
| POST | `/credentials` | write | создать |
| PUT | `/credentials/{id}` | write | изменить (пароль — необязательно) |
| DELETE | `/credentials/{id}` | write | удалить |
| POST | `/credentials/{id}/reveal` | secrets:read | расшифровать (событие в журнале) |
| POST | `/credentials/generate` | write | `{length}` — сгенерировать пароль |

### Биллинг и синхронизация

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/billing` | read | баланс (`?refresh=1`) |
| GET | `/billing/unpaid` | read | неоплаченные счета |
| GET | `/billing/bills` | read | `from,to,limit,offset` |
| GET | `/sync` | read | статус + история |
| POST | `/sync` | write | `{scope: all|services|details|dns}` |

### Токены и REG.RU API

| Метод | Путь | Право | Описание |
|---|---|---|---|
| GET | `/tokens` | admin | список токенов |
| POST | `/tokens` | admin | `{name,scopes,ttl_days}` → plaintext один раз |
| DELETE | `/tokens/{id}` | admin | отозвать |
| GET | `/meta` | read | enum'ы и каталог команд |
| GET | `/meta/commands` | read | 116 команд REG.RU API v2 |
| GET | `/meta/prices` | read | цены доменов |
| POST | `/regru/call` | regru:raw | `{command,input}` — любой вызов API |

## Примеры

### Инвентарь для агента

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://example.com/regru/api/v1/inventory" | jq '.data | {counts, hostings: [.hostings[] | {title, server_ip, panel_url}]}'
```

### Добавить DNS-запись

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"subname":"api","rectype":"A","content":"203.0.113.10"}' \
  "https://example.com/regru/api/v1/domains/example.com/dns/records" | jq
```

### Создать токен под задачу

```bash
curl -s -X POST \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"monitoring","scopes":["read"],"ttl_days":90}' \
  "https://example.com/regru/api/v1/tokens" | jq '.data.plain'
```

### MCP-сервер

Панель поставляется с MCP-сервером для AI-агентов: `php mcp/server.php`. Настройка и список инструментов — [`docs/MCP.md`](MCP.md), краткая инструкция — [`AGENTS.md`](../AGENTS.md).

## Лимиты

- Вход: 10 попыток на аккаунт за 15 минут + лимит по IP.
- API панели: 600 запросов в минуту на токен/IP (`security.api_rate_limit`).
- Исходящие REG.RU: локальный предохранитель 1100/час; официальный лимит аккаунта — 1200/час.
- Ошибка лимита — 429 с `Retry-After`.
