# Разработка

## Требования

- PHP 8.5 CLI (локально достаточно расширений `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`, `sodium`);
- Python не нужен; Node нужен только для проверки синтаксиса JS (`node --check`, опционально);
- Composer **не требуется**: приложение использует собственный PSR-4 автозагрузчик.

## Локальный запуск

```bash
cp .env.example .env
php backend/bin/regru key:generate
php backend/bin/regru migrate
php backend/bin/regru user:create --login=admin --password='Local-Dev-Passw0rd!'
php backend/bin/regru serve --port=8099
```

Либо зеркало продакшена (то же, что уезжает на сервер):

```bash
php tools/build.php --target=../deploy --dev
php -S 127.0.0.1:8099 -t ../deploy ../deploy/index.php
```

`--dev` включает `APP_DEBUG`, отключает secure-cookie и ставит `APP_URL=http://127.0.0.1:8099`.

## Структура

```
backend/src/Kernel       ядро HTTP и контейнер
backend/src/Http         контроллеры, middleware, presenter, AuthContext
backend/src/RegRu        клиент API2, контракты, endpoint-обёртки, каталог
backend/src/Model        readonly-модели, enums
backend/src/Repository   SQLite-доступ
backend/src/Service      бизнес-логика
backend/src/Security     crypto, csrf, сессия, пароли, токены
backend/src/Cli          консоль
backend/tests            раннер, TestKernel, фейки, фикстуры
backend/migrations       SQL-миграции
frontend/assets/js       SPA: app, router, api, dom, pages/*
frontend/assets/css      дизайн-система
tools                    lint, build, deploy, secret-scan
```

## Стиль PHP

- `declare(strict_types=1)` в каждом файле; `final`/`readonly` по умолчанию.
- PSR-12, 4 пробела, LF, UTF-8 без BOM, финальный перевод строки.
- Типы у свойств, параметров и возвращаемых значений; `array{...}`/`list<...>` в PHPDoc для сложных форм.
- Никаких глобальных функций: только классы и enum'ы. Исключение — тестовые хелперы в `backend/tests/run.php`.
- Ошибки для клиента — `HttpException`/`ValidationException`; внутренние — обычные исключения.
- Секреты и пароли — `#[\SensitiveParameter]` и только через `Crypto`.

## Стиль JS

- ES-модули, без сборки и внешних зависимостей.
- Разметка — `h(tag, props, ...children)`: строки становятся текстовыми узлами. `innerHTML` допустим только для статических иконок (`icons.js`).
- Данные — только через `api()`/`apiRaw()`; ошибки — `ApiError` с `status`, `code`, `fieldErrors`.
- Страница — модуль с `export function render(root, ctx)`, где `ctx = {params, query, navigate, runSync, isAdmin}`.
- Никаких inline-стилей, кроме динамических размеров; стили — классы из `app.css`.

## Тесты

```bash
php tools/lint.php
php backend/tests/run.php
php tools/secret-scan.php --repo=.
```

- `TestKernel::boot()` собирает контейнер на in-memory SQLite и подменяет REG.RU API фейками (`backend/tests/Fakes`).
- HTTP-тесты идут через настоящий `App`, роутер, middleware и сессии (`ArraySessionStorage`).
- Новый сценарий: добавьте `test('…', static function (): void { … })` в `backend/tests/run.php`.

Проверка JS без Node-проекта:

```powershell
Get-ChildItem -Recurse frontend/assets/js -Filter *.js | ForEach-Object {
  Copy-Item $_.FullName "$env:TEMP\$($_.BaseName).mjs"
  node --check "$env:TEMP\$($_.BaseName).mjs"
}
```

## Как добавить функцию

1. **Модель/таблица** — миграция `backend/migrations/NNNN_*.sql` и метод репозитория.
2. **REG.RU** — метод в `RegRu\Endpoint\*Api` (и контракте, если нужен фейк).
3. **Сервис** — бизнес-логика + аудит; валидация входа в контроллере.
4. **HTTP** — метод контроллера с `#[Route(...)]`, при необходимости scope.
5. **UI** — страница/кнопка, вызов API, обработка ошибок.
6. **Тест** — unit и/или HTTP-сценарий.
7. **Документация** — `docs/API.md`, `docs/USER-GUIDE.md`, `docs/openapi.yaml`.

## Коммиты

Формат: `<область>: <краткое описание>` (например, `dns: атомарное изменение записи`). В одном коммите — одна логическая правка. Перед коммитом: `lint`, `tests`, `secret-scan`.

## Релиз

1. Обновите `app.version` в `backend/config/defaults.php` и `CHANGELOG.md`.
2. Прогоните тесты и проверку на локальном зеркале.
3. `pwsh -NoProfile -File tools/deploy.ps1` и бета-проход по страницам.
4. Тег `vX.Y.Z`.
