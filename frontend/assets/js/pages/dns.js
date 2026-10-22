import { api } from '../api.js';
import {
  badge, btn, chip, clear, confirmDialog, copyButton, h, iconEl, kv, modal, toast,
} from '../dom.js';
import { enums, recordTypeMeta } from '../store.js';
import { href, navigate } from '../router.js';

export async function render(root, ctx) {
  clear(root);
  const selected = ctx.params.name || ctx.query.domain || '';

  root.appendChild(h('div', { class: 'page-head' },
    h('div', null,
      h('h1', { class: 'page-title' }, 'DNS-зоны'),
      h('p', { class: 'page-sub' }, 'Просмотр и изменение ресурсных записей доменов, делегированных на ns1.reg.ru / ns2.reg.ru.'),
    ),
    h('div', { class: 'page-actions' },
      btn('Синхронизировать DNS', { tone: 'ghost', iconName: 'refresh', onClick: () => ctx.runSync('dns') }),
      btn('К домену', { tone: 'ghost', iconName: 'globe', onClick: () => navigate(selected ? `/domains/${encodeURIComponent(selected)}` : '/domains') }),
    ),
  ));

  const allDomains = await loadDomains();
  const zones = allDomains
    .filter((domain) => domain.dns_managed)
    .map((domain) => ({ dname: domain.dname, records: domain.dns_records ?? 0 }));
  const external = allDomains.filter((domain) => !domain.dns_managed && (domain.nss ?? []).length > 0);
  const selectorCard = h('section', { class: 'card mb-16' }, h('div', { class: 'card-body' },
    h('div', { class: 'inline-form' },
      h('div', { class: 'field', style: { flex: '1 1 320px' } },
        h('label', null, 'Домен с DNS-зоной REG.RU'),
        buildZoneSelect(zones, selected, (value) => navigate(`/dns/${encodeURIComponent(value)}`)),
      ),
      zones.length === 0
        ? h('div', { class: 'notice warn', style: { flex: '1 1 320px' } }, iconEl('alert'),
          h('div', null, 'Нет доменов на DNS REG.RU (ns1.reg.ru / ns2.reg.ru). Домены на NS хостинга управляются в панели ISPmanager.'),
        )
        : h('span', { class: 'muted fs-12' }, `Зон под управлением: ${zones.length}`),
    ),
  ));

  root.appendChild(selectorCard);

  if (!zones.length) {
    root.appendChild(h('div', { class: 'card' }, h('div', { class: 'card-body' },
      h('div', { class: 'empty' }, iconEl('list'), h('strong', null, 'DNS-зоны REG.RU недоступны'),
        h('span', null, 'API REG.RU управляет только зонами на ns1.reg.ru и ns2.reg.ru. Запустите синхронизацию услуг — панель перепроверит делегирование.'),
        btn('Синхронизировать', { iconName: 'refresh', onClick: () => ctx.runSync('all') }),
      ),
    )));
    if (external.length) {
      root.appendChild(externalZonesCard(external));
    }
    return;
  }

  const dname = selected && zones.some((zone) => zone.dname === selected) ? selected : zones[0].dname;

  if (dname !== selected) {
    navigate(`/dns/${encodeURIComponent(dname)}`, { replace: true });
  }

  const container = h('div');
  root.appendChild(container);
  await renderZone(container, dname, ctx);
}

function buildZoneSelect(zones, selected, onChange) {
  const select = h('select', { 'aria-label': 'DNS-зона' });
  for (const zone of zones) {
    select.appendChild(h('option', { value: zone.dname, selected: zone.dname === selected }, `${zone.dname} · записей: ${zone.records}`));
  }
  if (!zones.length) select.appendChild(h('option', null, '— нет зон —'));
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

async function loadDomains() {
  try {
    return await api('domains', { query: { per_page: 200, sort: 'dname' } });
  } catch {
    return [];
  }
}

function externalZonesCard(domains) {
  const list = h('div', { class: 'list' });
  for (const domain of domains.slice(0, 25)) {
    list.appendChild(h('div', { class: 'list-item' },
      h('span', { class: 'icon-box warn' }, iconEl('globe')),
      h('div', { class: 'grow' },
        h('div', { class: 'title mono' }, domain.dname),
        h('div', { class: 'sub' }, (domain.nss ?? []).join(', ')),
      ),
      h('div', { class: 'meta' }, h('a', { class: 'btn ghost sm', href: href(`/domains/${encodeURIComponent(domain.dname)}`) }, 'Домен')),
    ));
  }

  return h('section', { class: 'card mt-16' },
    h('header', { class: 'card-head' },
      h('h2', null, 'Домены на NS хостинга'),
      h('span', { class: 'spacer' }),
      h('span', { class: 'badge' }, String(domains.length)),
    ),
    h('div', { class: 'card-body', style: { paddingBottom: '8px' } },
      h('div', { class: 'notice info' }, iconEl('info'),
        h('div', null, 'Эти домены делегированы на серверы хостинга REG.RU (ns5/ns6.hosting.reg.ru или ns1/ns2.shop.reg.ru). Их зоны ведутся в панели ISPmanager, а не через DNS API.'),
      ),
    ),
    list,
  );
}

async function renderZone(container, dname, ctx) {
  clear(container);
  container.appendChild(h('div', { class: 'skeleton', style: { height: '180px', marginBottom: '14px' } }));
  container.appendChild(h('div', { class: 'skeleton', style: { height: '260px' } }));

  let data;
  try {
    data = await api(`domains/${encodeURIComponent(dname)}/dns`);
  } catch (error) {
    clear(container);
    if (error.status === 409 && error.code === 'DNS_NOT_MANAGED') {
      container.appendChild(h('div', { class: 'notice warn' }, iconEl('alert'),
        h('div', { class: 'grow' },
          h('strong', null, 'Домен не на DNS REG.RU'),
          h('div', null, error.message),
          h('div', { class: 'mt-8' }, h('a', { class: 'btn ghost sm', href: href(`/domains/${encodeURIComponent(dname)}`) }, 'Открыть домен и настроить NS')),
        ),
      ));
      return;
    }
    container.appendChild(h('div', { class: 'notice bad' }, iconEl('alert'), h('div', null, error.message)));
    return;
  }

  const records = data.records ?? [];
  const grouped = records.reduce((acc, record) => {
    (acc[record.rectype] ??= []).push(record);
    return acc;
  }, {});

  clear(container);

  const head = h('div', { class: 'page-head', style: { marginBottom: '12px' } },
    h('div', null,
      h('h2', { class: 'page-title', style: { fontSize: '20px' } }, dname),
      h('p', { class: 'page-sub' },
        `Записей: ${records.length}`,
        data.synced_at ? ` · обновлено ${new Date(data.synced_at).toLocaleString('ru-RU')}` : '',
        ` · источник: ${data.source === 'api' ? 'REG.RU API' : 'кэш'}`,
      ),
    ),
    h('div', { class: 'page-actions' },
      btn('Обновить из REG.RU', { tone: 'ghost', iconName: 'refresh', onClick: async () => { await api(`domains/${encodeURIComponent(dname)}/dns`, { query: { refresh: 1 } }); await renderZone(container, dname, ctx); } }),
      btn('Добавить запись', { iconName: 'plus', onClick: () => recordDialog({ dname, onDone: () => renderZone(container, dname, ctx) }) }),
      ctx.isAdmin ? btn('Очистить зону', { tone: 'danger-ghost', iconName: 'trash', onClick: () => clearZone(dname, () => renderZone(container, dname, ctx)) }) : null,
    ),
  );
  container.appendChild(head);

  // SOA / TTL
  const ttl = fieldInline('TTL зоны', data.soa?.ttl ?? '1d');
  const minimum = fieldInline('Minimum TTL', data.soa?.minimum_ttl ?? '3h');
  const soaCard = h('section', { class: 'card mb-16' }, h('div', { class: 'card-body' },
    h('div', { class: 'inline-form' },
      ttl.wrap,
      minimum.wrap,
      btn('Сохранить SOA', {
        tone: 'soft',
        iconName: 'check',
        onClick: async () => {
          try {
            await api(`domains/${encodeURIComponent(dname)}/dns/soa`, {
              method: 'PUT',
              body: { ttl: ttl.input.value.trim(), minimum_ttl: minimum.input.value.trim() },
            });
            toast('SOA обновлён', 'ok');
            await renderZone(container, dname, ctx);
          } catch (error) {
            toast(error.message, 'bad');
          }
        },
      }),
      h('span', { class: 'muted fs-12' }, 'Формат: 3600 или 1h / 1d'),
    ),
  ));
  container.appendChild(soaCard);
  appendDnssec(container, dname, ctx);

  const card = h('section', { class: 'card' },
    h('header', { class: 'card-head' },
      h('h2', null, 'Ресурсные записи'),
      h('span', { class: 'spacer' }),
      h('span', { class: 'chips' }, ...Object.entries(grouped).map(([type, list]) => chip(`${type}: ${list.length}`, 'accent'))),
    ),
    h('div', { class: 'table-wrap' }, buildRecordsTable(dname, records, () => renderZone(container, dname, ctx))),
  );
  container.appendChild(card);

  if (!records.length) {
    container.appendChild(h('div', { class: 'notice info mt-16' }, iconEl('info'),
      h('div', null, 'Зона пуста. Добавьте хотя бы A-запись для домена и MX/TXT при необходимости.'),
    ));
  }
}

function fieldInline(label, value) {
  const input = h('input', { type: 'text', value, 'aria-label': label });
  return { wrap: h('div', { class: 'field' }, h('label', null, label), input), input };
}

function buildRecordsTable(dname, records, onChanged) {
  const table = h('table');
  table.appendChild(h('thead', null, h('tr', null,
    h('th', null, 'Тип'),
    h('th', null, 'Поддомен'),
    h('th', { class: 'num' }, 'Приор.'),
    h('th', null, 'Содержимое'),
    h('th', { class: 'actions' }, 'Действия'),
  )));
  const tbody = h('tbody');

  if (!records.length) {
    tbody.appendChild(h('tr', null, h('td', { colspan: '5' },
      h('div', { class: 'empty' }, iconEl('list'), h('strong', null, 'Записей нет'), h('span', null, 'Добавьте первую запись кнопкой выше.')),
    )));
  }

  for (const record of records) {
    const meta = recordTypeMeta(record.rectype);
    tbody.appendChild(h('tr', null,
      h('td', null, badge(record.rectype, record.rectype === 'A' || record.rectype === 'AAAA' ? 'info' : '')),
      h('td', null, h('span', { class: 'mono' }, record.subname === '@' ? '@ (домен)' : record.subname)),
      h('td', { class: 'num' }, meta?.supports_priority ? String(record.prio) : '—'),
      h('td', null, h('div', { class: 'copyable' },
        h('code', { class: 'mono truncate', title: record.content }, record.content),
        copyButton(record.content),
      )),
      h('td', { class: 'actions' },
        btn('', { tone: 'ghost', size: 'sm', iconName: 'pencil', title: 'Изменить', onClick: () => recordDialog({ dname, record, onDone: onChanged }) }),
        btn('', { tone: 'danger-ghost', size: 'sm', iconName: 'trash', title: 'Удалить', onClick: () => deleteRecord(dname, record, onChanged) }),
      ),
    ));
  }

  table.appendChild(tbody);
  return table;
}

function recordDialog({ dname, record = null, onDone }) {
  const types = enums().record_types ?? [];
  const typeField = h('select', { 'aria-label': 'Тип записи' });
  for (const type of types) {
    typeField.appendChild(h('option', { value: type.value, selected: (record?.rectype ?? 'A') === type.value }, type.value));
  }

  const subField = h('input', { type: 'text', value: record?.subname ?? '@', placeholder: '@ или www' });
  const contentField = h('input', { type: 'text', value: record?.content ?? '', placeholder: '' });
  const hostField = h('input', { type: 'text', value: '', placeholder: 'IPv4 или IPv6' });
  const prioField = h('input', { type: 'number', value: record?.prio ?? 0, min: 0, max: 65535, placeholder: '10' });
  const prioWrap = h('div', { class: 'field' }, h('label', null, 'Приоритет'), prioField);
  const contentLabel = h('label', null, 'Содержимое');

  const syncType = () => {
    const meta = recordTypeMeta(typeField.value) ?? { content_label: 'Содержимое', placeholder: '', supports_priority: false };
    contentLabel.textContent = meta.content_label;
    contentField.placeholder = meta.placeholder ?? '';
    prioWrap.hidden = !meta.supports_priority;
  };
  syncType();
  typeField.addEventListener('change', syncType);

  const body = h('div', { class: 'form-grid' },
    h('div', { class: 'field' }, h('label', null, 'Тип записи'), typeField),
    h('div', { class: 'field' }, h('label', null, 'Поддомен'), subField, h('div', { class: 'hint' }, '@ — сам домен, * — все поддомены, www — конкретный')),
    h('div', { class: 'field full' }, contentLabel, contentField),
    prioWrap,
  );

  const dialog = modal({
    title: record ? 'Изменить запись' : 'Новая запись',
    body,
    footer: [
      btn('Отмена', { tone: 'ghost', onClick: () => dialog.close() }),
      btn(record ? 'Сохранить' : 'Добавить', {
        iconName: 'check',
        onClick: async () => {
          const bodyData = { subname: subField.value.trim(), rectype: typeField.value, content: contentField.value.trim(), prio: Number(prioField.value) || 0 };
          try {
            if (record) {
              await api(`domains/${encodeURIComponent(dname)}/dns/records`, { method: 'PUT', body: { old: record, ...bodyData } });
              toast('Запись изменена', 'ok');
            } else {
              await api(`domains/${encodeURIComponent(dname)}/dns/records`, { method: 'POST', body: bodyData });
              toast('Запись добавлена', 'ok');
            }
            dialog.close();
            onDone?.();
          } catch (error) {
            toast(error.fieldErrors ? Object.values(error.fieldErrors).join(' ') : error.message, 'bad');
          }
        },
      }),
    ],
  });
}

async function deleteRecord(dname, record, onChanged) {
  const confirmed = await confirmDialog({
    title: 'Удалить запись?',
    message: `${record.rectype} ${record.subname} → ${record.content}`,
    confirmLabel: 'Удалить',
    danger: true,
  });
  if (!confirmed) return;

  try {
    await api(`domains/${encodeURIComponent(dname)}/dns/records`, {
      method: 'DELETE',
      body: { record: { subname: record.subname, rectype: record.rectype, content: record.content, prio: record.prio } },
    });
    toast('Запись удалена', 'ok');
    onChanged?.();
  } catch (error) {
    toast(error.message, 'bad');
  }
}

async function clearZone(dname, onChanged) {
  const confirmed = await confirmDialog({
    title: `Очистить зону ${dname}?`,
    message: 'Будут удалены ВСЕ ресурсные записи домена. Домен перестанет открываться, почта перестанет работать.',
    confirmLabel: 'Очистить зону',
    danger: true,
    requireText: 'DELETE',
  });
  if (!confirmed) return;

  try {
    await api(`domains/${encodeURIComponent(dname)}/dns/clear`, { method: 'POST', body: { confirm: 'DELETE' } });
    toast('Зона очищена', 'warn');
    onChanged?.();
  } catch (error) {
    toast(error.message, 'bad');
  }
}

function appendDnssec(container, dname, ctx) {
  const box = h('section', { class: 'card mb-16' }, h('div', { class: 'card-body' },
    h('div', { class: 'skeleton', style: { height: '48px' } }),
  ));
  container.appendChild(box);

  const renderStatus = (payload) => {
    const raw = payload?.status ?? {};
    const entries = Object.entries(raw)
      .filter(([, value]) => value !== null && value !== undefined && typeof value !== 'object')
      .slice(0, 8)
      .map(([key, value]) => [key, String(value)]);
    const enabled = entries.some(([key, value]) => /enabled|active|on/i.test(key) && /^(1|true|yes|да)$/i.test(value));

    clear(box);
    box.appendChild(h('div', { class: 'card-body' },
      h('div', { class: 'flex items-center gap-12' },
        h('span', { class: 'icon-box' }, iconEl('shield')),
        h('div', { class: 'grow' },
          h('div', { class: 'row-main' }, 'DNSSEC'),
          h('div', { class: 'row-sub' }, enabled ? 'подписи включены' : 'состояние: см. данные ниже'),
        ),
        ctx.isAdmin
          ? btn(enabled ? 'Выключить' : 'Включить', {
            tone: enabled ? 'danger-ghost' : 'soft',
            size: 'sm',
            onClick: async () => {
              try {
                await api(`domains/${encodeURIComponent(dname)}/dnssec`, { method: 'POST', body: { enabled: !enabled } });
                toast(`DNSSEC ${!enabled ? 'включён' : 'выключен'}`, 'ok');
                await load();
              } catch (error) {
                toast(error.message, 'bad');
              }
            },
          })
          : null,
      ),
      entries.length
        ? h('div', { class: 'mt-16' }, kv(entries))
        : h('div', { class: 'muted fs-12 mt-8' }, 'REG.RU не вернул дополнительных полей статуса.'),
    ));
  };

  async function load() {
    try {
      renderStatus(await api(`domains/${encodeURIComponent(dname)}/dnssec`));
    } catch (error) {
      clear(box);
      box.appendChild(h('div', { class: 'card-body' },
        h('div', { class: 'notice warn' }, iconEl('alert'), h('div', null, `DNSSEC: ${error.message}`)),
      ));
    }
  }

  load();
}