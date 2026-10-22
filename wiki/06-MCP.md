# 06 · MCP-сервер

MCP-сервер превращает HTTP API панели в инструменты для AI-агентов. Транспорт — stdio, JSON-RPC 2.0, протокол `2024-11-05`; реализация `mcp/server.php` без зависимостей.

## Токен

```bash
php backend/bin/regru token:create --name=agent-mcp --scopes=read --ttl-days=90
```

Права добавляются по необходимости: `secrets:read` — раскрытие паролей, `write` — синхронизация и продление, `dns:write` — DNS-записи, `regru:raw` — raw-вызовы.

## Переменные окружения

| Переменная | Обязательна | Смысл |
|---|---|---|
| `REGRU_PANEL_URL` | да | база панели, например `https://example.com/regru` |
| `REGRU_API_TOKEN` | да | Bearer-токен |
| `REGRU_MCP_ALLOW_WRITE` | нет | `1` включает изменяющие инструменты |
| `REGRU_MCP_LOCALE` | нет | язык ответов API |
| `REGRU_MCP_TIMEOUT` | нет | таймаут HTTP, секунды |
| `REGRU_MCP_CAINFO` | нет | путь к CA-бандлу |
| `REGRU_MCP_INSECURE` | нет | `1` отключает TLS-проверку (только LAN) |

Проверка:

```bash
php mcp/server.php --self-test
# OK: 13 tools, protocol 2024-11-05
```

## Подключение клиента

```json
{
  "mcpServers": {
    "regru-control": {
      "command": "php",
      "args": ["/path/to/regru-control/mcp/server.php"],
      "env": {
        "REGRU_PANEL_URL": "https://example.com/regru",
        "REGRU_API_TOKEN": "rg_REPLACE_ME",
        "REGRU_MCP_ALLOW_WRITE": "0"
      }
    }
  }
}
```

Шаблон — `.mcp.json.example` в репозитории. На сервере MCP запускается вне веб-корня, например `/usr/local/bin/regru-mcp` с env-файлом `0600`.

## Инструменты

| Инструмент | Изменяет | Право |
|---|---|---|
| `regru_inventory` | нет | read |
| `regru_domains` | нет | read |
| `regru_domain_get` | нет | read |
| `regru_dns_records` | нет | read |
| `regru_dns_add_record` | да | dns:write |
| `regru_dns_delete_record` | да | dns:write |
| `regru_hostings` | нет | read |
| `regru_credentials` | нет | read |
| `regru_credential_reveal` | нет (аудит) | secrets:read |
| `regru_billing` | нет | read |
| `regru_sync` | да | write |
| `regru_domain_renew` | да | write |
| `regru_raw_call` | да | regru:raw |

Без `REGRU_MCP_ALLOW_WRITE=1` write-инструменты отвечают ошибкой, а `regru_raw_call` разрешает только команды `*/get_*`.

## Безопасность

- Только чтение по умолчанию; изменения — осознанный флаг.
- Токен передаётся в заголовке и не логируется; секреты не принимаются аргументами инструмента.
- Раскрытие пароля — отдельное право и событие журнала.
- HTTPS обязателен; для самоподписанного сертификата — `REGRU_MCP_CAINFO`.

## Диагностика

| Симптом | Решение |
|---|---|
| `REGRU_PANEL_URL and REGRU_API_TOKEN must be set` | не заданы переменные |
| `Authentication required` | токен отозван или неверный URL |
| `Insufficient permissions` | нет нужного scope |
| `SSL certificate problem` | задайте `REGRU_MCP_CAINFO` |
| `This tool changes data…` | включите `REGRU_MCP_ALLOW_WRITE=1` |
| `429` | лимит панели/REG.RU, повторите позже |

Дальше: [API](05-API) · [Безопасность](07-Security)
