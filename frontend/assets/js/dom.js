import { icon } from './icons.js';
import { formatNumber, formatDate, formatDateTime, localeTag } from './i18n.js';

/**
 * Tiny DOM builder. Strings always become text nodes, so API data is never
 * interpreted as HTML.
 */
export function h(tag, props, ...children) {
  const el = document.createElement(tag);

  for (const [key, value] of Object.entries(props ?? {})) {
    if (value === null || value === undefined || value === false) continue;

    if (key === 'class') el.className = value;
    else if (key === 'dataset') Object.assign(el.dataset, value);
    else if (key === 'style' && typeof value === 'object') Object.assign(el.style, value);
    else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key === 'value' || key === 'checked' || key === 'disabled' || key === 'selected' || key === 'readOnly' || key === 'required') el[key] = value;
    else if (key === 'text') el.textContent = String(value);
    else el.setAttribute(key, String(value));
  }

  append(el, children);
  return el;
}

export function append(parent, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    if (child instanceof Node) parent.appendChild(child);
    else parent.appendChild(document.createTextNode(String(child)));
  }
  return parent;
}

export function frag(...children) {
  return append(document.createDocumentFragment(), children);
}

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

export function iconEl(name, cls) {
  return icon(name, cls);
}

export function btn(label, { tone = 'primary', iconName, onClick, title, disabled = false, type = 'button', size } = {}) {
  const classes = ['btn'];
  if (tone !== 'primary') classes.push(tone);
  if (size) classes.push(size);
  if (!label) classes.push('icon');

  const el = h('button', { class: classes.join(' '), type, title: title ?? label, disabled, onClick });
  if (iconName) el.appendChild(iconEl(iconName));
  if (label) el.appendChild(document.createTextNode(label));
  return el;
}

export function linkBtn(label, href, { iconName, external = false } = {}) {
  const el = h('a', {
    class: 'btn ghost sm',
    href,
    target: external ? '_blank' : null,
    rel: external ? 'noopener noreferrer' : null,
  });
  if (iconName) el.appendChild(iconEl(iconName));
  el.appendChild(document.createTextNode(label));
  return el;
}

export function badge(text, tone = '') {
  return h('span', { class: `badge ${tone}` }, text);
}

export function chip(text, tone = '') {
  return h('span', { class: `chip ${tone}` }, text);
}

export function dot(tone = '') {
  return h('span', { class: `dot ${tone}`, 'aria-hidden': 'true' });
}

export function statusBadge(label, tone) {
  return h('span', { class: `badge ${tone}` }, dot(tone), label);
}

export function card(title, body, { actions = [], subtitle = null, className = '' } = {}) {
  const el = h('section', { class: `card ${className}`.trim() });
  const head = h('header', { class: 'card-head' }, h('h2', null, title));
  if (subtitle) head.appendChild(h('small', null, subtitle));
  head.appendChild(h('span', { class: 'spacer' }));
  for (const action of actions) head.appendChild(action);
  el.appendChild(head);
  el.appendChild(h('div', { class: 'card-body' }, body));
  return el;
}

export function statCard({ label, value, hint, iconName, tone = '' }) {
  return h('div', { class: `card ${tone}` },
    h('div', { class: 'stat' },
      h('div', { class: 'stat-label' }, iconName ? iconEl(iconName) : null, label),
      h('div', { class: 'stat-value' }, value),
      hint ? h('div', { class: 'stat-hint' }, hint) : null,
    ),
  );
}

export function emptyState(title, text, { iconName = 'info', action = null } = {}) {
  return h('div', { class: 'empty' },
    iconEl(iconName),
    h('strong', null, title),
    text ? h('span', null, text) : null,
    action,
  );
}

export function skeletonTable(rows = 5, cols = 4) {
  const body = h('div', { class: 'card-body' });
  for (let i = 0; i < rows; i++) {
    const row = h('div', { class: 'flex gap-12', style: { marginBottom: '12px' } });
    for (let c = 0; c < cols; c++) {
      row.appendChild(h('div', {
        class: 'skeleton',
        style: { flex: c === 0 ? '2' : '1', height: '16px' },
      }));
    }
    body.appendChild(row);
  }
  return h('div', { class: 'card' }, body);
}

export function kv(entries) {
  const dl = h('dl', { class: 'kv' });
  for (const [key, value] of entries) {
    if (value === null || value === undefined) continue;
    dl.appendChild(h('dt', null, key));
    dl.appendChild(h('dd', null, value instanceof Node ? value : String(value)));
  }
  return dl;
}

export function field({ label, name, type = 'text', value = '', placeholder = '', hint = '', required = false, options = null, rows = 4, autocomplete = 'off', min, max, step }) {
  const id = `f-${name}-${Math.random().toString(36).slice(2, 7)}`;
  const wrap = h('div', { class: 'field' });
  const labelEl = h('label', { for: id }, label, required ? h('span', { class: 'text-bad' }, ' *') : null);
  wrap.appendChild(labelEl);

  let input;
  if (options) {
    input = h('select', { id, name });
    for (const option of options) {
      const opt = typeof option === 'string' ? { value: option, label: option } : option;
      input.appendChild(h('option', { value: opt.value, selected: String(opt.value) === String(value) }, opt.label));
    }
  } else if (type === 'textarea') {
    input = h('textarea', { id, name, placeholder, rows, required });
    input.value = value ?? '';
  } else {
    input = h('input', { id, name, type, value: value ?? '', placeholder, required, autocomplete, min, max, step });
  }

  wrap.appendChild(input);
  const hintEl = h('div', { class: 'hint' }, hint ?? '');
  hintEl.hidden = !hint;
  wrap.appendChild(hintEl);
  const error = h('div', { class: 'err', hidden: true });
  wrap.appendChild(error);

  return {
    wrap,
    input,
    name,
    hint: hintEl,
    setHint(text) { hintEl.textContent = text ?? ''; hintEl.hidden = !text; },
    setError(message) { error.textContent = message; error.hidden = !message; wrap.classList.toggle('invalid', !!message); },
  };
}

export function formGrid(fields, { full = [] } = {}) {
  const grid = h('div', { class: 'form-grid' });
  for (const item of fields) {
    const wrap = item.wrap ?? item;
    if (full.includes(item.name)) wrap.classList.add('full');
    grid.appendChild(wrap);
  }
  return grid;
}

export function formActions(...buttons) {
  return h('div', { class: 'form-actions' }, buttons);
}

export function toolbar(...children) {
  return h('div', { class: 'toolbar' }, children);
}

export function searchField(placeholder, onInput, { value = '' } = {}) {
  const input = h('input', { type: 'search', placeholder, value, 'aria-label': placeholder });
  let timer = null;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => onInput(input.value.trim()), 220);
  });
  return h('div', { class: 'search-field' }, iconEl('search'), input);
}

export function copyButton(text, { label = '' } = {}) {
  const el = h('button', { class: 'copy-btn', type: 'button', title: 'Скопировать', 'aria-label': 'Скопировать' }, iconEl('copy'));
  if (label) el.appendChild(document.createTextNode(label));
  el.addEventListener('click', async (event) => {
    event.stopPropagation();
    try {
      await navigator.clipboard.writeText(String(text));
      el.classList.add('text-ok');
      setTimeout(() => el.classList.remove('text-ok'), 900);
    } catch {
      window.prompt('Скопируйте значение:', String(text));
    }
  });
  return el;
}

export function codeBlock(text) {
  return h('pre', { class: 'code' }, text);
}

export function tabs(items, { active = 0, onChange } = {}) {
  const el = h('div', { class: 'tabs', role: 'tablist' });
  items.forEach((item, index) => {
    const button = h('button', {
      class: `tab ${index === active ? 'active' : ''}`,
      type: 'button',
      role: 'tab',
      'aria-selected': index === active ? 'true' : 'false',
      onClick: () => {
        el.querySelectorAll('.tab').forEach((tab, i) => {
          tab.classList.toggle('active', i === index);
          tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });
        onChange?.(index);
      },
    }, item.label);
    el.appendChild(button);
  });
  return el;
}

export function pagination({ page, pages, total, perPage, onPage }) {
  const el = h('div', { class: 'pagination' });
  el.appendChild(h('span', { class: 'page-info' }, `Всего: ${total} · страница ${page} из ${Math.max(1, pages)}`));
  el.appendChild(h('span', { class: 'spacer' }));
  el.appendChild(btn('Назад', { tone: 'ghost', size: 'sm', disabled: page <= 1, onClick: () => onPage(page - 1) }));
  el.appendChild(btn('Вперёд', { tone: 'ghost', size: 'sm', disabled: page >= pages, onClick: () => onPage(page + 1) }));
  return el;
}

/** Modal with focus trap, Esc close and backdrop click. */
export function modal({ title, body, footer = [], wide = false, onClose, dismissible = true }) {
  const root = document.getElementById('overlay-root');
  const backdrop = h('div', { class: 'modal-backdrop', role: 'presentation' });
  const dialog = h('div', { class: `modal ${wide ? 'wide' : ''}`, role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
  const head = h('div', { class: 'modal-head' }, h('h2', null, title), h('span', { class: 'spacer' }));
  const close = () => {
    backdrop.remove();
    document.removeEventListener('keydown', onKey);
    onClose?.();
  };
  if (dismissible) {
    head.appendChild(h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Закрыть', onClick: close }, iconEl('x')));
  }
  dialog.appendChild(head);
  dialog.appendChild(h('div', { class: 'modal-body' }, body));
  if (footer.length) dialog.appendChild(h('div', { class: 'modal-foot' }, footer));
  backdrop.appendChild(dialog);
  backdrop.addEventListener('mousedown', (event) => { if (event.target === backdrop && dismissible) close(); });

  const onKey = (event) => {
    if (event.key === 'Escape' && dismissible) close();
    if (event.key === 'Tab') {
      const focusable = [...dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
        .filter((node) => !node.disabled && node.offsetParent !== null);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  };
  document.addEventListener('keydown', onKey);
  root.appendChild(backdrop);
  const firstInput = dialog.querySelector('input, select, textarea, button');
  firstInput?.focus();

  return { close, dialog, body: dialog.querySelector('.modal-body') };
}

/** Confirmation dialog; with `requireText` the user must type it verbatim. */
export function confirmDialog({ title, message, confirmLabel = 'Подтвердить', danger = false, requireText = null }) {
  return new Promise((resolve) => {
    const typed = requireText ? field({ label: `Введите «${requireText}» для подтверждения`, name: 'confirm', placeholder: requireText }) : null;
    const body = h('div', { class: 'flex', style: { flexDirection: 'column', gap: '12px' } },
      h('p', { style: { margin: 0 } }, message),
      typed?.wrap,
    );
    const okBtn = btn(confirmLabel, {
      tone: danger ? 'danger' : 'primary',
      disabled: !!requireText,
      onClick: () => { instance.close(); resolve(true); },
    });
    if (typed) {
      typed.input.addEventListener('input', () => { okBtn.disabled = typed.input.value !== requireText; });
    }
    const instance = modal({
      title,
      body,
      footer: [btn('Отмена', { tone: 'ghost', onClick: () => { instance.close(); resolve(false); } }), okBtn],
      onClose: () => resolve(false),
    });
  });
}

export function drawer({ title, body, footer = [], onClose }) {
  const root = document.getElementById('overlay-root');
  const backdrop = h('div', { class: 'drawer-backdrop' });
  const panel = h('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
  const close = () => {
    backdrop.remove();
    panel.remove();
    document.removeEventListener('keydown', onKey);
    onClose?.();
  };
  const onKey = (event) => { if (event.key === 'Escape') close(); };
  panel.appendChild(h('div', { class: 'drawer-head' },
    h('h2', null, title),
    h('span', { class: 'spacer' }),
    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Закрыть', onClick: close }, iconEl('x')),
  ));
  panel.appendChild(h('div', { class: 'drawer-body' }, body));
  if (footer.length) panel.appendChild(h('div', { class: 'drawer-foot' }, footer));
  backdrop.addEventListener('mousedown', close);
  document.addEventListener('keydown', onKey);
  root.append(backdrop, panel);
  return { close, panel };
}

/** Toast notification. */
export function toast(message, tone = 'ok', timeout = 4200) {
  const root = document.getElementById('toasts');
  const iconName = tone === 'bad' ? 'alert' : tone === 'warn' ? 'alert' : 'check';
  const el = h('div', { class: `toast ${tone}`, role: 'status' }, iconEl(iconName), h('div', { class: 'grow' }, message),
    h('button', { type: 'button', 'aria-label': 'Закрыть', onClick: () => el.remove() }, iconEl('x')));
  root.appendChild(el);
  if (timeout > 0) setTimeout(() => el.remove(), timeout);
  return el;
}

// --- formatters -------------------------------------------------------------

export function money(value, currency = 'RUR') {
  const code = currency === 'RUR' ? 'RUB' : currency;

  try {
    return new Intl.NumberFormat(localeTag(), {
      style: 'currency',
      currency: code,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Number(value ?? 0));
  } catch {
    return `${formatNumber(value ?? 0)} ${currency}`;
  }
}

export function num(value) {
  return formatNumber(value ?? 0);
}

export function fmtDate(value) {
  return formatDate(value);
}

export function fmtDateTime(value) {
  return formatDateTime(value);
}

export function daysLabel(days) {
  if (days === null || days === undefined) return { text: '—', tone: '' };
  if (days < 0) return { text: `${Math.abs(days)} дн. назад`, tone: 'bad' };
  if (days === 0) return { text: 'сегодня', tone: 'bad' };
  if (days <= 14) return { text: `${days} дн.`, tone: 'bad' };
  if (days <= 45) return { text: `${days} дн.`, tone: 'warn' };
  return { text: `${days} дн.`, tone: '' };
}

export function plural(count, one, few, many) {
  const n = Math.abs(count) % 100;
  const n1 = n % 10;
  if (n > 10 && n < 20) return many;
  if (n1 > 1 && n1 < 5) return few;
  if (n1 === 1) return one;
  return many;
}
