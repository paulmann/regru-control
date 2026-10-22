import { api, apiBase } from '../api.js';
import {
  badge, btn, chip, clear, codeBlock, confirmDialog, copyButton, field, h, iconEl, kv, modal,
  searchField, tabs, toast,
} from '../dom.js';
import { enums, isAdmin } from '../store.js';

let catalogCache = null;
let activeTab = 0;

export async function render(root, ctx) {
  clear(root);
  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'API REG.RU'),
      h('p', { class: 'page-sub' }, 'Токены для интеграций и агента, полный каталог команд REG.RU API v2 и консоль произвольного вызова.'),
    ),
    h('div', { class: 'page-actions' },
      btn('Открыть OpenAPI', { tone: 'ghost', iconName: 'external', onClick: () => window.open('api/v1/openapi.yaml', '_blank', 'noopener') }),
    ),
  ));

  const content = h('div');
  const tabBar = tabs([
    { label: 'Токены' },
    { label: 'Каталог команд' },
    { label: 'Raw-консоль' },
    { label: 'Примеры для агента' },
  ], {
    active: activeTab,
    onChange: (index) => { activeTab = index; renderTab(); },
  });

  root.appendChild(h('section', { class: 'card' }, tabBar, content));

  function renderTab() {
    clear(content);
    if (activeTab === 0) renderTokens(content);
    if (activeTab === 1) renderCatalog(content);
    if (activeTab === 2) renderConsole(content);
    if (activeTab === 3) renderDocs(content);
  }

  renderTab();
}

async function renderTokens(container) {
  if (!isAdmin()) {
    container.appendChild(h('div', { class: 'card-body' }, h('div', { class: 'notice warn' }, iconEl('alert'),
      h('div', null, 'Управление токенами доступно только администратору.'),
    )));
    return;
  }

  const body = h('div', { class: 'card-body' });
  container.append(body);
  body.appendChild(h('div', { class: 'flex items-center gap-8 mb-16' },
    h('div', { class: 'grow' },
      h('strong', null, 'API-токены'),
      h('div', { class: 'muted fs-12' }, 'Токен показывается один раз. Права ограничиваются областями (scopes).'),
    ),
    btn('Создать токен', { iconName: 'plus', onClick: () => createToken(() => renderTokensReplace(container)) }),
  ));
  const listBox = h('div');
  body.appendChild(listBox);
  await load();

  async function load() {
    listBox.replaceChildren(h('div', { class: 'skeleton', style: { height: '90px' } }));
    try {
      const tokens = await api('tokens');
      if (!tokens.length) {
        listBox.replaceChildren(h('div', { class: 'empty' }, iconEl('key'), h('strong', null, 'Токенов нет'), h('span', null, 'Создайте токен, чтобы агент мог читать инвентарь и пароли по API.')));
        return;
      }
      const table = h('table');
      table.appendChild(h('thead', null, h('tr', null,
        h('th', null, 'Название'), h('th', null, 'Префикс'), h('th', { class: 'hide-sm' }, 'Права'), h('th', null, 'Создан'), h('th', { class: 'hide-sm' }, 'Последнее использование'), h('th', null, 'Статус'), h('th', { class: 'actions' }, ''),
      )));
      const tbody = h('tbody');
      for (const token of tokens) {
        tbody.appendChild(h('tr', null,
          h('td', null, h('div', { class: 'row-main' }, token.name), h('div', { class: 'row-sub' }, token.expires_at ? `до ${new Date(token.expires_at).toLocaleDateString('ru-RU')}` : 'бессрочный')),
          h('td', null, h('code', { class: 'mono fs-12' }, token.prefix)),
          h('td', { class: 'hide-sm' }, h('div', { class: 'chips' }, ...token.scopes.map((scope) => chip(scope, 'accent')))),
          h('td', { class: 'nowrap fs-12' }, new Date(token.created_at).toLocaleDateString('ru-RU')),
          h('td', { class: 'hide-sm fs-12' }, token.last_used_at ? new Date(token.last_used_at).toLocaleString('ru-RU') : '—'),
          h('td', null, badge(token.revoked_at ? 'отозван' : 'активен', token.revoked_at ? 'bad' : 'ok')),
          h('td', { class: 'actions' }, token.revoked_at ? null : btn('Отозвать', {
            tone: 'danger-ghost', size: 'sm', onClick: async () => {
              const confirmed = await confirmDialog({ title: 'Отозвать токен?', message: `Токен «${token.name}» перестанет работать сразу.`, confirmLabel: 'Отозвать', danger: true });
              if (!confirmed) return;
              try {
                await api(`tokens/${token.id}`, { method: 'DELETE' });
                toast('Токен отозван', 'ok');
                await load();
              } catch (error) {
                toast(error.message, 'bad');
              }
            },
          })),
        ));
      }
      table.appendChild(tbody);
      listBox.replaceChildren(h('div', { class: 'table-wrap' }, table));
    } catch (error) {
      listBox.replaceChildren(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    }
  }
}

function renderTokensReplace(container) {
  clear(container);
  renderTokens(container);
}

function createToken(after) {
  const name = field({ label: 'Название', name: 'name', placeholder: 'agent / ci / monitoring', required: true });
  const ttl = field({ label: 'Срок действия, дней', name: 'ttl_days', type: 'number', value: '', placeholder: '365 — оставьте пустым для бессрочного' });
  const scopeBoxes = [];
  const scopesWrap = h('div', { class: 'field full' }, h('label', null, 'Права (scopes)'));
  for (const scope of enums().token_scopes ?? []) {
    const checkbox = h('input', { type: 'checkbox', value: scope.value });
    scopeBoxes.push({ checkbox, value: scope.value });
    scopesWrap.appendChild(h('label', { class: 'switch', style: { marginBottom: '8px' } },
      checkbox,
      h('span', { class: 'track' }),
      h('span', null, h('strong', null, scope.label), h('span', { class: 'muted fs-12' }, ` — ${scope.description}`)),
    ));
  }

  const defaults = ['read'];
  for (const item of scopeBoxes) item.checkbox.checked = defaults.includes(item.value);

  const dialog = modal({
    title: 'Новый API-токен',
    body: h('div', { class: 'form-grid' }, name.wrap, ttl.wrap, scopesWrap),
    footer: [
      btn('Отмена', { tone: 'ghost', onClick: () => dialog.close() }),
      btn('Создать', {
        iconName: 'key',
        onClick: async () => {
          const scopes = scopeBoxes.filter((item) => item.checkbox.checked).map((item) => item.value);
          if (!scopes.length) { toast('Выберите хотя бы одно право', 'bad'); return; }
          try {
            const result = await api('tokens', {
              method: 'POST',
              body: { name: name.input.value.trim(), scopes, ttl_days: ttl.input.value === '' ? null : Number(ttl.input.value) },
            });
            dialog.close();
            showPlainToken(result);
            after?.();
          } catch (error) {
            toast(error.message, 'bad');
          }
        },
      }),
    ],
  });
}

function showPlainToken(result) {
  const token = result.plain;
  const dialog = modal({
    title: 'Токен создан',
    body: h('div', { class: 'flex', style: { flexDirection: 'column', gap: '14px' } },
      h('div', { class: 'notice warn' }, iconEl('alert'), h('div', null, 'Скопируйте токен сейчас. После закрытия окна он больше не показывается — в базе хранится только хеш.')),
      h('div', { class: 'field' }, h('label', null, 'Токен'), h('div', { class: 'copyable' }, h('code', { class: 'mono' }, token), copyButton(token))),
      kv([
        ['Название', result.token.name],
        ['Права', result.token.scopes.join(', ')],
        ['Действует до', result.token.expires_at ? new Date(result.token.expires_at).toLocaleString('ru-RU') : 'бессрочно'],
      ]),
    ),
    footer: [
      btn('Скопировать', { iconName: 'copy', onClick: () => navigator.clipboard.writeText(token).then(() => toast('Токен скопирован', 'ok')) }),
      btn('Готово', { tone: 'ghost', onClick: () => dialog.close() }),
    ],
    wide: true,
  });
}

async function renderCatalog(container) {
  if (!catalogCache) {
    try {
      catalogCache = await api('meta/commands');
    } catch (error) {
      container.appendChild(h('div', { class: 'card-body' }, h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message))));
      return;
    }
  }

  const data = catalogCache;
  const meta = data.meta ?? {};
  const listing = h('div');

  const renderList = (query = '') => {
    const needle = query.toLowerCase();
    const groups = {};
    for (const command of data.commands) {
      if (needle && !`${command.command} ${command.purpose}`.toLowerCase().includes(needle)) continue;
      (groups[command.group_label] ??= []).push(command);
    }

    clear(listing);
    const entries = Object.entries(groups);
    if (!entries.length) {
      listing.appendChild(h('div', { class: 'empty' }, iconEl('search'), h('strong', null, 'Ничего не найдено')));
      return;
    }

    for (const [label, commands] of entries) {
      const group = h('div', { class: 'cmd-group' }, h('h3', null, `${label} · ${commands.length}`));
      for (const command of commands) {
        group.appendChild(h('div', { class: 'cmd' },
          h('code', null, command.command),
          h('div', { class: 'purpose' }, command.purpose || command.title),
          h('span', { class: 'avail' }, command.availability ? chip(command.availability, command.availability === 'Партнёры' ? 'warn' : '') : ''),
          copyButton(command.command),
        ));
      }
      listing.appendChild(group);
    }
  };

  container.appendChild(h('div', { class: 'card-body' },
    h('div', { class: 'flex items-center gap-12 flex-wrap mb-16' },
      h('div', { class: 'grow' },
        h('strong', null, `Каталог REG.RU API v2 — ${meta.command_count ?? data.commands.length} команд`),
        h('div', { class: 'muted fs-12' }, `Источник: ${meta.source ?? 'reg.ru/reseller/api2doc'} · снимок ${meta.fetched_at ? new Date(meta.fetched_at).toLocaleDateString('ru-RU') : ''}`),
      ),
      searchField('Поиск по команде или назначению', (value) => renderList(value)),
    ),
    h('div', { class: 'notice info mb-16' }, iconEl('info'),
      h('div', { class: 'fs-12' }, 'Каталог — справочник всех документированных команд. Типизированные операции доступны на страницах «Домены», «DNS», «Хостинги», «Биллинг»; остальные можно вызвать через Raw-консоль или API с правом regru:raw.'),
    ),
    listing,
  ));

  renderList();
}

function renderConsole(container) {
  const command = h('input', {
    type: 'text',
    placeholder: 'zone/get_resource_records',
    list: 'regru-command-list',
    'aria-label': 'Команда REG.RU API',
  });
  const datalist = h('datalist', { id: 'regru-command-list' });
  if (catalogCache?.commands) {
    for (const item of catalogCache.commands) datalist.appendChild(h('option', { value: item.command }, item.purpose ?? ''));
  }
  const input = h('textarea', { rows: 8, placeholder: '{"domain_name":"example.com"}' });
  input.value = '{}';
  const result = h('pre', { class: 'code', style: { display: 'none' } });

  const run = btn('Выполнить запрос', {
    iconName: 'terminal',
    onClick: async () => {
      let payload;
      try {
        payload = JSON.parse(input.value || '{}');
      } catch {
        toast('Входные данные должны быть корректным JSON', 'bad');
        return;
      }
      run.disabled = true;
      try {
        const response = await api('regru/call', { method: 'POST', body: { command: command.value.trim(), input: payload } });
        result.style.display = 'block';
        result.textContent = JSON.stringify(response.raw ?? response, null, 2);
        toast('Запрос выполнен', 'ok');
      } catch (error) {
        result.style.display = 'block';
        result.textContent = JSON.stringify({ error: error.message, code: error.code, details: error.details }, null, 2);
        toast(error.message, 'bad');
      } finally {
        run.disabled = false;
      }
    },
  });

  container.appendChild(h('div', { class: 'card-body' },
    h('div', { class: 'notice warn mb-16' }, iconEl('alert'),
      h('div', null,
        h('strong', null, 'Осторожно: команды выполняются на реальном аккаунте'),
        h('div', { class: 'fs-12' }, 'Продление, регистрация, удаление и оплата списывают деньги и меняют данные. Каждый вызов пишется в журнал. Для интеграций создайте отдельный токен с правом regru:raw.'),
      ),
    ),
    h('div', { class: 'form-grid' },
      h('div', { class: 'field full' }, h('label', null, 'Команда'), command, datalist),
      h('div', { class: 'field full' }, h('label', null, 'input_data (JSON)'), input),
    ),
    h('div', { class: 'btn-row mt-16' }, run),
    h('div', { class: 'mt-16' }, result),
  ));
}

function renderDocs(container) {
  const base = `${window.location.origin}${apiBase}`;
  const token = 'rg_YOUR_TOKEN';
  const examples = [
    {
      title: 'Инвентарь для агента',
      description: 'Компактный список доменов, хостингов, адресов и доступов (без паролей).',
      curl: `curl -s -H "Authorization: Bearer ${token}" \\\n  "${base}inventory" | jq`,
    },
    {
      title: 'Домены с DNS-статусом',
      description: 'Фильтры: q, state, expiring_in, sort, page, per_page.',
      curl: `curl -s -H "Authorization: Bearer ${token}" \\\n  "${base}domains?expiring_in=30&sort=days_left" | jq`,
    },
    {
      title: 'DNS-записи домена',
      description: 'Только для доменов, делегированных на ns1.reg.ru / ns2.reg.ru.',
      curl: `curl -s -H "Authorization: Bearer ${token}" \\\n  "${base}domains/example.com/dns" | jq '.data.records'`,
    },
    {
      title: 'Пароль из хранилища (нужен scope secrets:read)',
      description: 'Каждое раскрытие попадает в журнал: actor, IP, время.',
      curl: `curl -s -X POST -H "Authorization: Bearer ${token}" \\\n  "${base}credentials/1/reveal" | jq`,
    },
    {
      title: 'Произвольная команда REG.RU (scope regru:raw)',
      description: 'Любая из 116 документированных команд, JSON в поле input.',
      curl: `curl -s -X POST \\\n  -H "Authorization: Bearer ${token}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"command":"user/get_balance","input":{}}' \\\n  "${base}regru/call" | jq`,
    },
  ];

  const body = h('div', { class: 'card-body' });
  body.appendChild(h('div', { class: 'notice info mb-16' }, iconEl('shield'),
    h('div', null,
      h('strong', null, 'Как подключить агента'),
      h('div', { class: 'fs-12' }, 'Создайте токен на вкладке «Токены» с правами read и secrets:read (и regru:raw при необходимости). Передавайте его в заголовке Authorization. Токен не даёт доступа к интерфейсу панели и не зависит от пароля.'),
    ),
  ));

  for (const example of examples) {
    body.appendChild(h('div', { class: 'mb-16' },
      h('div', { class: 'section-head' }, h('h2', null, example.title), h('span', { class: 'spacer' }), copyButton(example.curl)),
      h('p', { class: 'muted fs-13', style: { marginTop: '4px' } }, example.description),
      codeBlock(example.curl),
    ));
  }

  const endpoints = [
    ['GET', '/api/v1/inventory', 'инвентарь без секретов'],
    ['GET', '/api/v1/domains', 'домены с фильтрами'],
    ['GET', '/api/v1/domains/{name}/dns', 'DNS-записи'],
    ['POST', '/api/v1/domains/{name}/dns/records', 'добавить запись (scope dns:write)'],
    ['GET', '/api/v1/hostings', 'хостинги и IP'],
    ['POST', '/api/v1/hostings/{id}/reveal', 'пароль панели (scope secrets:read)'],
    ['GET', '/api/v1/billing', 'баланс'],
    ['POST', '/api/v1/sync', 'синхронизация (scope write)'],
    ['POST', '/api/v1/regru/call', 'любая команда REG.RU (scope regru:raw)'],
    ['GET', '/api/v1/health', 'статус без авторизации'],
  ];

  const table = h('table', null,
    h('thead', null, h('tr', null, h('th', null, 'Метод'), h('th', null, 'Путь'), h('th', null, 'Назначение'))),
    h('tbody', null, ...endpoints.map(([method, path, purpose]) => h('tr', null,
      h('td', null, badge(method, method === 'GET' ? 'info' : 'warn')),
      h('td', null, h('code', { class: 'mono fs-12' }, path)),
      h('td', null, purpose),
    ))),
  );

  body.appendChild(h('div', { class: 'section-head mt-16' }, h('h2', null, 'Основные эндпоинты')));
  body.appendChild(h('div', { class: 'table-wrap' }, table));
  body.appendChild(h('p', { class: 'muted fs-12 mt-16' }, 'Полная спецификация: ', h('a', { href: 'api/v1/openapi.yaml', target: '_blank', rel: 'noopener' }, 'docs/openapi.yaml')));
  container.appendChild(body);
}
