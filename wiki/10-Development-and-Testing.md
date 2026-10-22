# 10 · Разработка и тестирование

## Принципы

- PHP 8.5, `strict_types`, `final`/`readonly` по умолчанию.
- Ноль Composer/Node-зависимостей: собственный автозагрузчик, роутер, контейнер, SPA без сборки.
- Бэкенд самодостаточен: новая функция сначала появляется в API, потом в интерфейсе.
- Секреты — только через `Security\Crypto`, в логи не попадают.

## Структура

```text
backend/src/Kernel       ядро HTTP, контейнер, конфиг, лог, i18n
backend/src/Http         контроллеры, middleware, presenter
backend/src/RegRu        клиент API2, контракты, endpoint-обёртки
backend/src/Model        readonly-модели, enums
backend/src/Repository   SQLite
backend/src/Service      бизнес-логика
backend/src/Security     crypto, csrf, сессии, пароли, токены
backend/src/Cli          консоль
frontend/assets/js       SPA: app, router, api, dom, i18n, pages
mcp/server.php           MCP-сервер (stdio)
```

## Локальная среда

```bash
cp .env.example .env
php backend/bin/regru key:generate
php backend/bin/regru migrate
php backend/bin/regru user:create --login=admin --password='Local-Dev-Passw0rd!'
php backend/bin/regru serve
```

Зеркало продакшена: `php tools/build.php --target=../deploy --dev`.

## Добавить эндпоинт

```php
#[Route('POST', '/api/v1/example', [AuthMiddleware::class])]
public function example(Request $request): Response
{
    AuthContext::fromRequest($request)->require(TokenScope::Write);

    $v = new Validator($request->body());
    $name = $v->requiredString('name', 'Название', 1, 100);
    $v->throwIfInvalid();

    return ApiPresenter::created(['name' => $name]);
}
```

Контроллер регистрируется в `backend/config/controllers.php`. Валидация — `Support\Validator`, ошибки — `ValidationException` (422 + карта полей).

## Добавить команду REG.RU

- Типизированно: метод в `RegRu\Endpoint\*Api` + контракт, затем сервис.
- Быстро: `POST /api/v1/regru/call` (scope `regru:raw`) — для любой из 116 команд каталога.

## Добавить страницу SPA

Модуль `export function render(root, ctx)` в `frontend/assets/js/pages/`, маршрут в `app.js`, пункт меню в `NAV`. Данные — через `api()`, разметка — через `h()` (данные становятся текстовыми узлами, XSS через них невозможен).

## Тесты и проверки

```bash
php tools/lint.php --text-files   # синтаксис + BOM/CRLF
php backend/tests/run.php         # 38 тестов без сети
php tools/i18n-check.php          # переводы и плейсхолдеры
php tools/secret-scan.php --repo=.
php mcp/server.php --self-test
```

Тесты используют in-memory SQLite и фейковые REG.RU-эндпоинты, сеть не нужна.

## Требования к PR

Один PR — одна логическая правка; тест на новое поведение; обновление документации (`docs/API.md`, `docs/USER-GUIDE.md`); никаких реальных доменов/IP/паролей в коде и тестах — используйте `example.com` и `203.0.113.0/24`.

Новые строки интерфейса: русская строка — message id; после правок `php tools/i18n-extract.php`, переводы в `frontend/assets/js/locales/*.json`, затем `php tools/i18n-check.php`.

Дальше: [FAQ](11-FAQ) · [Архитектура](04-Architecture)
