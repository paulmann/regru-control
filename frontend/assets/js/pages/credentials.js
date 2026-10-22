import { api } from '../api.js';
import {
  badge, btn, chip, clear, confirmDialog, copyButton, drawer, field, h, iconEl, kv, modal,
  plural, searchField, toast,
} from '../dom.js';
import { credentialKindMeta, enums } from '../store.js';

export async function render(root, ctx) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Доступы'),
      h('p', { class: 'page-sub' }, 'Пароли панелей, FTP, SSH, баз данных и почты. Шифруются на сервере (libsodium), раскрытие — только по запросу и с записью в журнал.'),
    ),
    h('div', { class: 'page-actions' },
      btn('Добавить доступ', { iconName: 'plus', onClick: () => editDialog({ onDone: () => render(root, ctx) }) }),
    ),
  ));

  let kind = '';
  let query = '';
  const container = h('div');
  root.appendChild(container);

  const card = h('section', { class: 'card' });
  const toolbar = h('div', { class: 'toolbar' },
    searchField('Поиск по названию, хосту, логину', (value) => { query = value; load(); }),
    (() => {
      const select = h('select', { 'aria-label': 'Тип доступа' });
      select.appendChild(h('option', { value: '' }, 'Все типы'));
      for (const item of enums().credential_kinds ?? []) {
        select.appendChild(h('option', { value: item.value }, item.label));
      }
      select.addEventListener('change', () => { kind = select.value; load(); });
      return select;
    })(),
    h('span', { class: 'spacer' }),
    h('span', { class: 'muted fs-12', id: 'cred-count' }, ''),
  );
  const body = h('div');
  card.append(toolbar, body);
  container.appendChild(card);

  async function load() {
    body.replaceChildren(h('div', { class: 'card-body' },
      h('div', { class: 'skeleton', style: { height: '120px' } }),
    ));

    try {
      const result = await api('credentials', { query: { kind, q: query } });
      const count = toolbar.querySelector('#cred-count');
      if (count) count.textContent = `Записей: ${result.length}`;
      renderTable(result);
    } catch (error) {
      body.replaceChildren(h('div', { class: 'card-body' },
        h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)),
      ));
    }
  }

  function renderTable(items) {
    if (!items.length) {
      body.replaceChildren(h('div', { class: 'empty' }, iconEl('key'),
        h('strong', null, 'Хранилище пусто'),
        h('span', null, 'Пароли хостингов появятся после синхронизации, остальные можно добавить вручную.'),
        btn('Добавить доступ', { iconName: 'plus', onClick: () => editDialog({ onDone: () => render(root, ctx) }) }),
      ));
      return;
    }

    const table = h('table');
    table.appendChild(h('thead', null, h('tr', null,
      h('th', null, 'Название'),
      h('th', null, 'Тип'),
      h('th', { class: 'hide-sm' }, 'Хост'),
      h('th', null, 'Логин'),
      h('th', null, 'Пароль'),
      h('th', { class: 'actions' }, 'Действия'),
    )));
    const tbody = h('tbody');

    for (const item of items) {
      const kindMeta = credentialKindMeta(item.kind);
      tbody.appendChild(h('tr', null,
        h('td', null,
          h('div', { class: 'flex items-center gap-8' },
            h('span', { class: 'icon-box' }, iconEl(kindMeta?.icon ?? 'key')),
            h('div', null,
              h('div', { class: 'row-main' }, item.title),
              h('div', { class: 'row-sub' }, item.source === 'sync' ? 'из REG.RU' : 'добавлено вручную', item.dname ? ` · ${item.dname}` : ''),
            ),
          ),
        ),
        h('td', null, badge(kindMeta?.label ?? item.kind)),
        h('td', { class: 'hide-sm' }, h('div', { class: 'copyable' }, h('code', { class: 'mono' }, item.host || '—'), item.host ? copyButton(item.host) : null)),
        h('td', null, h('span', { class: 'mono' }, item.username || '—')),
        h('td', null, item.has_password
          ? btn('Показать', { tone: 'ghost', size: 'sm', iconName: 'eye', onClick: () => reveal(item.id) })
          : h('span', { class: 'muted fs-12' }, 'нет')),
        h('td', { class: 'actions' },
          btn('', { tone: 'ghost', size: 'sm', iconName: 'pencil', title: 'Изменить', onClick: () => editDialog({ item, onDone: () => render(root, ctx) }) }),
          btn('', { tone: 'danger-ghost', size: 'sm', iconName: 'trash', title: 'Удалить', onClick: () => remove(item) }),
        ),
      ));
    }

    table.appendChild(tbody);
    body.replaceChildren(h('div', { class: 'table-wrap' }, table));
  }

  async function reveal(id) {
    try {
      const data = await api(`credentials/${id}/reveal`, { method: 'POST' });
      const dialog = modal({
        title: data.title,
        body: h('div', { class: 'flex', style: { flexDirection: 'column', gap: '14px' } },
          kv([
            ['Логин', h('span', { class: 'copyable' }, h('code', { class: 'mono' }, data.username || '—'), copyButton(data.username || ''))],
            ['Пароль', h('span', { class: 'copyable' }, h('code', { class: 'mono' }, data.password), copyButton(data.password))],
            ['Ссылка', data.url ? h('a', { href: data.url, target: '_blank', rel: 'noopener noreferrer' }, data.url) : '—'],
          ]),
          h('div', { class: 'notice warn' }, iconEl('shield'), h('div', null, 'Пароль расшифрован на сервере; запись об этом добавлена в журнал действий.')),
        ),
        footer: [
          btn('Скопировать пароль', { iconName: 'copy', onClick: () => navigator.clipboard.writeText(data.password).then(() => toast('Скопировано', 'ok')) }),
          btn('Закрыть', { tone: 'ghost', onClick: () => dialog.close() }),
        ],
      });
    } catch (error) {
      toast(error.message, 'bad');
    }
  }

  async function remove(item) {
    const confirmed = await confirmDialog({
      title: 'Удалить доступ?',
      message: `«${item.title}» будет удалён из хранилища. Это действие необратимо.`,
      confirmLabel: 'Удалить',
      danger: true,
    });
    if (!confirmed) return;

    try {
      await api(`credentials/${item.id}`, { method: 'DELETE' });
      toast('Запись удалена', 'ok');
      await load();
    } catch (error) {
      toast(error.message, 'bad');
    }
  }

  await load();
}

function editDialog({ item = null, onDone }) {
  const title = field({ label: 'Название', name: 'title', value: item?.title ?? '', placeholder: 'Например: ISPmanager example.com', required: true });
  const kind = field({
    label: 'Тип',
    name: 'kind',
    type: 'select',
    value: item?.kind ?? 'panel',
    options: (enums().credential_kinds ?? []).map((value) => ({ value: value.value, label: value.label })),
  });
  const host = field({ label: 'Хост / адрес', name: 'host', value: item?.host ?? '', placeholder: '203.0.113.10 или host.example.com' });
  const port = field({ label: 'Порт', name: 'port', type: 'number', value: item?.port ?? '', placeholder: '1500' });
  const username = field({ label: 'Логин', name: 'username', value: item?.username ?? '', placeholder: 'login' });
  const password = h('input', { type: 'password', autocomplete: 'new-password', placeholder: item ? 'оставьте пустым, чтобы не менять' : 'пароль' });
  const revealToggle = btn('', { tone: 'ghost', size: 'sm', iconName: 'eye', title: 'Показать пароль', onClick: () => { password.type = password.type === 'password' ? 'text' : 'password'; } });
  const generateBtn = btn('Сгенерировать', {
    tone: 'ghost',
    size: 'sm',
    iconName: 'refresh',
    onClick: async () => {
      try {
        const result = await api('credentials/generate', { method: 'POST', body: { length: 24 } });
        password.value = result.password;
        password.type = 'text';
      } catch (error) {
        toast(error.message, 'bad');
      }
    },
  });
  const passwordRow = h('div', { class: 'field full' },
    h('label', null, 'Пароль', item ? ' (необязательно)' : ' *'),
    h('div', { class: 'flex gap-8' }, password, revealToggle, generateBtn),
  );
  const url = field({ label: 'Ссылка на панель', name: 'url', value: item?.url ?? '', placeholder: 'https://203.0.113.10:1500/' });
  const dname = field({ label: 'Связанный домен', name: 'dname', value: item?.dname ?? '', placeholder: 'example.com' });
  const serviceId = field({ label: 'ID услуги REG.RU', name: 'service_id', value: item?.service_id ?? '', placeholder: '1234567' });
  const notes = field({ label: 'Заметки', name: 'notes', type: 'textarea', value: item?.notes ?? '', rows: 3, hint: 'Например: где используется, кому принадлежит, как восстановить.' });

  const body = h('div', { class: 'form-grid' },
    title.wrap,
    kind.wrap,
    host.wrap,
    port.wrap,
    username.wrap,
    h('div', { class: 'field' }),
    passwordRow,
    url.wrap,
    dname.wrap,
    serviceId.wrap,
    (() => { notes.wrap.classList.add('full'); return notes.wrap; })(),
  );

  const dialog = modal({
    title: item ? 'Изменить доступ' : 'Новый доступ',
    body,
    footer: [
      btn('Отмена', { tone: 'ghost', onClick: () => dialog.close() }),
      btn(item ? 'Сохранить' : 'Добавить', {
        iconName: 'check',
        onClick: async () => {
          const payload = {
            title: title.input.value.trim(),
            kind: kind.input.value,
            host: host.input.value.trim(),
            port: port.input.value === '' ? null : Number(port.input.value),
            username: username.input.value.trim(),
            url: url.input.value.trim(),
            dname: dname.input.value.trim(),
            service_id: serviceId.input.value.trim(),
            notes: notes.input.value.trim(),
          };
          if (!item || password.value) payload.password = password.value;

          try {
            if (item) {
              await api(`credentials/${item.id}`, { method: 'PUT', body: payload });
              toast('Доступ обновлён', 'ok');
            } else {
              await api('credentials', { method: 'POST', body: payload });
              toast('Доступ добавлен', 'ok');
            }
            dialog.close();
            onDone?.();
          } catch (error) {
            if (error.fieldErrors) {
              for (const [name, message] of Object.entries(error.fieldErrors)) {
                const target = { title, kind, host, port, username }[name];
                target?.setError(message);
              }
            }
            toast(error.message, 'bad');
          }
        },
      }),
    ],
  });
}
