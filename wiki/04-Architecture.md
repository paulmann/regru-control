# 04 · Архитектура

## Цели

1. Один процесс без зависимостей: PHP-FPM + SQLite, ноль Composer-пакетов, Node-сборки и внешних CDN.
2. Бэкенд самодостаточен: любая функция доступна через HTTP API, SPA — один из клиентов.
3. Расширяемость: новый эндпоинт — атрибут на методе контроллера; новая команда REG.RU — метод endpoint-класса или raw-вызов.
4. Секреты закрыты по умолчанию.

## Слои

```text
HTTP (public/index.php)
  └─ Kernel\App
       ├─ Pipeline: CsrfMiddleware → ApiRateLimitMiddleware
       ├─ Router (атрибуты #[Route])
       │    └─ Middleware маршрута: AuthMiddleware (+ OptionalAuth)
       ├─ Controller  (валидация входа, права, вызов сервиса)
       │    └─ Service (бизнес-логика, аудит)
       │         ├─ Repository (SQLite)
       │         └─ RegRu\Contract\* (HTTP REG.RU API v2)
       ├─ ApiPresenter (единый JSON-конверт)
       ├─ ErrorHandler (HttpException → JSON, остальное → 500)
       └─ SecurityHeaders (CSP, HSTS, no-store)
```

## Ядро

| Класс | Роль |
|---|---|
| `Kernel\App` | регистрация контроллеров, middleware, заголовки, `X-Request-Id` |
| `Kernel\Router` | `{param}`-шаблоны, 404/405, внедрение `Request` и скаляров |
| `Kernel\Container` | autowiring по типам; контракты API связываются в `backend/bootstrap.php` |
| `Kernel\Request` | иммутабельный запрос, поддержка подкаталога |
| `Kernel\Config` | плоские ключи (`regru.timeout`), типизация, `with()` для тестов |
| `Kernel\Logger` | JSON-строки, редакция секретов, ротация |
| `Kernel\RequestContext` | request id, актор, IP, user-agent — для логов и аудита |
| `Kernel\I18n` / `Translator` | локаль запроса и сообщения |

## Данные

SQLite, WAL, `foreign_keys=ON`; миграции в `backend/migrations/NNNN_*.sql`.

| Таблица | Содержимое |
|---|---|
| `users` | пользователи панели (Argon2id) |
| `regru_account` | логин + зашифрованный пароль API |
| `services` | снимок `service/get_list` |
| `domains` | домены, NS, `dns_managed`, автопродление |
| `dns_records` | кэш зон |
| `hostings` | IP, панель, план, зашифрованный пароль (если API отдал) |
| `credentials` | хранилище паролей |
| `api_tokens` | SHA-256 хеши, scopes, срок, отзыв |
| `audit_log` | журнал действий |
| `sync_runs` | история синхронизаций |
| `settings` | TTL синхронизации, кэш баланса, SOA |
| `rate_limits` | окна лимитов |

## Клиент REG.RU API2

- `RegRu\RegRuClient` — единственное место, знающее транспорт: POST `application/x-www-form-urlencoded`, `input_data` = JSON, `input_format=json`.
- Повторяются только транспортные ошибки (DNS/TLS/5xx). Бизнес-ошибки не повторяются: повторное `service/renew` списало бы деньги дважды.
- Локальный предохранитель 1100 запросов/час (официальный лимит 1200).
- Контракты `RegRu\Contract\*` позволяют подменять API в тестах без сети.
- `RegRuCommandCatalog` — каталог 116 команд из официальной документации.

## Синхронизация

`Service\SyncService` выполняет этапы независимо:

1. `services` — `service/get_list`, затем `domain/get_nss` и `service/get_info` для `autorenew_flag`.
2. `details` — `service/get_details` только для услуг без попытки; пустой ответ тоже запоминается.
3. `dns` — `zone/get_resource_records` пакетами по 10 доменов для зон на NS REG.RU.

Ошибка этапа не отменяет остальные: статус `partial`, ошибки в `sync_runs.stats/error`.

## Локализация

- `en` по умолчанию, `ru`, `zh`, `hi`, `de`, `es`.
- Сервер: `LocaleNegotiator` (`?lang=` → cookie → `Accept-Language` → `en`), `Translator`, `I18n`; ответы получают `Content-Language` и `Vary`.
- Фронтенд: русская строка — message id, каталоги `frontend/assets/js/locales/*.json`, перевод динамического DOM через `MutationObserver`.

## Принятые решения

| Решение | Почему |
|---|---|
| Без фреймворка и Composer | нулевая стоимость эксплуатации и цепочки поставок |
| SQLite | один администратор, редкая запись, простые бэкапы |
| Атрибуты вместо массива маршрутов | маршрут рядом с обработчиком |
| SPA без сборки | правки без пересборки, строгий CSP |
| Кэш DNS-записей | REG.RU не отдаёт id записей; после мутации кэш перечитывается |
| `dns_managed` | зоной можно управлять только на `ns1.reg.ru`/`ns2.reg.ru` |

Дальше: [API](05-API) · [Разработка](10-Development-and-Testing)
