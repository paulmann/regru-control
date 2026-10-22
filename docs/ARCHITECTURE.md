# Архитектура

## Цели

1. **Один процесс без зависимостей.** Панель ставится на обычный PHP-FPM-хостинг: скопировали каталог, создали `.env`, применили миграции. Никаких Composer-пакетов, Node-сборки и внешних CDN.
2. **Бэкенд самодостаточен.** Любая функция доступна через HTTP API; SPA — лишь один из клиентов.
3. **Расширяемость.** Новый эндпоинт — атрибут на методе контроллера; новая команда REG.RU — метод endpoint-класса или вызов через raw-API; новая страница — модуль в `frontend/assets/js/pages`.
4. **Секреты по умолчанию закрыты.** Пароли шифруются, списки их не содержат, раскрытие — отдельное право и запись в журнал.

## Слои

```
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
       ├─ ErrorHandler (HttpException → problem+json, остальное → 500)
       └─ SecurityHeaders (CSP, HSTS, no-store)
```

### Ядро

| Класс | Роль |
|---|---|
| `Kernel\App` | регистрирует контроллеры, прогоняет middleware, применяет заголовки, ставит `X-Request-Id` |
| `Kernel\Router` | компилирует `{param}`-шаблоны, различает 404/405, внедряет `Request` и скалярные параметры |
| `Kernel\Container` | autowiring по типам; интерфейсы REG.RU API связываются с реализациями в `backend/bootstrap.php` |
| `Kernel\Request` | иммутабельный запрос; поддерживает монтирование в подкаталог (`/regru/…`) |
| `Kernel\Config` | плоские ключи (`regru.timeout`), типизация, `with()` для тестов |
| `Kernel\Logger` | JSON-строки, редакция секретов, ротация по размеру |
| `Kernel\RequestContext` | request id, актор, IP и user-agent — для логов и аудита |

### Данные

SQLite, WAL, `foreign_keys=ON`. Миграции — `backend/migrations/NNNN_*.sql`, применяются `Migrator` в транзакции и записываются в `schema_migrations`.

| Таблица | Содержимое |
|---|---|
| `users` | пользователи панели (Argon2id) |
| `regru_account` | логин + **зашифрованный** пароль API REG.RU |
| `services` | снимок `service/get_list` (+ `raw`) |
| `domains` | домены, NS, `dns_managed`, автопродление |
| `dns_records` | кэш зон (обновляется после каждой мутации) |
| `hostings` | IP, панель, план, **зашифрованный** пароль панели (если API отдал) |
| `credentials` | хранилище паролей, `password_enc` |
| `api_tokens` | SHA-256 хеши токенов, scopes, срок, отзыв |
| `audit_log` | журнал действий |
| `sync_runs` | история синхронизаций и статистика |
| `settings` | TTL синхронизации, кэш баланса, SOA-кэш |
| `rate_limits` | окна лимитов (вход, API, исходящие запросы REG.RU) |

Даты хранятся строками UTC ISO-8601; сравнение — через `julianday()`.

### Клиент REG.RU API2

- `RegRu\RegRuClient` — единственное место, знающее транспорт: POST `application/x-www-form-urlencoded`, `input_data` = JSON, `input_format=json`.
- Повторяет только транспортные ошибки (DNS/TLS/5xx) с экспоненциальной задержкой. Бизнес-ошибки не повторяются: повторное `service/renew` списало бы деньги дважды.
- Локальный предохранитель на 1100 запросов/час (официальный лимит — 1200) хранится в `rate_limits`, поэтому cron-синхронизация не может заблокировать аккаунт.
- Контракты `RegRu\Contract\*` позволяют подменять API в тестах без сети.
- `RegRu\RegRuCommandCatalog` читает сгенерированный из официальной документации каталог 116 команд — для страницы «API REG.RU» и проверки имён в raw-вызовах.

### Синхронизация

`Service\SyncService` выполняет этапы независимо:

1. `services` — `service/get_list`, затем `domain/get_nss` (одним списком) и `service/get_info` для `autorenew_flag`.
2. `details` — `service/get_details` только для услуг, у которых ещё не было попытки; пустой ответ тоже считается ответом и запоминается.
3. `dns` — `zone/get_resource_records` пакетами по 10 доменов для зон на NS REG.RU.

Ошибка этапа не отменяет остальные: запуск получает статус `partial`, ошибки сохраняются в `sync_runs.stats/error` и видны в интерфейсе.

## Локализация

- Поддерживаются `en` (по умолчанию), `ru`, `zh`, `hi`, `de`, `es`.
- Сервер: `LocaleNegotiator` (`?lang=` → cookie `rg_lang` → `Accept-Language` → `en`), `Translator` с JSON-каталогами `backend/resources/locales/*.json`, фасад `I18n` для исключений и валидатора. Ответы получают `Content-Language` и `Vary: Accept-Language, Cookie`.
- Фронтенд: русская строка — message id (gettext-style), каталоги `frontend/assets/js/locales/*.json`. Слой `i18n.js` переводит статический DOM и динамически добавленные узлы через `MutationObserver`, поэтому существующие шаблоны не требуют ручной обёртки ключами. Динамические строки сопоставляются по шаблонам с `{p}`.
- `php tools/i18n-extract.php` обновляет каталог сообщений, `php tools/i18n-check.php` проверяет полноту и плейсхолдеры.

## Как расширять

### Новый HTTP-эндпоинт

```php
#[Route('GET', '/api/v1/domains/{name}/dnssec', [AuthMiddleware::class])]
public function dnssecStatus(Request $request, string $name): Response
{
    AuthContext::fromRequest($request)->require(TokenScope::Read);

    return ApiPresenter::data($this->dnssec->status($name));
}
```

Контроллер добавляется в `backend/config/controllers.php`. Валидация — через `Support\Validator`, ошибки — `ValidationException` (422 + карта полей).

### Новая команда REG.RU

1. Типизированный сценарий: метод в `RegRu\Endpoint\*Api` + метод контракта, затем сервис.
2. Разовый вызов: `POST /api/v1/regru/call` с `{"command":"…","input":{…}}` (scope `regru:raw`) — работает для всех команд из каталога без изменения кода.

### Новая страница SPA

Добавьте модуль `export function render(root, ctx)` в `frontend/assets/js/pages/`, зарегистрируйте маршрут в `app.js` и пункт меню в `NAV`. Все данные — через `api()`/`apiRaw()`, разметка — через `h()` (строки становятся текстовыми узлами, XSS через данные невозможен).

## Принятые решения

| Решение | Почему |
|---|---|
| Без фреймворка и Composer-пакетов | нулевая стоимость эксплуатации, отсутствие цепочки поставок, предсказуемый деплой |
| SQLite | один администратор, редкая запись, простые бэкапы (`VACUUM INTO`), нет отдельного сервера БД |
| Атрибуты вместо массива маршрутов | маршрут рядом с обработчиком, меньше рассинхронизации |
| SPA на ES-модулях без сборки | правки применяются без пересборки; строгий CSP без inline-кода |
| Кэш DNS-записей | REG.RU не отдаёт id записей; после каждой мутации кэш перечитывается, интерфейс показывает фактическое состояние реестра |
| `dns_managed` | зоной можно управлять через API только на `ns1.reg.ru`/`ns2.reg.ru`; домены на NS хостинга честно помечаются |
