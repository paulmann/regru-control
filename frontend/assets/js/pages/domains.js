import { api, apiRaw } from '../api.js';
import {
  badge, btn, chip, clear, copyButton, daysLabel, drawer, field, fmtDate, h, iconEl, kv, modal,
  money, pagination, searchField, statusBadge, toast,
} from '../dom.js';
import { enums } from '../store.js';
import { href, navigate } from '../router.js';

const filters = {
  q: '',
  state: '',
  expiring_in: '',
  sort: 'expiration',
  dir: 'asc',
  page: 1,
  per_page: 50,
};

let rowsCache = [];

export async function render(root, ctx) {
  clear(root);
  if (ctx.query.q !== undefined) filters.q = ctx.query.q;
  if (ctx.query.state) filters.state = ctx.query.state;
  if (ctx.query.sort) filters.sort = ctx.query.sort;
  if (ctx.query.page) filters.page = Number(ctx.query.page) || 1;

  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Домены'),
      h('p', { class: 'page-sub' }, 'Регистрация, оплата, делегирование и доступ к DNS-зонам.'),
    ),
    h('div', { class: 'page-actions' },
      btn('Синхронизировать', { tone: 'ghost', iconName: 'refresh', onClick: () => ctx.runSync('services') }),
      btn('Скачать CSV', { tone: 'ghost', iconName: 'download', onClick: () => exportCsv(rowsCache) }),
    ),
  ));

  const tableCard = h('section', { class: 'card' });
  root.appendChild(tableCard);

  const tbody = h('tbody');
  const table = h('table', null,
    h('thead', null, h('tr', null,
      h('th', null, 'Домен'),
      h('th', null, 'Статус'),
      h('th', null, 'Оплачен до'),
      h('th', { class: 'num' }, 'Осталось'),
      h('th', { class: 'hide-sm' }, 'DNS'),
      h('th', { class: 'hide-sm' }, 'Автопродление'),
      h('th', { class: 'actions' }, 'Действия'),
    )),
    tbody,
  );
  const pager = h('div');
  const countEl = h('span', { class: 'muted fs-12' }, '');

  tableCard.append(
    h('div', { class: 'toolbar' },
      searchField('Поиск по имени домена', (value) => { filters.q = value; filters.page = 1; load(); }, { value: filters.q }),
      selectFilter('Состояние', [{ value: '', label: 'Все' }, ...(enums().service_states ?? []).map((item) => ({ value: item.value, label: item.label }))], filters.state, (value) => { filters.state = value; filters.page = 1; load(); }),
      selectFilter('Оплата', [
        { value: '', label: 'Любая дата' },
        { value: '14', label: '≤ 14 дней' },
        { value: '30', label: '≤ 30 дней' },
        { value: '60', label: '≤ 60 дней' },
        { value: '90', label: '≤ 90 дней' },
      ], filters.expiring_in, (value) => { filters.expiring_in = value; filters.page = 1; load(); }),
      selectFilter('Сортировка', [
        { value: 'expiration', label: 'По дате оплаты' },
        { value: 'dname', label: 'По имени' },
        { value: 'days_left', label: 'По остатку дней' },
        { value: 'state', label: 'По статусу' },
      ], filters.sort, (value) => { filters.sort = value; load(); }),
      h('span', { class: 'spacer' }),
      countEl,
    ),
    h('div', { class: 'table-wrap' }, table),
    pager,
  );

  async function load() {
    tbody.replaceChildren(...Array.from({ length: 5 }, () => h('tr', null,
      ...Array.from({ length: 7 }, () => h('td', null, h('div', { class: 'skeleton', style: { width: '80%' } }))),
    )));

    try {
      const result = await apiRaw('domains', { query: filters });
      rowsCache = result.data;
      countEl.textContent = `Всего: ${result.meta.total}`;
      renderRows(result.data);
      renderPager(result.meta);
    } catch (error) {
      tbody.replaceChildren(h('tr', null, h('td', { colspan: '7' },
        h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)),
      )));
    }
  }

  function renderRows(rows) {
    tbody.replaceChildren();

    if (!rows.length) {
      const filtered = filters.q || filters.state || filters.expiring_in;
      tbody.appendChild(h('tr', null, h('td', { colspan: '7' },
        h('div', { class: 'empty' }, iconEl(filtered ? 'search' : 'globe'),
          h('strong', null, filtered ? 'Ничего не найдено' : 'Доменов пока нет'),
          h('span', null, filtered ? 'Измените фильтры или поисковый запрос.' : 'Запустите синхронизацию — список загрузится из REG.RU.'),
          filtered ? null : btn('Синхронизировать', { iconName: 'refresh', onClick: () => ctx.runSync('services') }),
        ),
      )));
      return;
    }

    for (const domain of rows) {
      const days = daysLabel(domain.days_left);
      const row = h('tr', { class: 'clickable', onClick: () => navigate(`/domains/${encodeURIComponent(domain.dname)}`) },
        h('td', null, h('div', { class: 'mono row-main' }, domain.dname), h('div', { class: 'row-sub' }, domain.tld ? `.${domain.tld}` : '')),
        h('td', null, statusBadge(domain.state_label, domain.state_tone)),
        h('td', { class: 'nowrap' }, fmtDate(domain.expiration_date)),
        h('td', { class: 'num' }, chip(days.text, days.tone)),
        h('td', { class: 'hide-sm' }, dnsCell(domain)),
        h('td', { class: 'hide-sm' }, autorenewCell(domain, load)),
        h('td', { class: 'actions' },
          btn('DNS', { tone: 'ghost', size: 'sm', iconName: 'list', disabled: !domain.dns_managed, title: domain.dns_managed ? 'Открыть DNS-зону' : 'Домен не на DNS REG.RU', onClick: (event) => { event.stopPropagation(); navigate(`/dns/${encodeURIComponent(domain.dname)}`); } }),
          btn('Продлить', { tone: 'soft', size: 'sm', onClick: (event) => { event.stopPropagation(); renewDialog(domain, load); } }),
        ),
      );
      tbody.appendChild(row);
    }
  }

  function renderPager(meta) {
    pager.replaceChildren();
    if (meta.pages > 1) {
      pager.appendChild(pagination({
        page: meta.page,
        pages: meta.pages,
        total: meta.total,
        perPage: meta.per_page,
        onPage: (page) => { filters.page = page; load(); },
      }));
    }
  }

  await load();

  if (ctx.params.name) {
    await openDomain(ctx.params.name, load);
  }
}

function selectFilter(label, options, value, onChange) {
  const select = h('select', { 'aria-label': label });
  for (const option of options) {
    select.appendChild(h('option', { value: option.value, selected: String(option.value) === String(value) }, option.label));
  }
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

function dnsCell(domain) {
  if (!domain.dns_managed) {
    return h('div', { class: 'chips' }, chip('внешний DNS'));
  }
  return h('div', { class: 'chips' },
    chip('REG.RU', 'ok'),
    h('span', { class: 'muted fs-12' }, (domain.nss ?? []).slice(0, 2).join(', ')),
  );
}

function autorenewCell(domain, onChanged) {
  if (domain.autorenew === null || domain.autorenew === undefined) {
    return h('span', { class: 'muted fs-12', title: 'REG.RU не возвращает этот флаг в списке услуг' }, '—');
  }

  const button = btn(domain.autorenew ? 'вкл' : 'выкл', {
    tone: domain.autorenew ? 'soft' : 'ghost',
    size: 'sm',
    title: 'Переключить автопродление',
    onClick: async (event) => {
      event.stopPropagation();
      button.disabled = true;
      try {
        await api(`domains/${encodeURIComponent(domain.dname)}/autorenew`, { method: 'POST', body: { enabled: !domain.autorenew } });
        toast(`Автопродление ${domain.dname}: ${!domain.autorenew ? 'включено' : 'выключено'}`, 'ok');
        onChanged?.();
      } catch (error) {
        toast(error.message, 'bad');
        button.disabled = false;
      }
    },
  });
  return button;
}

function renewDialog(domain, onDone) {
  const period = field({
    label: 'Период продления, лет',
    name: 'period',
    type: 'select',
    value: '1',
    options: Array.from({ length: 10 }, (_, index) => ({ value: String(index + 1), label: `${index + 1} год(а)` })),
  });

  const dialog = modal({
    title: `Продление ${domain.dname}`,
    body: h('div', null,
      h('p', { style: { marginTop: 0 } }, 'Сейчас домен оплачен до ', h('strong', null, fmtDate(domain.expiration_date)), '.'),
      period.wrap,
      h('div', { class: 'notice info mt-16' }, iconEl('info'),
        h('div', null, 'Сумма списывается с баланса REG.RU. При нехватке средств REG.RU создаст счёт на пополнение.'),
      ),
    ),
    footer: [
      btn('Отмена', { tone: 'ghost', onClick: () => dialog.close() }),
      btn('Продлить', {
        iconName: 'check',
        onClick: async () => {
          try {
            const result = await api(`domains/${encodeURIComponent(domain.dname)}/renew`, { method: 'POST', body: { period: Number(period.input.value) } });
            toast(`Домен продлён. Списано: ${money(result.payment, result.currency)}`, 'ok');
            dialog.close();
            onDone?.();
          } catch (error) {
            toast(error.message, 'bad');
          }
        },
      }),
    ],
  });
}

async function openDomain(dname, onChanged) {
  let domain;
  try {
    domain = await api(`domains/${encodeURIComponent(dname)}`);
  } catch (error) {
    toast(error.message, 'bad');
    navigate('/domains');
    return;
  }

  const nsInputs = [];
  const nss = domain.nss?.length ? domain.nss : ['', ''];
  for (let index = 0; index < 4; index++) {
    nsInputs.push(field({
      label: `DNS-сервер ns${index}`,
      name: `ns${index}`,
      value: nss[index] ?? '',
      placeholder: index < 2 ? 'ns1.example.com' : 'необязательно',
    }));
  }

  const undelegate = h('input', { type: 'checkbox' });
  const saveNs = btn('Сохранить NS', {
    iconName: 'check',
    onClick: async () => {
      saveNs.disabled = true;
      try {
        await api(`domains/${encodeURIComponent(dname)}/ns`, {
          method: 'PUT',
          body: {
            nss: nsInputs.map((item) => item.input.value.trim()).filter(Boolean),
            undelegate: undelegate.checked,
          },
        });
        toast('DNS-серверы обновлены', 'ok');
        instance.close();
        onChanged?.();
      } catch (error) {
        toast(error.message, 'bad');
      } finally {
        saveNs.disabled = false;
      }
    },
  });

  const days = daysLabel(domain.days_left);

  const body = h('div', { class: 'flex', style: { flexDirection: 'column', gap: '16px' } },
    kv([
      ['Состояние', statusBadge(domain.state_label, domain.state_tone)],
      ['Регистрация', fmtDate(domain.creation_date)],
      ['Оплачен до', fmtDate(domain.expiration_date)],
      ['Осталось', chip(days.text, days.tone)],
      ['ID услуги', h('span', { class: 'mono' }, domain.service_id)],
      ['DNS-записей в кэше', String(domain.dns_records ?? 0)],
      ['Автопродление', domain.autorenew === null ? 'неизвестно' : domain.autorenew ? 'включено' : 'выключено'],
    ]),
    h('div', { class: 'hr' }),
    h('div', { class: 'section-head' },
      h('h2', null, 'DNS-серверы'),
      h('span', { class: 'spacer' }),
      badge(domain.dns_managed ? 'зона REG.RU' : 'внешний DNS', domain.dns_managed ? 'ok' : 'warn'),
    ),
    ...nsInputs.map((item) => item.wrap),
    (domain.nss ?? []).some((ns) => /(?:hosting|shop)\.reg\.ru$/i.test(ns))
      ? h('div', { class: 'notice info' }, iconEl('info'),
        h('div', null, 'Домен использует NS хостинга REG.RU (ns5/ns6.hosting.reg.ru или ns1/ns2.shop.reg.ru). Зона управляется в панели ISPmanager, а не через DNS API REG.RU.'),
      )
      : null,
    h('label', { class: 'switch' }, undelegate, h('span', { class: 'track' }), h('span', null, 'Снять домен с делегирования')),
    h('div', { class: 'btn-row' }, saveNs),
    h('div', { class: 'hr' }),
    h('div', { class: 'btn-row' },
      btn('Открыть DNS-зону', { tone: 'ghost', iconName: 'list', disabled: !domain.dns_managed, onClick: () => { instance.close(); navigate(`/dns/${encodeURIComponent(dname)}`); } }),
      btn('Продлить', { tone: 'soft', iconName: 'invoice', onClick: () => renewDialog(domain, onChanged) }),
      btn('Скопировать имя', { tone: 'ghost', iconName: 'copy', onClick: () => navigator.clipboard.writeText(domain.dname).then(() => toast('Скопировано', 'ok')) }),
    ),
  );

  const instance = drawer({
    title: domain.dname,
    body,
    onClose: () => { navigate('/domains'); onChanged?.(); },
  });
}

function exportCsv(rows) {
  if (!rows.length) {
    toast('Нет данных для экспорта', 'warn');
    return;
  }
  const header = ['domain', 'tld', 'state', 'expiration_date', 'days_left', 'dns_managed', 'nss', 'service_id'];
  const lines = [header.join(';')];
  for (const row of rows) {
    lines.push([
      row.dname, row.tld, row.state, row.expiration_date ?? '', row.days_left ?? '',
      row.dns_managed ? '1' : '0', (row.nss ?? []).join(','), row.service_id,
    ].map((value) => `"${String(value).replaceAll('"', '""')}"`).join(';'));
  }
  const blob = new Blob([`\uFEFF${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
  const link = h('a', { href: URL.createObjectURL(blob), download: `domains-${new Date().toISOString().slice(0, 10)}.csv` });
  document.body.appendChild(link);
  link.click();
  link.remove();
}
