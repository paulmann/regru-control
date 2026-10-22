import { api } from '../api.js';
import {
  badge, btn, chip, clear, copyButton, daysLabel, drawer, fmtDate, h, iconEl, kv, modal,
  statusBadge, toast,
} from '../dom.js';
import { href, navigate } from '../router.js';

export async function render(root, ctx) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Хостинги и серверы'),
      h('p', { class: 'page-sub' }, 'IP-адреса и панели управления по данным REG.RU. Пароли, которые API не отдаёт, можно сохранить вручную в разделе «Доступы».'),
    ),
    h('div', { class: 'page-actions' },
      btn('Синхронизировать данные', { tone: 'ghost', iconName: 'refresh', onClick: () => ctx.runSync('details') }),
      btn('Запросить данные услуг', { iconName: 'refresh', title: 'Повторно запросить service/get_details', onClick: () => syncPasswords(root, ctx) }),
    ),
  ));

  const container = h('div');
  root.appendChild(container);

  let items;
  try {
    items = await api('hostings');
  } catch (error) {
    container.appendChild(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    return;
  }

  if (!items.length) {
    container.appendChild(h('div', { class: 'card' }, h('div', { class: 'card-body' },
      h('div', { class: 'empty' }, iconEl('server'), h('strong', null, 'Хостингов нет'),
        h('span', null, 'Панель покажет ISPmanager/cPanel/Plesk и серверы после синхронизации.'),
        btn('Синхронизировать', { iconName: 'refresh', onClick: () => ctx.runSync('all') }),
      ),
    )));
    return;
  }

  const table = h('table');
  table.appendChild(h('thead', null, h('tr', null,
    h('th', null, 'Услуга'),
    h('th', null, 'Состояние'),
    h('th', null, 'Адрес'),
    h('th', { class: 'hide-sm' }, 'Логин'),
    h('th', null, 'Пароль'),
    h('th', { class: 'hide-sm' }, 'Оплачен до'),
    h('th', { class: 'actions' }, 'Действия'),
  )));
  const tbody = h('tbody');

  for (const item of items) {
    const days = daysLabel(item.days_left);
    tbody.appendChild(h('tr', null,
      h('td', null,
        h('div', { class: 'flex items-center gap-8' },
          h('span', { class: 'icon-box' }, iconEl(item.category === 'server' ? 'cpu' : 'server')),
          h('div', null,
            h('div', { class: 'row-main' }, item.title),
            h('div', { class: 'row-sub' }, item.type_label, item.plan ? ` · ${item.plan}` : ''),
          ),
        ),
      ),
      h('td', null, statusBadge(item.state_label, item.state_tone)),
      h('td', null,
        item.server_ip
          ? h('div', { class: 'copyable' }, h('code', { class: 'mono' }, item.server_ip), copyButton(item.server_ip))
          : h('span', { class: 'muted' }, 'неизвестно'),
        item.panel_url ? h('div', { class: 'mt-8' }, h('a', { class: 'fs-12', href: item.panel_url, target: '_blank', rel: 'noopener noreferrer' }, 'Открыть панель', iconEl('external'))) : null,
      ),
      h('td', { class: 'hide-sm' }, item.panel_login
        ? h('div', { class: 'copyable' }, h('code', { class: 'mono' }, item.panel_login), copyButton(item.panel_login))
        : h('span', { class: 'muted' }, '—')),
      h('td', null, item.has_panel_password
        ? btn('Показать', { tone: 'ghost', size: 'sm', iconName: 'eye', onClick: () => reveal(item.service_id) })
        : h('div', { class: 'flex gap-8' },
          h('span', { class: 'muted fs-12', title: 'REG.RU API не возвращает пароль для этого типа услуги' }, 'API не вернул'),
          btn('Вручную', { tone: 'ghost', size: 'sm', iconName: 'plus', onClick: () => navigate('/credentials') }),
        )),
      h('td', { class: 'hide-sm' }, h('div', null, fmtDate(item.expiration_date)), chip(days.text, days.tone)),
      h('td', { class: 'actions' },
        btn('', { tone: 'ghost', size: 'sm', iconName: 'info', title: 'Подробности', onClick: () => details(item.service_id) }),
        item.panel_url ? btn('', { tone: 'ghost', size: 'sm', iconName: 'external', title: 'Открыть панель', onClick: () => window.open(item.panel_url, '_blank', 'noopener') }) : null,
      ),
    ));
  }

  table.appendChild(tbody);
  container.appendChild(h('section', { class: 'card' },
    h('header', { class: 'card-head' },
      h('h2', null, 'Услуги хостинга и серверов'),
      h('span', { class: 'spacer' }),
      h('span', { class: 'muted fs-12' }, `Всего: ${items.length}`),
    ),
    h('div', { class: 'table-wrap' }, table),
  ));

  const missing = items.filter((item) => !item.has_panel_password).length;
  if (missing > 0) {
    container.appendChild(h('div', { class: 'notice warn mt-16' }, iconEl('alert'),
      h('div', null,
        h('strong', null, 'Часть паролей недоступна через API'),
        h('div', null, `REG.RU вернул данные для ${items.length - missing} из ${items.length} услуг. Для остальных пароль панели можно сохранить вручную в разделе «Доступы» — он будет зашифрован и доступен агенту по API с правом secrets:read.`),
        h('div', { class: 'mt-8' }, btn('Открыть хранилище доступов', { tone: 'soft', size: 'sm', iconName: 'key', onClick: () => navigate('/credentials') })),
      ),
    ));
  }

  container.appendChild(h('div', { class: 'notice info mt-16' }, iconEl('shield'),
    h('div', null,
      h('strong', null, 'Пароли хранятся зашифрованными'),
      h('div', null, 'Каждое нажатие «Показать» расшифровывает пароль на сервере и записывается в журнал действий. Пароли никогда не попадают в логи и в список API без отдельного права secrets:read.'),
    ),
  ));

  if (ctx.params.id) {
    await details(ctx.params.id);
  }

  async function reveal(serviceId) {
    try {
      const data = await api(`hostings/${encodeURIComponent(serviceId)}/reveal`, { method: 'POST' });
      const dialog = modal({
        title: 'Доступ к панели',
        body: h('div', { class: 'flex', style: { flexDirection: 'column', gap: '14px' } },
          kv([
            ['Логин', h('span', { class: 'copyable' }, h('code', { class: 'mono' }, data.login || '—'), copyButton(data.login || ''))],
            ['Пароль', h('span', { class: 'copyable' }, h('code', { class: 'mono' }, data.password), copyButton(data.password))],
            ['IP', data.ip ?? '—'],
            ['Панель', data.url ? h('a', { href: data.url, target: '_blank', rel: 'noopener noreferrer' }, data.url) : '—'],
          ]),
          h('div', { class: 'notice warn' }, iconEl('alert'), h('div', null, 'Доступ к паролю записан в журнал (кто, когда, с какого IP).')),
        ),
        footer: [
          btn('Скопировать логин и пароль', {
            iconName: 'copy',
            onClick: () => navigator.clipboard.writeText(`${data.login}\n${data.password}`).then(() => toast('Скопировано', 'ok')),
          }),
          btn('Закрыть', { tone: 'ghost', onClick: () => dialog.close() }),
        ],
      });
    } catch (error) {
      toast(error.message, 'bad');
    }
  }

  async function details(serviceId) {
    try {
      const item = await api(`hostings/${encodeURIComponent(serviceId)}`);
      const detailsEntries = Object.entries(item.details ?? {})
        .filter(([, value]) => value !== null && value !== undefined && typeof value !== 'object')
        .slice(0, 20)
        .map(([key, value]) => [key, String(value)]);

      const instance = drawer({
        title: item.title,
        onClose: () => navigate('/hostings'),
        body: h('div', { class: 'flex', style: { flexDirection: 'column', gap: '16px' } },
          kv([
            ['Тип', item.type_label],
            ['Состояние', statusBadge(item.state_label, item.state_tone)],
            ['ID услуги', h('span', { class: 'mono' }, item.service_id)],
            ['Тариф', item.plan ?? '—'],
            ['IP-адрес', item.server_ip ?? '—'],
            ['Панель', item.panel_url ? h('a', { href: item.panel_url, target: '_blank', rel: 'noopener noreferrer' }, 'открыть') : '—'],
            ['Логин', item.panel_login ?? '—'],
            ['Пароль', item.has_panel_password ? 'есть (зашифрован)' : 'REG.RU не вернул'],
            ['Оплачен до', fmtDate(item.expiration_date)],
            ['DNS-серверы хостинга', (item.nss ?? []).join(', ') || '—'],
          ]),
          detailsEntries.length ? h('div', null,
            h('div', { class: 'section-head' }, h('h2', null, 'Данные услуги')),
            kv(detailsEntries),
          ) : null,
          h('div', { class: 'btn-row' },
            item.has_panel_password ? btn('Показать пароль', { iconName: 'eye', onClick: () => { instance.close(); reveal(serviceId); } }) : null,
            item.dns_managed ? null : null,
          ),
        ),
      });
    } catch (error) {
      toast(error.message, 'bad');
    }
  }
}

async function syncPasswords(root, ctx) {
  try {
    const result = await api('hostings/sync', { method: 'POST', body: { force: true } });
    toast(`Обновлено: ${result.synced}, ошибок: ${result.failed}`, result.failed ? 'warn' : 'ok');
    if (result.errors?.length) console.warn('hosting sync', result.errors);
    await render(root, ctx);
  } catch (error) {
    toast(error.message, 'bad');
  }
}
