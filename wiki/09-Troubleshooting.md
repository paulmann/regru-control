# 09 · Устранение неполадок

## REG.RU API

### `ACCESS_DENIED_FROM_IP`

IP сервера не разрешён в настройках API REG.RU. Добавьте адрес сервера в разделе «Настройки API» личного кабинета. Проверьте, что запросы идут именно с этого IP:

```bash
curl -s https://api.ipify.org; echo
```

### `PARAMETER_MISSING: domain_name or service_id required`

Некоторые команды не принимают список `services`/`domains` в JSON и требуют верхнеуровневый `domain_name` или `service_id`. В raw-вызове передайте параметр напрямую, например `{"service_id":"123456"}`. Типизированные обёртки делают это правильно.

### `service/get_details` возвращает пустой `services`

Для части услуг (ISPmanager-хостинги, VPS, выделенные серверы) REG.RU не отдаёт панельные логины и пароли. Это ответ API, а не ошибка панели. Сохраните пароль вручную в «Доступах» — он будет зашифрован и доступен агенту по API с правом `secrets:read`.

### `DOMAIN_IS_NOT_USE_REGRU_NSS`

Домен делегирован не на `ns1.reg.ru`/`ns2.reg.ru`, поэтому управлять зоной через API нельзя. Домены на `ns5/ns6.hosting.reg.ru` и `ns1/ns2.shop.reg.ru` ведутся в ISPmanager. Смените NS в карточке домена, если хотите управлять зоной через REG.RU.

### `NO_SUCH_COMMAND`

Команда недоступна типу аккаунта (например, партнёрские функции у обычного клиента). Проверьте каталог команд на странице «API REG.RU».

### `INSUFFICIENT_FUNDS` / `NOT_ENOUGH_MONEY`

Недостаточно средств. Пополните баланс REG.RU; продление создаст счёт.

### `IP_EXCEEDED_ALLOWED_CONNECTION_RATE`

Превышен лимит 1200 запросов/час. Панель останавливается на 1100, но параллельные скрипты могли израсходовать лимит. Подождите до конца часа; проверьте cron и сторонние интеграции.

### Ответ 502 `REGRU_API_ERROR`

Смотрите `details.api_code`/`api_text` в ответе и `var/log/app.log` (журнал без секретов, с `request_id`).

## Панель

### 403 `CSRF_FAILED`

Истёк или отсутствует CSRF-токен. Обновите страницу. Если повторяется: проверьте, что cookie `rg_session` не блокируется и панель открыта по тому же протоколу, что в `APP_URL` (при `SESSION_SECURE=1` на http cookie не установится).

### Пустая страница, «Загрузка панели…» не исчезает

1. 404 на `assets/js/app.js` — не выполнен `tools/build.php` или nginx не отдаёт статику.
2. CSP-ошибки — не должно быть inline-скриптов; проверьте `index.html`.
3. Ошибка `bootstrap` — смотрите `var/log/app.log` и ответ `/api/v1/bootstrap`.

### 500 «Внутренняя ошибка сервера»

`APP_DEBUG=1` в development покажет детали. Частые причины: неверный `APP_KEY` (длина не 32 байта после base64), нет прав на `var/`, не применены миграции.

### Сессия сбрасывается сразу

`SESSION_SECURE=1` требует HTTPS. Для локального http поставьте `SESSION_SECURE=0`. Проверьте, что nginx не переписывает cookie-path и домен.

### «Нет доменов», хотя аккаунт подключён

Выполните `regru sync --scope=all` или кнопку синхронизации. Проверьте `regru doctor --online` и `var/log/app.log`.

### DNS-страница пуста

Через API управляются только зоны на `ns1.reg.ru`/`ns2.reg.ru`. Остальные домены показаны в блоке «Домены на NS хостинга».

### Не приходят письма или не работают ссылки

Панель не отправляет писем. Сброс пароля — только CLI: `regru user:passwd --login=<login> --password-stdin`.

## Развёртывание

### nginx отдаёт 404 на `/regru/`

Проверьте `nginx -T | grep -A5 'location ^~ /regru/'`, `try_files` и что front controller лежит в `<remote>/index.php`. Для подкаталога приложение срезает префикс по `SCRIPT_NAME`; при нестандартном rewrite смотрите `details.path`.

### 502 Bad Gateway

Сокет PHP-FPM в блоке nginx не совпадает с пулом сайта. Сравните `REGRU_FPM_SOCKET` и `grep listen` в конфигурации пула.

### 403 на статике `/regru/assets/...`

Файлы принадлежат не тому пользователю или сработал `disable_symlinks if_not_owner`. Выполните `chown -R <site_user>:<site_user> <remote>`.

### Cron не выполняется

У cron минимальный `PATH` и нет `HOME`. Используйте абсолютные пути, проверяйте `journalctl -u cron` и почту пользователя. Запускайте под владельцем файлов.

### «Database is locked»

SQLite в WAL допускает одного писателя. Панель ставит `busy_timeout=5000`; при интенсивном cron уменьшите частоту синхронизации или перенесите бэкап на ночь.

### После обновления белый экран

Проверьте, что миграции применились (`regru doctor`) и задеплоился новый `assets/`. Кэш браузера: `Ctrl+F5`.

## Диагностика

```bash
php backend/bin/regru doctor --online
php backend/bin/regru routes
tail -50 var/log/app.log
sqlite3 var/regru.sqlite 'select id,status,started_at,error from sync_runs order by id desc limit 5;'
```

Дальше: [Эксплуатация](08-Operations) · [FAQ](11-FAQ)
