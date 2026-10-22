import { api } from '../api.js';
import {
  badge, btn, clear, fmtDate, fmtDateTime, h, iconEl, kv, money, statCard, toast,
} from '../dom.js';
import { account } from '../store.js';
import { navigate } from '../router.js';

export async function render(root, ctx) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Биллинг'),
      h('p', { class: 'page-sub' }, 'Баланс, неоплаченные счета и история начислений REG.RU.'),
    ),
    h('div', { class: 'page-actions' },
      btn('Обновить баланс', { iconName: 'refresh', onClick: () => loadBalance(true) }),
    ),
  ));

  if (!account()?.configured) {
    root.appendChild(h('div', { class: 'notice warn' }, iconEl('alert'),
      h('div', { class: 'grow' },
        h('strong', null, 'Аккаунт REG.RU не подключён'),
        h('div', null, 'Баланс и счета доступны после настройки логина и пароля API.'),
      ),
      btn('Настроить', { tone: 'soft', iconName: 'settings', onClick: () => navigate('/settings') }),
    ));
    return;
  }

  const stats = h('div', { class: 'grid auto mb-16' });
  const balanceCard = h('div');
  const unpaidCard = h('div');
  const billsCard = h('div');
  root.append(stats, balanceCard, unpaidCard, billsCard);

  async function loadBalance(force = false) {
    balanceCard.replaceChildren(h('div', { class: 'skeleton', style: { height: '90px' } }));
    try {
      const balance = await api('billing', { query: force ? { refresh: 1 } : {} });
      stats.replaceChildren(
        statCard({ label: 'Предоплата', value: money(balance.prepay, balance.currency), hint: balance.cached ? 'из кэша (5 минут)' : 'актуально', iconName: 'invoice', tone: 'hero' }),
        statCard({ label: 'Заблокировано', value: money(balance.blocked, balance.currency), hint: 'например, на торгах', iconName: 'lock' }),
        statCard({ label: 'Кредит', value: money(balance.credit, balance.currency), hint: 'доступно партнёрам', iconName: 'cloud' }),
      );
      balanceCard.replaceChildren(h('div', { class: 'notice info' }, iconEl('info'),
        h('div', null, `Баланс получен: ${balance.fetched_at ? fmtDateTime(balance.fetched_at) : 'сейчас'}. Регион: ${balance.currency}.`, balance.error ? ` Ошибка обновления: ${balance.error}` : ''),
      ));
    } catch (error) {
      stats.replaceChildren();
      balanceCard.replaceChildren(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    }
  }

  async function loadUnpaid() {
    unpaidCard.replaceChildren(h('div', { class: 'skeleton', style: { height: '80px' } }));
    try {
      const items = await api('billing/unpaid');
      if (!items.length) {
        unpaidCard.replaceChildren(h('div', { class: 'notice ok' }, iconEl('check'), h('div', null, 'Неоплаченных счетов нет.')));
        return;
      }
      const table = h('table', null,
        h('thead', null, h('tr', null, h('th', null, 'Счёт'), h('th', null, 'Дата'), h('th', { class: 'num' }, 'Сумма'), h('th', null, 'Статус'))),
        h('tbody', null, ...items.map((bill) => h('tr', null,
          h('td', { class: 'mono' }, bill.bill_id),
          h('td', null, fmtDate(bill.bill_date)),
          h('td', { class: 'num' }, money(bill.total_payment, bill.currency)),
          h('td', null, badge(bill.pay_status, 'warn')),
        ))),
      );
      unpaidCard.replaceChildren(h('section', { class: 'card' },
        h('header', { class: 'card-head' }, h('h2', null, 'Неоплаченные счета'), h('span', { class: 'spacer' }), h('span', { class: 'badge warn' }, `${items.length}`)),
        h('div', { class: 'table-wrap' }, table),
      ));
    } catch (error) {
      unpaidCard.replaceChildren(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    }
  }

  async function loadBills(from, to) {
    billsBody.replaceChildren(h('div', { class: 'skeleton', style: { height: '120px', margin: '16px' } }));
    try {
      const items = await api('billing/bills', { query: { from, to, limit: 200 } });
      const table = h('table', null,
        h('thead', null, h('tr', null,
          h('th', null, 'Счёт'), h('th', null, 'Дата'), h('th', { class: 'num' }, 'Сумма'), h('th', null, 'Оплата'), h('th', null, 'Статус'), h('th', { class: 'num' }, 'Позиций'),
        )),
        h('tbody', null, ...(items.length ? items.map((bill) => h('tr', null,
          h('td', { class: 'mono' }, bill.bill_id),
          h('td', null, fmtDate(bill.bill_date)),
          h('td', { class: 'num' }, money(bill.total_payment, bill.currency)),
          h('td', null, bill.pay_type),
          h('td', null, badge(bill.pay_status, bill.pay_status === 'payed' ? 'ok' : bill.pay_status === 'notpayed' ? 'warn' : '')),
          h('td', { class: 'num' }, String(bill.items?.length ?? 0)),
        )) : [h('tr', null, h('td', { colspan: '6' }, h('div', { class: 'empty' }, h('strong', null, 'Счетов за период нет'))))])),
      );
      billsBody.replaceChildren(h('div', { class: 'table-wrap' }, table));
    } catch (error) {
      billsBody.replaceChildren(h('div', { class: 'notice bad', style: { margin: '16px' } }, iconEl('alert'), h('div', null, error.message)));
    }
  }

  const today = new Date();
  const from = fieldDate('С', new Date(today.getTime() - 90 * 86400000));
  const to = fieldDate('По', today);
  const billsBody = h('div');
  const billsHeader = h('section', { class: 'card mt-16' },
    h('header', { class: 'card-head' },
      h('h2', null, 'История счетов'),
      h('span', { class: 'spacer' }),
      h('div', { class: 'inline-form' }, from.wrap, to.wrap, btn('Показать', { tone: 'ghost', size: 'sm', iconName: 'search', onClick: () => loadBills(from.input.value, to.input.value) })),
    ),
    billsBody,
  );
  billsCard.appendChild(billsHeader);

  await Promise.all([loadBalance(), loadUnpaid(), loadBills(from.input.value, to.input.value)]);
}

function fieldDate(label, date) {
  const input = h('input', {
    type: 'date',
    value: date.toISOString().slice(0, 10),
    'aria-label': label,
    style: { width: '150px' },
  });
  return { wrap: h('div', { class: 'flex items-center gap-8' }, h('span', { class: 'muted fs-12' }, label), input), input };
}
