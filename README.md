# REG.RU Control

Самодостаточная панель управления доменами, DNS-зонами и хостингами REG.RU: красивый веб-интерфейс для человека и полноценный JSON API для интеграций и AI-агентов.

Переписана с нуля на **PHP 8.5** (исходный проект `daloman/regru-api-go` покрывал три команды DNS-зоны и не обрабатывал ошибки). Здесь — весь документированный REG.RU API v2: 116 команд, типизированные обёртки для основных сценариев и «raw»-консоль для остальных.

> Проект не связан с REG.RU. «REG.RU» — товарный знак соответствующего правообладателя.

## Возможности

**Инвентарь**
- 90+ услуг REG.RU в одной таблице: домены, хостинги, VPS, выделенные серверы, лицензии, парковки.
- Автоопределение делегирования: домены на `ns1.reg.ru`/`ns2.reg.ru` получают управляемую DNS-зону, домены на NS хостинга помечаются отдельно.
- Автопродление, даты оплаты, остаток дней, статусы, ID услуг.
- Синхронизация по расписанию (cron) и вручную; история запусков с ошибками по этапам.

**Домены и DNS**
- Продление, включение/выключение автопродления, смена DNS-серверов, снятие с делегирования.
- Полноценный редактор DNS: A, AAAA, CNAME, MX, TXT, NS, SRV, CAA; SOA/TTL; атомарное «удалить + добавить» для изменения записи; DNSSEC.
- Экспорт списка доменов в CSV.

**Хостинги и доступы**
- IP-адреса, ссылки на панели, логины из `service/get_details`.
- Шифрованное хранилище паролей (FTP, SSH, БД, почта, панели) с генератором паролей.
- Раскрытие пароля — только по явному запросу, с записью в журнал (кто, когда, с какого IP).

**Аккаунт и эксплуатация**
- Баланс, неоплаченные счета, история счетов за период.
- API-токены со scope-ограничениями (`read`, `write`, `dns:write`, `secrets:read`, `admin`, `regru:raw`).
- Журнал действий, диагностика (`doctor`), резервные копии SQLite, экспорт инвентаря.

**Интерфейс**
- Шесть языков: английский (по умолчанию), русский, китайский, хинди, немецкий, испанский.
- Автоопределение языка по `Accept-Language`/браузеру, сохранение выбора, переключатель в шапке и на экране входа.
- Тёмная и светлая темы, адаптив до 390 px, строгий CSP без CDN.

**API для агента**
- `GET /api/v1/inventory` — компактный инвентарь без секретов.
- `POST /api/v1/credentials/{id}/reveal` — пароль из хранилища (scope `secrets:read`).
- `POST /api/v1/regru/call` — любая из 116 команд REG.RU API v2 (scope `regru:raw`).
- OpenAPI-спецификация: `/api/v1/openapi.yaml`.
- **MCP-сервер** (`mcp/server.php`): 13 инструментов для Claude/DSH/Cursor — инвентарь, DNS, хостинги, доступы, биллинг; read-only по умолчанию. Настройка — [`docs/MCP.md`](docs/MCP.md).

## Стек и принципы

| Слой | Решение |
|---|---|
| Язык | PHP 8.5: `readonly`-классы, enums, property hooks, атрибуты, `#[\Override]` |
| Зависимости | Ноль Composer-пакетов: собственный PSR-4 автозагрузчик, свой роутер и DI-контейнер |
| БД | SQLite (PDO), WAL, миграции в SQL |
| Шифрование | libsodium `secretbox`, ключ из `APP_KEY`, привязка к контексту записи |
| Фронтенд | ES-модули без сборки, строгий CSP, без CDN; i18n — JSON-каталоги en/ru/zh/hi/de/es |
| Стиль | тёмная тема в духе GitHub, светлая тема, адаптив до 390 px |

Код разделён на независимые слои: `backend/` (API, домен, данные) и `frontend/` (SPA). Бэкенд полностью самодостаточен — все функции доступны через HTTP API без интерфейса.

```
backend/src/
  Kernel/       ядро: App, Router, Request/Response, Config, Container, Logger
  Http/         контроллеры, middleware, presenter
  RegRu/        клиент API2, контракты, endpoint-обёртки, каталог команд
  Model/        readonly-модели и enum'ы
  Repository/   доступ к SQLite
  Service/      бизнес-логика: sync, dns, hostings, vault, billing, tokens
  Security/     crypto, csrf, сессии, пароли, токены
  Cli/          консольные команды
  Database/     PDO-обёртка и мигратор
frontend/
  index.html    оболочка SPA
  assets/js/    app, router, api, dom, страницы
  assets/css/   дизайн-система
```

## Быстрый старт

Требования: PHP 8.5 (cli + fpm) с расширениями `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`, `sodium`.

```bash
cp .env.example .env
php backend/bin/regru key:generate
php backend/bin/regru migrate
php backend/bin/regru user:create --login=admin        # пароль будет сгенерирован и показан
php backend/bin/regru account:set --login=ВАШ_ЛОГИН    # пароль API REG.RU спросит из stdin
php backend/bin/regru sync --scope=all
php backend/bin/regru serve --port=8099                # http://127.0.0.1:8099
```

Для локальной разработки удобнее собрать зеркало продакшена:

```bash
php tools/build.php --target=../deploy --dev
php -S 127.0.0.1:8099 -t ../deploy ../deploy/index.php
```

## Конфигурация

Все параметры читаются из `.env` (реальные переменные окружения имеют приоритет).

| Переменная | Назначение | По умолчанию |
|---|---|---|
| `APP_ENV` | `production` / `development` / `test` | `production` |
| `APP_DEBUG` | подробные ошибки (только для разработки) | `0` |
| `APP_URL` | публичный адрес панели | — |
| `APP_KEY` | base64:32 байта, ключ шифрования | генерируется |
| `DB_PATH` | путь к SQLite | `var/regru.sqlite` |
| `LOG_PATH`, `LOG_LEVEL` | журнал приложения | `var/log/app.log`, `info` |
| `SESSION_SECURE`, `SESSION_IDLE` | cookie и таймаут сессии | `1`, `1800` |
| `REGRU_TIMEOUT`, `REGRU_RETRIES` | сетевые параметры | `20`, `3` |
| `REGRU_RATE_LIMIT_PER_HOUR` | локальный предохранитель (лимит REG.RU — 1200/час) | `1100` |
| `SYNC_TTL` | когда данные считаются устаревшими | `3600` |

## CLI

```text
regru key:generate          сгенерировать APP_KEY
regru migrate               применить миграции
regru user:create           создать пользователя панели
regru user:passwd           сменить пароль
regru account:set           сохранить доступы REG.RU API (пароль — через stdin)
regru account:test          проверить доступ к API
regru doctor [--online]     диагностика окружения
regru sync [--scope=all|services|details|dns] [--json]
regru dns:list|dns:add|dns:rm
regru api:call --command=... --data='{...}'
regru token:create|token:list|token:revoke
regru backup                VACUUM INTO + ротация 14 копий
regru routes                список HTTP-маршрутов
regru serve                 локальный сервер
regru test                  тесты
```

Секреты можно передавать через stdin (`--password-stdin`), чтобы они не попадали в `ps` и историю shell.

## API

Аутентификация — Bearer-токен (интеграции, агент) или cookie-сессия (SPA, требует `X-CSRF-Token`). Краткая инструкция для агентов и администраторов — [`AGENTS.md`](AGENTS.md).

```bash
curl -s -H "Authorization: Bearer rg_..." \
  "https://example.com/regru/api/v1/inventory" | jq

curl -s -X POST -H "Authorization: Bearer rg_..." \
  -H "Content-Type: application/json" \
  -d '{"command":"user/get_balance","input":{}}' \
  "https://example.com/regru/api/v1/regru/call" | jq
```

Формат: `{"data": ..., "meta": {...}}`; ошибки — `{"error": {"code", "message", "details"}, "request_id"}`. Полное описание — [`docs/API.md`](docs/API.md), [`docs/openapi.yaml`](docs/openapi.yaml).

## Языки

Локаль определяется по цепочке: `?lang=` → cookie `rg_lang` → `Accept-Language` → английский. Переключатель — в шапке и на странице входа; выбор сохраняется в cookie и localStorage. API отвечает на языке `Accept-Language` (ошибки, валидация).

## Безопасность

- Пароли и токены REG.RU шифруются libsodium и никогда не возвращаются списком.
- Раскрытие секрета — отдельное право (`secrets:read`) и событие журнала.
- Argon2id для паролей панели, CSRF для cookie-запросов, rate limit на вход и API.
- Строгий CSP без inline-скриптов, `X-Content-Type-Options`, `X-Frame-Options: DENY`, HSTS на уровне nginx.
- Панель рассчитана на приватный доступ: закройте её списком сетей в nginx (см. `docs/DEPLOY.md`).

Подробности и модель угроз: [`SECURITY.md`](SECURITY.md).

## Тесты и качество

```bash
php tools/lint.php                 # синтаксис + strict_types
php tools/lint.php --text-files    # BOM/CRLF-гигиена
php backend/tests/run.php          # 35 тестов без внешних зависимостей
php tools/secret-scan.php --repo=.
```

Тесты не ходят в сеть: endpoint-клиенты REG.RU подменяются контрактами-заглушками, база — in-memory SQLite.

## Документация

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — устройство и точки расширения
- [`docs/DEPLOY.md`](docs/DEPLOY.md) — продакшн-развёртывание, nginx, cron, бэкапы
- [`docs/API.md`](docs/API.md) — эндпоинты, scopes, примеры
- [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md) — сценарии работы в интерфейсе
- [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) — локальная среда, тесты, стиль
- [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) — типовые ошибки API и окружения

## Лицензия

MIT — см. [`LICENSE`](LICENSE).
