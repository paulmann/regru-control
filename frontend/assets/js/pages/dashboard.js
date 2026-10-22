import { api } from '../api.js';
import { btn, card, chip, clear, daysLabel, dot, fmtDate, fmtDateTime, h, iconEl, money, num, statCard, statusBadge, toast } from '../dom.js';
import { href, navigate } from '../router.js';
import { isAdmin } from '../store.js';

export async function render(root, ctx) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Обзор'),
      h('p', { class: 'page-sub' }, 'Сводка по аккаунту REG.RU: баланс, домены, хостинги и синхронизация.'),
    ),
    h('div', { class: 'page-actions' },
      btn('Синхронизировать', { iconName: 'refresh', onClick: () => ctx.runSync('all') }),
      btn('Настройки', { tone: 'ghost', iconName: 'settings', onClick: () => navigate('/settings') }),
    ),
  ));

  let data;
  try {
    data = await api('dashboard');
  } catch (error) {
    if (error.status === 409 && error.code === 'REGRU_ACCOUNT_NOT_CONFIGURED') {
      root.appendChild(accountNotice());
      return;
    }
    throw error;
  }

  if (!data.account?.configured) {
    root.appendChild(accountNotice());
  }

  const counts = data.counts ?? {};
  const balance = data.balance;

  const stats = h('div', { class: 'grid auto mb-16' },
    statCard({
      label: 'Баланс',
      value: balance ? money(balance.prepay, balance.currency) : '—',
      hint: balance
        ? `${balance.cached ? 'из кэша' : 'актуально'}${balance.blocked ? ` · заблокировано ${money(balance.blocked, balance.currency)}` : ''}`
        : (data.balance_error ? 'не удалось получить' : 'аккаунт не настроен'),
      iconName: 'invoice',
      tone: balance ? 'hero' : '',
    }),
    statCard({
      label: 'Домены',
      value: num(counts.domains),
      hint: counts.domains_expiring_30 > 0
        ? h('span', { class: 'text-warn' }, `требуют продления: ${counts.domains_expiring_30}`)
        : 'все оплачены',
      iconName: 'globe',
    }),
    statCard({
      label: 'Хостинги и серверы',
      value: num(counts.hostings),
      hint: `${num(counts.services)} услуг всего`,
      iconName: 'server',
    }),
    statCard({
      label: 'Доступы в хранилище',
      value: num(counts.credentials),
      hint: 'пароли зашифрованы',
      iconName: 'key',
    }),
    statCard({
      label: 'DNS-записи',
      value: num(counts.dns_records),
      hint: `${num(counts.api_tokens)} активных API-токенов`,
      iconName: 'list',
    }),
  );
  root.appendChild(stats);

  const columns = h('div', { class: 'grid cols-2' });
  columns.appendChild(expiringCard(data.expiring ?? []));
  columns.appendChild(hostingsCard(data.hostings ?? []));
  root.appendChild(columns);

  const bottom = h('div', { class: 'grid cols-2 mt-16' });
  bottom.appendChild(syncCard(data.sync ?? {}, ctx));
  bottom.appendChild(quickCard());
  root.appendChild(bottom);
}

function accountNotice() {
  return h('div', { class: 'notice warn mb-16' }, iconEl('alert'),
    h('div', { class: 'grow' },
      h('strong', null, 'Аккаунт REG.RU не подключён'),
      h('div', null, 'Укажите логин и пароль API в настройках — после этого станут доступны домены, DNS, хостинги и баланс.'),
    ),
    btn('Настроить', { tone: 'soft', iconName: 'settings', onClick: () => navigate('/settings') }),
  );
}

function expiringCard(items) {
  const body = h('div');
  if (!items.length) {
    body.appendChild(h('div', { class: 'empty' }, iconEl('check'), h('strong', null, 'Нет срочных продлений'), h('span', null, 'В ближайшие 60 дней ничего не истекает.')));
  } else {
    const table = h('table');
    table.appendChild(h('thead', null, h('tr', null,
      h('th', null, 'Домен'), h('th', null, 'Оплачен до'), h('th', { class: 'num' }, 'Осталось'), h('th', null, ''),
    )));
    const tbody = h('tbody');
    for (const item of items) {
      const days = daysLabel(item.days_left);
      tbody.appendChild(h('tr', { class: 'clickable', onClick: () => navigate(`/domains/${encodeURIComponent(item.dname)}`) },
        h('td', null, h('span', { class: 'mono row-main' }, item.dname)),
        h('td', null, fmtDate(item.expiration_date)),
        h('td', { class: 'num' }, chip(days.text, days.tone)),
        h('td', { class: 'actions' }, h('a', { class: 'btn ghost sm', href: href(`/domains/${encodeURIComponent(item.dname)}`) }, 'Открыть')),
      ));
    }
    table.appendChild(tbody);
    body.appendChild(h('div', { class: 'table-wrap' }, table));
  }

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' },
      h('h2', null, 'Истекают в ближайшие 60 дней'),
      h('span', { class: 'spacer' }),
      h('a', { class: 'btn ghost sm', href: href('/domains') }, 'Все домены'),
    ),
    body,
  );
}

function hostingsCard(items) {
  const list = h('div', { class: 'list' });
  if (!items.length) {
    list.appendChild(h('div', { class: 'empty' }, iconEl('server'), h('strong', null, 'Хостингов нет'), h('span', null, 'После синхронизации здесь появятся серверы и панели.')));
  }

  for (const item of items) {
    list.appendChild(h('div', { class: 'list-item' },
      h('span', { class: `icon-box ${item.state_tone === 'ok' ? 'ok' : item.state_tone === 'warn' ? 'warn' : ''}` }, iconEl(item.category === 'server' ? 'cpu' : 'server')),
      h('div', { class: 'grow' },
        h('div', { class: 'title' }, item.title),
        h('div', { class: 'sub' },
          item.type_label,
          item.server_ip ? ` · ${item.server_ip}` : '',
          item.panel_login ? ` · ${item.panel_login}` : '',
        ),
      ),
      h('div', { class: 'meta' },
        statusBadge(item.state_label, item.state_tone),
        h('div', { class: 'mt-8' },
          item.panel_url
            ? h('a', { class: 'btn ghost sm', href: item.panel_url, target: '_blank', rel: 'noopener noreferrer' }, 'Панель', iconEl('external'))
            : h('a', { class: 'btn ghost sm', href: href('/hostings') }, 'Подробнее'),
        ),
      ),
    ));
  }

  return h('section', { class: 'card' },
    h('header', { class: 'card-head' },
      h('h2', null, 'Хостинги и серверы'),
      h('span', { class: 'spacer' }),
      h('a', { class: 'btn ghost sm', href: href('/hostings') }, 'Все'),
    ),
    list,
  );
}

function syncCard(sync, ctx) {
  const latest = sync.latest;
  const body = h('div', { class: 'card-body' },
    h('div', { class: 'flex items-center gap-12 mb-16' },
      dot(sync.running ? 'warn' : latest?.status === 'ok' ? 'ok' : latest?.status === 'partial' ? 'warn' : latest ? 'bad' : ''),
      h('div', { class: 'grow' },
        h('div', { class: 'row-main' }, sync.running ? 'Синхронизация выполняется' : latest ? `Последний запуск: ${latest.status === 'ok' ? 'успешно' : latest.status === 'partial' ? 'с замечаниями' : 'ошибка'}` : 'Ещё не запускалась'),
        h('div', { class: 'row-sub' }, latest ? fmtDateTime(latest.finished_at || latest.started_at) : 'нажмите «Синхронизировать»'),
      ),
    ),
    h('div', { class: 'btn-row' },
      btn('Запустить полную', { iconName: 'refresh', onClick: () => ctx.runSync('all') }),
      btn('Только DNS', { tone: 'ghost', onClick: () => ctx.runSync('dns') }),
      btn('История', { tone: 'ghost', onClick: () => navigate('/sync') }),
    ),
  );

  return card('Синхронизация', body);
}

function quickCard() {
  const items = [
    { label: 'Открыть список доменов', icon: 'globe', path: '/domains' },
    { label: 'Добавить доступ в хранилище', icon: 'key', path: '/credentials' },
    { label: 'Каталог команд REG.RU API', icon: 'terminal', path: '/api' },
    isAdmin() ? { label: 'Журнал действий', icon: 'activity', path: '/audit' } : null,
  ].filter(Boolean);

  const body = h('div', { class: 'list' });
  for (const item of items) {
    body.appendChild(h('a', { class: 'list-item', href: href(item.path), style: { textDecoration: 'none', color: 'inherit' } },
      h('span', { class: 'icon-box' }, iconEl(item.icon)),
      h('div', { class: 'grow' }, h('div', { class: 'title' }, item.label)),
      iconEl('chevron_right'),
    ));
  }

  return card('Быстрые действия', body);
}
