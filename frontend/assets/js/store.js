import { api, setCsrf } from './api.js';

const THEME_KEY = 'regru-control-theme';

export const state = {
  bootstrap: null,
  theme: localStorage.getItem(THEME_KEY) || 'dark',
  sidebarOpen: false,
  syncRunning: false,
};

export async function boot() {
  const data = await api('bootstrap');
  setCsrf(data.csrf_token);
  state.bootstrap = data;
  applyTheme(state.theme);
  return data;
}

export async function refreshBootstrap() {
  return boot();
}

export function applyTheme(theme) {
  state.theme = theme === 'light' ? 'light' : 'dark';
  document.documentElement.dataset.theme = state.theme;
  localStorage.setItem(THEME_KEY, state.theme);
}

export function toggleTheme() {
  applyTheme(state.theme === 'dark' ? 'light' : 'dark');
  return state.theme;
}

export function enums() {
  return state.bootstrap?.enums ?? {};
}

export function isAuthenticated() {
  return Boolean(state.bootstrap?.authenticated);
}

export function user() {
  return state.bootstrap?.user ?? null;
}

export function isAdmin() {
  return Boolean(state.bootstrap?.is_admin);
}

export function appInfo() {
  return state.bootstrap?.app ?? {};
}

export function account() {
  return state.bootstrap?.account ?? null;
}

function findEnum(list, value) {
  return (list ?? []).find((item) => item.value === value) ?? null;
}

export function serviceTypeMeta(value) {
  return findEnum(enums().service_types, value);
}

export function serviceStateMeta(value) {
  return findEnum(enums().service_states, value);
}

export function recordTypeMeta(value) {
  return findEnum(enums().record_types, String(value ?? '').toUpperCase());
}

export function credentialKindMeta(value) {
  return findEnum(enums().credential_kinds, value);
}

export function scopeMeta(value) {
  return findEnum(enums().token_scopes, value);
}

export function syncScopeMeta(value) {
  return findEnum(enums().sync_scopes, value);
}

export function categoryLabel(value) {
  return findEnum(enums().service_categories, value)?.label ?? value;
}

export function toneForState(stateValue) {
  return serviceStateMeta(stateValue)?.tone ?? 'muted';
}
