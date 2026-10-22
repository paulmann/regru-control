import { api, apiRaw } from '../api.js';
import {
  badge, btn, chip, clear, drawer, fmtDateTime, h, iconEl, kv, searchField, toast,
} from '../dom.js';

const filters = { action: '', actor: '', limit: 100, offset: 0 };

export async function render(root) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Журнал действий'),
      h('p', { class: 'page-sub' }, 'Кто и когда менял данные, раскрывал пароли или запускал синхронизацию.'),
    ),
  ));

  const card = h('section', { class: 'card' });
  const tbody = h('tbody');
  const table = h('table', null,
    h('thead', null, h('tr', null,
      h('th', null, 'Время'),
      h('th', null, 'Актор'),
      h('th', null, 'Действие'),
      h('th', { class: 'hide-sm' }, 'Объект'),
      h('th', null, 'Результат'),
      h('th', { class: 'hide-sm' }, 'IP'),
      h('th', null, ''),
    )),
    tbody,
  );
  const info = h('span', { class: 'muted fs-12' }, '');
  card.append(
    h('div', { class: 'toolbar' },
      searchField('Фильтр по актору', (value) => { filters.actor = value; filters.offset = 0; load(); }),
      (() => {
        const input = h('input', { type: 'text', placeholder: 'Действие, например dns.add', 'aria-label': 'Действие', style: { maxWidth: '240px' } });
        input.addEventListener('change', () => { filters.action = input.value.trim(); filters.offset = 0; load(); });
        return input;
      })(),
      h('span', { class: 'spacer' }),
      info,
      btn('Обновить', { tone: 'ghost', size: 'sm', iconName: 'refresh', onClick: () => load() }),
    ),
    h('div', { class: 'table-wrap' }, table),
  );
  root.appendChild(card);

  async function load() {
    tbody.replaceChildren(h('tr', null, h('td', { colspan: '7' }, h('div', { class: 'skeleton', style: { height: '120px' } }))));
    try {
      const result = await apiRaw('audit', { query: filters });
      info.textContent = `Событий: ${result.meta.total}`;
      tbody.replaceChildren();
      if (!result.data.length) {
        tbody.appendChild(h('tr', null, h('td', { colspan: '7' }, h('div', { class: 'empty' }, iconEl('activity'), h('strong', null, 'Событий нет')))));
        return;
      }
      for (const event of result.data) {
        const row = h('tr', { class: 'clickable', onClick: () => details(event) },
          h('td', { class: 'nowrap' }, fmtDateTime(event.at)),
          h('td', null, chip(`${event.actor_type}: ${event.actor}`, event.actor_type === 'api' ? 'accent' : '')),
          h('td', null, h('code', { class: 'mono fs-12' }, event.action)),
          h('td', { class: 'hide-sm' }, event.target || '—'),
          h('td', null, badge(event.result, event.result === 'ok' ? 'ok' : 'bad')),
          h('td', { class: 'hide-sm' }, h('span', { class: 'mono fs-12' }, event.ip || '—')),
          h('td', null, iconEl('chevron_right')),
        );
        tbody.appendChild(row);
      }
    } catch (error) {
      toast(error.message, 'bad');
    }
  }

  function details(event) {
    drawer({
      title: event.action,
      body: h('div', { class: 'flex', style: { flexDirection: 'column', gap: '16px' } },
        kv([
          ['Время', fmtDateTime(event.at)],
          ['Актор', `${event.actor_type} · ${event.actor}`],
          ['Объект', event.target || '—'],
          ['Результат', event.result],
          ['IP', event.ip || '—'],
          ['Request ID', h('span', { class: 'mono' }, event.request_id || '—')],
        ]),
        h('div', null,
          h('div', { class: 'section-head' }, h('h2', null, 'Детали')),
          h('pre', { class: 'code' }, JSON.stringify(event.details ?? {}, null, 2)),
        ),
      ),
    });
  }

  await load();
}
