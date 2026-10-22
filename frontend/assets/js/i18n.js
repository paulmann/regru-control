/**
 * i18n runtime.
 *
 * The Russian UI string is the message id (gettext-style): existing templates
 * need no manual key wrapping. Catalogs map Russian → target language and are
 * loaded lazily; English is the default and the fallback for missing entries.
 *
 * `{p}` in a message id is a placeholder (it replaced `${expr}` in the source).
 * Catalogs keep the same placeholder, and matching text is interpolated back.
 */

import { api } from './api.js';

const STORAGE_KEY = 'rg-locale';
const DEFAULT_LOCALE = 'en';

export const LOCALES = [
  { code: 'en', label: 'English', native: 'English' },
  { code: 'ru', label: 'Russian', native: 'Русский' },
  { code: 'zh', label: 'Chinese', native: '中文' },
  { code: 'hi', label: 'Hindi', native: 'हिन्दी' },
  { code: 'de', label: 'German', native: 'Deutsch' },
  { code: 'es', label: 'Spanish', native: 'Español' },
];

export const LOCALE_TAGS = { en: 'en', ru: 'ru', zh: 'zh-CN', hi: 'hi', de: 'de', es: 'es' };

let locale = DEFAULT_LOCALE;
const catalogs = {};
const patterns = {};
const cache = new Map();
let observer = null;
let applying = false;

function normalize(tag) {
  const base = String(tag ?? '').toLowerCase().replace('_', '-').split('-')[0];
  if (base === 'cn') return 'zh';
  return LOCALES.some((item) => item.code === base) ? base : null;
}

export function currentLocale() {
  return locale;
}

export function localeTag() {
  return LOCALE_TAGS[locale] ?? 'en';
}

export function localeOptions() {
  return LOCALES;
}

export function detectLocale(serverLocale) {
  const stored = normalize(localStorage.getItem(STORAGE_KEY));
  if (stored) return stored;

  const fromServer = normalize(serverLocale);
  if (fromServer) return fromServer;

  for (const candidate of navigator.languages ?? [navigator.language]) {
    const detected = normalize(candidate);
    if (detected) return detected;
  }

  return DEFAULT_LOCALE;
}

async function loadCatalog(code) {
  if (code === 'ru') {
    catalogs.ru = {};
    return {};
  }

  if (catalogs[code]) return catalogs[code];

  const url = new URL(`assets/js/locales/${code}.json`, document.baseURI);

  try {
    const response = await fetch(url, { credentials: 'same-origin' });
    catalogs[code] = response.ok ? await response.json() : {};
  } catch {
    catalogs[code] = {};
  }

  buildPatterns(code);
  return catalogs[code];
}

function buildPatterns(code) {
  const catalog = catalogs[code] ?? {};
  patterns[code] = Object.entries(catalog)
    .filter(([key]) => key.includes('{p}'))
    .map(([key, value]) => ({
      regex: new RegExp(`^${escapeRegex(key).replace(/\\\{p\\\}/g, '(.+?)')}$`),
      value,
    }));
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export async function initI18n(serverLocale) {
  locale = detectLocale(serverLocale);
  await loadCatalog('en');
  if (locale !== 'en') await loadCatalog(locale);
  document.documentElement.lang = localeTag();
  startDomTranslation();
  return locale;
}

/** Translate a single string (UI templates may call it explicitly). */
export function t(text, params = null) {
  if (typeof text !== 'string' || text === '') return text;
  if (locale === 'ru') return interpolate(text, params);

  const cacheKey = `${locale}\u0000${text}`;
  const cached = cache.get(cacheKey);
  if (cached !== undefined) return interpolate(cached, params);

  const catalog = catalogs[locale] ?? {};
  let translated = catalog[text];

  if (translated === undefined) {
    for (const pattern of patterns[locale] ?? []) {
      const match = pattern.regex.exec(text);
      if (!match) continue;
      let index = 0;
      translated = pattern.value.replace(/\{p\}/g, () => match[++index] ?? '');
      break;
    }
  }

  if (translated === undefined) {
    translated = catalogs.en?.[text];
  }

  if (translated === undefined) {
    for (const pattern of patterns.en ?? []) {
      const match = pattern.regex.exec(text);
      if (!match) continue;
      let index = 0;
      translated = pattern.value.replace(/\{p\}/g, () => match[++index] ?? '');
      break;
    }
  }

  if (translated === undefined) translated = text;

  cache.set(cacheKey, translated);
  return interpolate(translated, params);
}

function interpolate(text, params) {
  if (!params || typeof params !== 'object') return text;
  let result = text;
  for (const [key, value] of Object.entries(params)) {
    result = result.split(`{${key}}`).join(String(value));
  }
  return result;
}

const TRANSLATABLE_ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt'];
const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'CODE', 'PRE']);

function translateTextNode(node) {
  const original = node.nodeValue;
  if (!original || original.trim() === '') return;

  const trimmed = original.trim();
  const translated = t(trimmed);
  if (translated === trimmed) return;

  applying = true;
  const leading = original.slice(0, original.indexOf(trimmed));
  const trailing = original.slice(original.indexOf(trimmed) + trimmed.length);
  node.nodeValue = leading + translated + trailing;
  applying = false;
}

function translateElement(element) {
  for (const attribute of TRANSLATABLE_ATTRIBUTES) {
    if (!element.hasAttribute(attribute)) continue;
    const original = element.getAttribute(attribute);
    if (!original) continue;
    const translated = t(original);
    if (translated !== original) element.setAttribute(attribute, translated);
  }
}

export function translateDom(root = document.body) {
  if (!root || locale === 'ru') return;

  if (root.nodeType === Node.TEXT_NODE) {
    if (!SKIP_TAGS.has(root.parentElement?.tagName)) translateTextNode(root);
    return;
  }

  if (root.nodeType === Node.ELEMENT_NODE) translateElement(root);

  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
    acceptNode(node) {
      if (node.nodeType === Node.ELEMENT_NODE && SKIP_TAGS.has(node.tagName)) {
        return NodeFilter.FILTER_REJECT;
      }
      return NodeFilter.FILTER_ACCEPT;
    },
  });

  let node = walker.nextNode();
  while (node) {
    if (node.nodeType === Node.TEXT_NODE) translateTextNode(node);
    else translateElement(node);
    node = walker.nextNode();
  }
}

/** Observe dynamically added nodes (page fragments, modals, toasts). */
export function startDomTranslation() {
  if (observer || typeof MutationObserver === 'undefined') return;

  const queue = new Set();
  let scheduled = false;

  observer = new MutationObserver((mutations) => {
    if (applying) return;
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) queue.add(node);
      }
    }

    if (scheduled || queue.size === 0) return;
    scheduled = true;

    queueMicrotask(() => {
      scheduled = false;
      const nodes = [...queue];
      queue.clear();
      for (const node of nodes) {
        if (node.isConnected) translateDom(node);
      }
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });
}

export async function setLocale(code, { notifyServer = true } = {}) {
  const next = normalize(code) ?? DEFAULT_LOCALE;
  if (next === locale) return locale;

  locale = next;
  localStorage.setItem(STORAGE_KEY, next);
  await loadCatalog('en');
  if (next !== 'en') await loadCatalog(next);
  document.documentElement.lang = localeTag();
  cache.clear();

  if (notifyServer) {
    try {
      await api('locale', { method: 'POST', body: { locale: next } });
    } catch {
      // The choice is already stored locally; the cookie is a nice-to-have.
    }
  }

  window.dispatchEvent(new CustomEvent('rg:locale', { detail: next }));
  return next;
}

// --- locale-aware formatting ------------------------------------------------

export function formatNumber(value) {
  return Number(value ?? 0).toLocaleString(localeTag());
}

export function formatDate(value) {
  if (!value) return '—';
  const date = new Date(String(value).length <= 10 ? `${value}T00:00:00` : value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString(localeTag(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function formatDateTime(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString(localeTag(), {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}
