# 08 · Эксплуатация

## Синхронизация

Полная синхронизация — около 15 запросов к REG.RU (лимит 1200/час, локальный предохранитель 1100):

1. `service/get_list` — услуги;
2. `domain/get_nss` — NS одним списком;
3. `service/get_info` — автопродление;
4. `service/get_details` — данные хостингов (только для услуг без попытки);
5. `zone/get_resource_records` — DNS-зоны пакетами по 10 доменов.

Этапы независимы: ошибка одного даёт статус `partial`, остальные данные сохраняются.

```bash
php backend/bin/regru sync --scope=all
php backend/bin/regru sync --scope=services   # быстрее
php backend/bin/regru sync --scope=dns
php backend/bin/regru sync --json             # машинный вывод
```

Cron — в [Установке](02-Getting-Started).

## Резервные копии

```bash
php backend/bin/regru backup
```

Создаёт `var/backups/regru-YYYYmmdd-HHMMSS.sqlite` через `VACUUM INTO` (консистентный снимок WAL) и оставляет 14 копий. Ключ `APP_KEY` в бэкап не входит: `.env` бэкапьте отдельно, иначе пароли не расшифровать.

## Обновление

```bash
git pull
php tools/lint.php
php backend/tests/run.php
php backend/bin/regru backup
php tools/build.php --target=../deploy
```

Затем выкладка (`tools/deploy.ps1` на Windows или rsync/scp), `php backend/bin/regru migrate`, `doctor --online`. Предыдущий релиз на сервере архивируется в `/root/regru-backup/`.

## Диагностика

```bash
php backend/bin/regru doctor --online   # окружение + доступ к API
php backend/bin/regru routes            # маршруты
tail -100 var/log/app.log               # JSON-лог без секретов
```

Каждая строка журнала содержит `request_id`; он же в ответе API и заголовке `X-Request-Id`.

## Мониторинг

- `GET /api/v1/health` — статус, версия, PHP, БД.
- `GET /api/v1/sync` — когда была синхронизация и были ли ошибки.
- Страница «Журнал» — входы, изменения, раскрытия паролей.
- Ошибки REG.RU видны в истории синхронизации и в логе (`api_code`).

## Ограничения REG.RU

- Пароли панелей отдаются не для всех типов услуг — недоступные сохраняются вручную в «Доступах».
- DNS через API управляется только для зон на `ns1.reg.ru`/`ns2.reg.ru`.
- Некоторые команды доступны только партнёрам — видно в каталоге команд.
- Официальный лимит — 1200 запросов/час; панель останавливается на 1100.

Дальше: [Неполадки](09-Troubleshooting) · [Конфигурация](03-Configuration-Reference)
