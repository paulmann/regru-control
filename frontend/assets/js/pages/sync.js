import { api } from '../api.js';
import {
  badge, btn, chip, clear, fmtDateTime, h, iconEl, statCard, toast,
} from '../dom.js';
import { syncScopeMeta } from '../store.js';
import { navigate } from '../router.js';

let pollTimer = null;

export async function render(root, ctx) {
  clear(root);
  clearInterval(pollTimer);
  pollTimer = null;

  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'Синхронизация'),
      h('p', { class: 'page-sub' }, 'Загрузка услуг, доменов, DNS-зон и данных хостингов из REG.RU API. Полный цикл — около 15 запросов, лимит аккаунта 1200/час.'),
    ),
    h('div', { class: 'page-actions' },
      btn('К доменам', { tone: 'ghost', iconName: 'globe', onClick: () => navigate('/domains') }),
    ),
  ));

  const statusBox = h('div');
  const actionsBox = h('div', { class: 'grid auto mb-16' });
  const historyBox = h('div');
  root.append(statusBox, actionsBox, historyBox);

  for (const item of [
    { scope: 'all', label: 'Полная синхронизация', hint: 'услуги, домены, DNS, хостинги', icon: 'refresh' },
    { scope: 'services', label: 'Услуги и домены', hint: '1–2 запроса к API', icon: 'globe' },
    { scope: 'details', label: 'Данные хостингов', hint: 'логины и пароли панелей', icon: 'server' },
    { scope: 'dns', label: 'DNS-зоны', hint: 'только делегированные на REG.RU', icon: 'list' },
  ]) {
    const button = btn('Запустить', {
      iconName: item.icon,
      onClick: async () => {
        button.disabled = true;
        const result = await ctx.runSync(item.scope);
        button.disabled = false;
        if (result) await load();
      },
    });
    actionsBox.appendChild(h('section', { class: 'card' }, h('div', { class: 'card-body' },
      h('div', { class: 'flex items-center gap-8' },
        h('span', { class: 'icon-box' }, iconEl(item.icon)),
        h('div', { class: 'grow' },
          h('div', { class: 'row-main' }, item.label),
          h('div', { class: 'row-sub' }, item.hint),
        ),
      ),
      h('div', { class: 'mt-16' }, button),
    )));
  }

  async function load() {
    statusBox.replaceChildren(h('div', { class: 'skeleton', style: { height: '120px', marginBottom: '16px' } }));
    try {
      const data = await api('sync');
      renderStatus(data.status);
      renderHistory(data.history);
      if (data.status.running) {
        pollTimer = setTimeout(load, 3000);
      }
    } catch (error) {
      statusBox.replaceChildren(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    }
  }

  function renderStatus(status) {
    const latest = status.latest;
    const lastSuccess = status.last_success;
    const counts = status.counts ?? {};

    statusBox.replaceChildren(h('div', { class: 'grid auto mb-16' },
      statCard({
        label: 'Последний успешный запуск',
        value: lastSuccess ? new Date(lastSuccess.finished_at || lastSuccess.started_at).toLocaleString('ru-RU') : '—',
        hint: lastSuccess ? `статус: ${lastSuccess.status}` : 'ещё не было',
        iconName: 'check',
      }),
      statCard({
        label: 'Состояние',
        value: status.running ? 'выполняется…' : status.stale ? 'данные устарели' : 'актуально',
        hint: status.stale ? `TTL ${Math.round(status.ttl_seconds / 60)} мин` : 'синхронизация свежая',
        iconName: 'refresh',
        tone: status.running ? '' : status.stale ? '' : 'hero',
      }),
      statCard({ label: 'Услуги', value: String(counts.services ?? 0), hint: 'в локальной базе', iconName: 'server' }),
      statCard({ label: 'Домены', value: String(counts.domains ?? 0), hint: `${counts.dns_records ?? 0} DNS-записей`, iconName: 'globe' }),
      statCard({ label: 'Хостинги', value: String(counts.hostings ?? 0), hint: 'включая серверы', iconName: 'cpu' }),
    ));

    if (latest?.status === 'failed' || latest?.status === 'partial') {
      statusBox.appendChild(h('div', { class: 'notice warn' }, iconEl('alert'),
        h('div', null,
          h('strong', null, latest.status === 'failed' ? 'Последний запуск завершился ошибкой' : 'Последний запуск завершился с замечаниями'),
          h('div', { class: 'fs-13' }, latest.error ?? 'Подробности в истории ниже.'),
        ),
      ));
    }
  }

  function renderHistory(items) {
    if (!items?.length) {
      historyBox.replaceChildren(h('div', { class: 'card' }, h('div', { class: 'card-body' },
        h('div', { class: 'empty' }, iconEl('refresh'), h('strong', null, 'Запусков ещё не было'), h('span', null, 'Нажмите «Запустить» — история появится здесь.')),
      )));
      return;
    }

    const table = h('table');
    table.appendChild(h('thead', null, h('tr', null,
      h('th', null, 'Начало'),
      h('th', null, 'Статус'),
      h('th', null, 'Источник'),
      h('th', { class: 'hide-sm' }, 'Итоги'),
      h('th', { class: 'num' }, 'Длительность'),
      h('th', { class: 'hide-sm' }, 'Ошибки'),
    )));
    const tbody = h('tbody');

    for (const run of items) {
      const stats = run.stats ?? {};
      const summary = [
        stats.inventory?.services !== undefined ? `услуг: ${stats.inventory.services}` : '',
        stats.inventory?.domains !== undefined ? `доменов: ${stats.inventory.domains}` : '',
        stats.dns?.records !== undefined ? `DNS: ${stats.dns.records}` : '',
        stats.details?.synced !== undefined ? `панелей: ${stats.details.synced}` : '',
      ].filter(Boolean).join(' · ') || '—';

      tbody.appendChild(h('tr', null,
        h('td', null, fmtDateTime(run.started_at)),
        h('td', null, badge(run.status, run.status === 'ok' ? 'ok' : run.status === 'partial' ? 'warn' : run.status === 'running' ? 'info' : 'bad')),
        h('td', null, chip(run.trigger)),
        h('td', { class: 'hide-sm' }, summary),
        h('td', { class: 'num' }, run.duration_seconds === null ? '—' : `${run.duration_seconds} с`),
        h('td', { class: 'hide-sm' }, run.error ? h('span', { class: 'text-warn truncate', title: run.error }, 'есть') : '—'),
      ));
    }

    table.appendChild(tbody);
    historyBox.replaceChildren(h('section', { class: 'card' },
      h('header', { class: 'card-head' }, h('h2', null, 'История запусков'), h('span', { class: 'spacer' }), h('span', { class: 'muted fs-12' }, `последние ${items.length}`)),
      h('div', { class: 'table-wrap' }, table),
    ));
  }

  await load();
}
