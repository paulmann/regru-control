import { api } from '../api.js';
import {
  badge, btn, chip, clear, copyButton, field, h, iconEl, kv, toast,
} from '../dom.js';
import { appInfo, applyTheme, isAdmin, state, toggleTheme } from '../store.js';
import { navigate } from '../router.js';

export async function render(root, ctx) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Настройки'),
      h('p', { class: 'page-sub' }, 'Подключение к REG.RU API, безопасность, параметры синхронизации и данные.'),
    ),
  ));

  const container = h('div', { class: 'grid cols-2' });
  root.appendChild(container);

  let view;
  try {
    view = await api('settings');
  } catch (error) {
    container.replaceChildren(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    return;
  }

  container.appendChild(accountCard(view));
  container.appendChild(securityCard(view));
  container.appendChild(syncCard(view));
  container.appendChild(appearanceCard());
  container.appendChild(systemCard(view));
  container.appendChild(exportCard());
}

function accountCard(view) {
  const username = field({ label: 'Логин REG.RU', name: 'username', value: view.account.username ?? '', placeholder: 'например, mylogin' });
  const password = field({ label: 'Пароль API', name: 'password', type: 'password', placeholder: 'пароль из настроек API', hint: 'Хранится зашифрованным (libsodium). Никогда не возвращается через API.' });
  const currency = field({
    label: 'Валюта',
    name: 'currency',
    type: 'select',
    value: view.account.currency ?? 'RUR',
    options: ['RUR', 'USD', 'EUR'].map((value) => ({ value, label: value })),
  });

  const status = view.account.configured
    ? h('div', { class: 'notice ok' }, iconEl('check'),
      h('div', null,
        h('strong', null, `Аккаунт подключён: ${view.account.username}`),
        h('div', { class: 'fs-12' }, view.account.last_verified_at ? `Проверен: ${new Date(view.account.last_verified_at).toLocaleString('ru-RU')}` : 'ещё не проверялся'),
        view.account.last_error ? h('div', { class: 'fs-12 text-bad' }, view.account.last_error) : null,
      ))
    : h('div', { class: 'notice warn' }, iconEl('alert'),
      h('div', null,
        h('strong', null, 'Аккаунт не подключён'),
        h('div', { class: 'fs-12' }, 'Укажите логин и пароль API. Пароль — не от личного кабинета, а из раздела «Настройки API».'),
      ));

  const save = btn('Сохранить и проверить', {
    iconName: 'check',
    onClick: async () => {
      save.disabled = true;
      try {
        const result = await api('settings/account', {
          method: 'PUT',
          body: { username: username.input.value.trim(), password: password.input.value, currency: currency.input.value },
        });
        if (result.ok) {
          toast('Доступ к REG.RU API подтверждён', 'ok');
          password.input.value = '';
          await refresh();
        } else {
          toast(`Сохранено, но проверка не прошла: ${result.error}`, 'bad', 8000);
        }
      } catch (error) {
        toast(error.message, 'bad');
      } finally {
        save.disabled = false;
      }
    },
  });

  const test = btn('Проверить соединение', {
    tone: 'ghost',
    iconName: 'activity',
    onClick: async () => {
      try {
        const result = await api('settings/account/test', { method: 'POST' });
        toast(result.ok ? 'API REG.RU доступен' : `Ошибка: ${result.error}`, result.ok ? 'ok' : 'bad', 6000);
      } catch (error) {
        toast(error.message, 'bad');
      }
    },
  });

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' }, h('h2', null, 'REG.RU API'), h('span', { class: 'spacer' }), badge(view.account.configured ? 'подключён' : 'не настроен', view.account.configured ? 'ok' : 'warn')),
    h('div', { class: 'card-body' },
      status,
      h('div', { class: 'form-grid mt-16' }, username.wrap, currency.wrap, (() => { password.wrap.classList.add('full'); return password.wrap; })()),
      h('div', { class: 'btn-row mt-16' }, save, test),
      h('div', { class: 'notice info mt-16' }, iconEl('shield'),
        h('div', null,
          h('strong', null, 'Доступ по IP'),
          h('div', { class: 'fs-12' }, 'В настройках API REG.RU должен быть разрешён IP сервера панели. Если запросы отклоняются с кодом ACCESS_DENIED_FROM_IP — добавьте адрес сервера в белый список.'),
        ),
      ),
    ),
  );
}

function securityCard() {
  const current = field({ label: 'Текущий пароль', name: 'current', type: 'password', autocomplete: 'current-password' });
  const next = field({ label: 'Новый пароль', name: 'next', type: 'password', autocomplete: 'new-password', hint: 'Минимум 12 символов, два класса символов.' });
  const repeat = field({ label: 'Повторите новый пароль', name: 'repeat', type: 'password', autocomplete: 'new-password' });

  const submit = btn('Сменить пароль', {
    iconName: 'lock',
    onClick: async () => {
      if (next.input.value !== repeat.input.value) {
        toast('Пароли не совпадают', 'bad');
        return;
      }
      submit.disabled = true;
      try {
        await api('auth/password', {
          method: 'POST',
          body: { current_password: current.input.value, new_password: next.input.value },
        });
        toast('Пароль изменён', 'ok');
        current.input.value = next.input.value = repeat.input.value = '';
      } catch (error) {
        toast(error.message, 'bad');
      } finally {
        submit.disabled = false;
      }
    },
  });

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' }, h('h2', null, 'Безопасность'), h('span', { class: 'spacer' }), h('span', { class: 'badge' }, 'сессия 30 мин')),
    h('div', { class: 'card-body' },
      h('div', { class: 'form-grid' }, current.wrap, next.wrap, repeat.wrap),
      h('div', { class: 'btn-row mt-16' }, submit),
      h('div', { class: 'notice info mt-16' }, iconEl('info'),
        h('div', { class: 'fs-12' }, 'Сессия завершается через 30 минут бездействия. Выход — в меню пользователя. Все изменения и раскрытия паролей пишутся в журнал.'),
      ),
    ),
  );
}

function syncCard(view) {
  const ttl = field({
    label: 'Интервал синхронизации, секунд',
    name: 'sync_ttl',
    type: 'number',
    value: view.sync.ttl,
    min: 300,
    max: 86400,
    hint: 'Подсказка на обзоре, когда данные устарели. Автоматического фонового запуска нет — синхронизация запускается вручную или по cron.',
  });

  const save = btn('Сохранить', {
    iconName: 'check',
    onClick: async () => {
      save.disabled = true;
      try {
        await api('settings', { method: 'PUT', body: { sync_ttl: Number(ttl.input.value) } });
        toast('Настройки сохранены', 'ok');
      } catch (error) {
        toast(error.message, 'bad');
      } finally {
        save.disabled = false;
      }
    },
  });

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' }, h('h2', null, 'Синхронизация'), h('span', { class: 'spacer' })),
    h('div', { class: 'card-body' },
      syncCardNotice(),
      ttl.wrap,
      h('div', { class: 'btn-row mt-16' }, save, btn('Открыть синхронизацию', { tone: 'ghost', iconName: 'refresh', onClick: () => navigate('/sync') })),
    ),
  );
}

function syncCardNotice() {
  return h('div', { class: 'notice info mb-16' }, iconEl('info'),
    h('div', { class: 'fs-12' }, 'Полная синхронизация расходует лимит REG.RU API (1200 запросов/час). Панель останавливается на 1100, чтобы не заблокировать аккаунт.'),
  );
}

function appearanceCard() {
  const button = btn(state.theme === 'dark' ? 'Светлая тема' : 'Тёмная тема', {
    tone: 'ghost',
    iconName: state.theme === 'dark' ? 'sun' : 'moon',
    onClick: () => {
      const theme = toggleTheme();
      applyTheme(theme);
      button.textContent = '';
      button.appendChild(iconEl(theme === 'dark' ? 'sun' : 'moon'));
      button.appendChild(document.createTextNode(theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'));
    },
  });

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' }, h('h2', null, 'Оформление'), h('span', { class: 'spacer' })),
    h('div', { class: 'card-body' },
      h('p', { class: 'muted', style: { marginTop: 0 } }, 'Тёмная тема — как в референсной панели. Светлая — для работы днём.'),
      h('div', { class: 'btn-row' }, button),
    ),
  );
}

function systemCard(view) {
  const system = view.system;
  return h('section', { class: 'card' },
    h('header', { class: 'card-head' }, h('h2', null, 'Система'), h('span', { class: 'spacer' }), badge(appInfo().env ?? 'production')),
    h('div', { class: 'card-body' },
      kv([
        ['Приложение', `${view.app.name} ${view.app.version}`],
        ['PHP', system.php],
        ['SAPI', system.sapi],
        ['ОС', system.os],
        ['SQLite', system.sqlite],
        ['База', system.db_path],
        ['Размер базы', system.db_size],
        ['Часовой пояс', view.app.timezone],
      ]),
      h('div', { class: 'section-head mt-16' }, h('h2', null, 'Расширения PHP')),
      h('div', { class: 'chips' }, ...system.extensions.map((extension) => chip(extension, extension.includes('отсутствует') ? 'bad' : 'ok'))),
      h('div', { class: 'section-head mt-16' }, h('h2', null, 'Лимиты')),
      kv([
        ['Запросов к REG.RU в час', String(view.limits.regru_per_hour)],
        ['Запросов к панели в минуту', String(view.limits.api_per_minute)],
        ['Простой сессии', `${Math.round(view.limits.session_idle_seconds / 60)} мин`],
      ]),
    ),
  );
}

function exportCard() {
  const download = (data, name) => {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const link = h('a', { href: URL.createObjectURL(blob), download: name });
    document.body.appendChild(link);
    link.click();
    link.remove();
  };

  const plain = btn('Скачать без паролей', {
    tone: 'ghost',
    iconName: 'download',
    onClick: async () => {
      try {
        const data = await api('settings/export', { method: 'POST', body: { include_secrets: false } });
        download(data, `regru-export-${new Date().toISOString().slice(0, 10)}.json`);
        toast('Экспорт без паролей скачан', 'ok');
      } catch (error) {
        toast(error.message, 'bad');
      }
    },
  });

  const secrets = btn('Скачать с паролями', {
    tone: 'danger-ghost',
    iconName: 'lock',
    onClick: async () => {
      const answer = window.prompt('Выгрузка содержит пароли. Введите EXPORT для подтверждения:');
      if (answer !== 'EXPORT') return;
      try {
        const data = await api('settings/export', { method: 'POST', body: { include_secrets: true, confirm: 'EXPORT' } });
        download(data, `regru-export-secrets-${new Date().toISOString().slice(0, 10)}.json`);
        toast('Экспорт с паролями скачан. Храните файл в защищённом месте.', 'warn', 8000);
      } catch (error) {
        toast(error.message, 'bad');
      }
    },
  });

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' }, h('h2', null, 'Экспорт данных'), h('span', { class: 'spacer' }), h('span', { class: 'badge warn' }, 'JSON')),
    h('div', { class: 'card-body' },
      h('p', { class: 'muted', style: { marginTop: 0 } }, 'Полная выгрузка инвентаря: домены, услуги, хостинги, доступы, баланс. Без паролей — безопасно хранить где угодно.'),
      h('div', { class: 'btn-row' }, plain, secrets),
      h('div', { class: 'notice warn mt-16' }, iconEl('alert'),
        h('div', { class: 'fs-12' }, 'Экспорт с паролями доступен только администратору и требует подтверждения. Действие фиксируется в журнале.'),
      ),
      h('div', { class: 'mt-16 fs-13' }, h('a', { href: 'api/v1/openapi.yaml', target: '_blank', rel: 'noopener' }, 'OpenAPI-спецификация'), ' · ',
        h('a', { href: '#/api', onClick: (event) => { event.preventDefault(); navigate('/api'); } }, 'Примеры API для агента')),
    ),
  );
}

async function refresh() {
  await render(document.getElementById('content'), {});
}
