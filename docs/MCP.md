# MCP-сервер

Панель поставляется с MCP-сервером (Model Context Protocol) — AI-агент получает доступ к доменам, DNS, хостингам, доступам и биллингу через стандартные инструменты, без ручного curl.

- Транспорт: **stdio**, JSON-RPC 2.0, MCP `2024-11-05`.
- Реализация: `mcp/server.php`, чистый PHP 8.5, без зависимостей.
- Запускается из копии репозитория (в веб-корень не выкладывается).
- Только HTTP к API панели: сервер не ходит в REG.RU напрямую и не читает БД.

## 1. Токен

Создайте токен с минимальными правами:

```bash
php backend/bin/regru token:create \
  --name=agent-mcp --scopes=read --ttl-days=90
```

Добавляйте права по необходимости:

| Право | Зачем |
|---|---|
| `read` | инвентарь, домены, DNS (чтение), хостинги, биллинг |
| `secrets:read` | `regru_credential_reveal` — каждое раскрытие пишется в журнал |
| `write` | `regru_sync`, `regru_domain_renew` |
| `dns:write` | `regru_dns_add_record`, `regru_dns_delete_record` |
| `regru:raw` | `regru_raw_call` |

Токен показывается один раз. Храните его в переменных окружения клиента, не в репозитории.

## 2. Переменные окружения

| Переменная | Обязательна | Смысл |
|---|---|---|
| `REGRU_PANEL_URL` | да | база панели, например `https://example.com/regru` |
| `REGRU_API_TOKEN` | да | Bearer-токен |
| `REGRU_MCP_ALLOW_WRITE` | нет | `1` включает изменяющие инструменты (по умолчанию выключены) |
| `REGRU_MCP_LOCALE` | нет | язык ответов API: `en` (по умолчанию), `ru`, `zh`, `hi`, `de`, `es` |
| `REGRU_MCP_TIMEOUT` | нет | таймаут HTTP, секунды (по умолчанию 30) |
| `REGRU_MCP_CAINFO` | нет | путь к CA-бандлу, если PHP не находит системный |
| `REGRU_MCP_INSECURE` | нет | `1` отключает проверку TLS — только для доверенной LAN |

### Токен из файла

Некоторые MCP-клиенты (включая DeepSeek Harness) вычищают из окружения дочернего процесса переменные с `TOKEN`, `KEY`, `SECRET`, `PASSWORD`. Тогда токен передаётся файлом:

```bash
php mcp/server.php --token-file /path/to/token.txt
```

Файл должен быть доступен только владельцу (`0600`). `--token-file` имеет приоритет над `REGRU_API_TOKEN`; сам токен не попадает ни в аргументы процесса, ни в логи.

### Регистрация в DeepSeek Harness

Слой патчей `%USERPROFILE%\.dsh\profiles\desktop\cordis.patch.yml`:

```yaml
- insert:
    - id: mcp-regru-control
      name: '@deepseek-ai/dsh-mcp-client'
      config:
        serverName: regru
        transport: stdio
        command: 'C:\tools\php85\php.exe'
        args:
          - 'C:\AutoClaw\AMD\REG.RU\GIT\mcp\server.php'
          - '--token-file'
          - 'C:\AutoClaw\AMD\REG.RU\.recon\agent-token.txt'
        env:
          REGRU_PANEL_URL: 'https://example.com/regru'
          REGRU_MCP_ALLOW_WRITE: '0'
          REGRU_MCP_LOCALE: 'ru'
        cwd: 'C:\AutoClaw\AMD\REG.RU\GIT'
        toolCallTimeoutMs: 120000
        failOnStartupError: false
```

После правки конфига HARNESS нужно перезапустить; инструменты появятся как `mcp__regru__regru_inventory`, `mcp__regru__regru_dns_records` и т. д.

Проверка без сети:

```bash
php mcp/server.php --self-test
# OK: 13 tools, protocol 2024-11-05
```

## 3. Размещение на сервере

MCP-сервер не выкладывается в веб-корень: он запускается по stdio от имени пользователя сайта. Пример (каталог вне docroot):

```bash
mkdir -p /var/www/<site_user>/data/regru-mcp
chown <site_user>:<site_user> /var/www/<site_user>/data/regru-mcp
cp mcp/server.php /var/www/<site_user>/data/regru-mcp/
# mcp.env (0600): REGRU_PANEL_URL, REGRU_API_TOKEN, REGRU_MCP_ALLOW_WRITE=0
```

Обёртка `/usr/local/bin/regru-mcp`:

```sh
#!/bin/sh
set -a
. /var/www/<site_user>/data/regru-mcp/mcp.env
set +a
exec php /var/www/<site_user>/data/regru-mcp/server.php "$@"
```

Проверка: `regru-mcp --self-test`; MCP-клиент запускает `/usr/local/bin/regru-mcp` без аргументов. При обновлении панели копируйте `mcp/server.php` заново.

## 4. Подключение клиента

### Claude Desktop / Claude Code

`claude_desktop_config.json` (или `.mcp.json` в проекте):

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

### DSH / любой MCP-клиент

```json
{
  "mcp": {
    "regru-control": {
      "command": "php",
      "args": ["C:/path/to/regru-control/mcp/server.php"],
      "env": {
        "REGRU_PANEL_URL": "https://example.com/regru",
        "REGRU_API_TOKEN": "rg_REPLACE_ME"
      }
    }
  }
}
```

Windows: укажите полный путь к `php.exe` в `command`. Шаблон — [`.mcp.json.example`](../.mcp.json.example).

### Ручная проверка протокола

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | REGRU_PANEL_URL=https://example.com/regru REGRU_API_TOKEN=rg_... php mcp/server.php
```

## 5. Инструменты

| Инструмент | Изменяет | Право | Назначение |
|---|---|---|---|
| `regru_inventory` | нет | `read` | инвентарь без секретов: счётчики, домены, хостинги, доступы, баланс |
| `regru_domains` | нет | `read` | список доменов с фильтрами |
| `regru_domain_get` | нет | `read` | один домен |
| `regru_dns_records` | нет | `read` | DNS-записи и SOA |
| `regru_dns_add_record` | да | `dns:write` | создать запись |
| `regru_dns_delete_record` | да | `dns:write` | удалить запись |
| `regru_hostings` | нет | `read` | хостинги, VPS, серверы; без паролей |
| `regru_credentials` | нет | `read` | метаданные хранилища |
| `regru_credential_reveal` | нет¹ | `secrets:read` | расшифровать пароль (аудит) |
| `regru_billing` | нет | `read` | баланс и неоплаченные счета |
| `regru_sync` | да | `write` | синхронизация инвентаря |
| `regru_domain_renew` | да | `write` | продление (списывает деньги) |
| `regru_raw_call` | да² | `regru:raw` | любая команда REG.RU API2 |

¹ чтение секрета не меняет данные, но фиксируется в журнале.
² без `REGRU_MCP_ALLOW_WRITE=1` разрешены только команды `*/get_*`.

Инструменты помечены `annotations.readOnlyHint`/`destructiveHint` — клиент может показать предупреждение.

## 6. Безопасность

- По умолчанию сервер **только читает**. Изменения — осознанное включение `REGRU_MCP_ALLOW_WRITE=1`.
- `regru_domain_renew` и `regru_raw_call` могут списывать деньги: выдавайте `write`/`regru:raw` только доверенному агенту.
- Токен передаётся только в заголовке `Authorization`; сервер не логирует его и не принимает токен аргументом инструмента.
- Все действия агента видны в журнале панели (`actor` = токен), раскрытия паролей — отдельные события.
- Отзыв: `regru token:revoke --id=<id>` или UI «API REG.RU → Токены».
- HTTPS обязателен; для самоподписанного сертификата в LAN — `REGRU_MCP_CAINFO` (предпочтительно) или `REGRU_MCP_INSECURE=1`.

## 7. Ограничения и диагностика

| Симптом | Причина / решение |
|---|---|
| `REGRU_PANEL_URL and REGRU_API_TOKEN must be set` | не заданы переменные окружения клиента |
| `Authentication required (UNAUTHORIZED)` | токен отозван/истёк или неверный URL |
| `Insufficient permissions (FORBIDDEN)` | у токена нет нужного scope |
| `Network error: SSL certificate problem` | задайте `REGRU_MCP_CAINFO` или системный CA |
| `This tool changes data…` | включите `REGRU_MCP_ALLOW_WRITE=1` |
| `Raw write commands are disabled…` | для произвольной команды нужно `REGRU_MCP_ALLOW_WRITE=1` |
| `429` | лимит панели/REG.RU, повторите позже |

Логи панели: `var/log/app.log`; диагностика: `php backend/bin/regru doctor --online`.
