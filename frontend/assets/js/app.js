import { ApiError, api, onUnauthorized } from './api.js';
import {
  boot, isAdmin, isAuthenticated, refreshBootstrap, state, toggleTheme, user, appInfo,
} from './store.js';
import {
  currentLocale, initI18n, LOCALES, localeTag, setLocale, t, translateDom,
} from './i18n.js';
import { btn, clear, h, iconEl, toast } from './dom.js';
import { href, navigate, startRouter } from './router.js';
import * as dashboard from './pages/dashboard.js';
import * as domains from './pages/domains.js';
import * as dns from './pages/dns.js';
import * as hostings from './pages/hostings.js';
import * as credentials from './pages/credentials.js';
import * as billing from './pages/billing.js';
import * as syncPage from './pages/sync.js';
import * as apiPage from './pages/api.js';
import * as settings from './pages/settings.js';
import * as audit from './pages/audit.js';
import * as loginPage from './pages/login.js';

const NAV = [
  { group: 'Панель', items: [
    { path: '/', label: 'Обзор', icon: 'grid' },
  ] },
  { group: 'Инвентарь', items: [
    { path: '/domains', label: 'Домены', icon: 'globe' },
    { path: '/dns', label: 'DNS-зоны', icon: 'list' },
    { path: '/hostings', label: 'Хостинги', icon: 'server' },
    { path: '/credentials', label: 'Доступы', icon: 'key' },
  ] },
  { group: 'Аккаунт', items: [
    { path: '/billing', label: 'Биллинг', icon: 'invoice' },
    { path: '/sync', label: 'Синхронизация', icon: 'refresh' },
  ] },
  { group: 'Система', items: [
    { path: '/api', label: 'API REG.RU', icon: 'terminal' },
    { path: '/settings', label: 'Настройки', icon: 'settings' },
    { path: '/audit', label: 'Журнал', icon: 'activity' },
  ] },
];

const ROUTES = [
  { path: '/', page: dashboard, title: 'Обзор', nav: '/' },
  { path: '/domains', page: domains, title: 'Домены', nav: '/domains' },
  { path: '/domains/:name', page: domains, title: 'Домен', nav: '/domains' },
  { path: '/dns', page: dns, title: 'DNS-зоны', nav: '/dns' },
  { path: '/dns/:name', page: dns, title: 'DNS-зона', nav: '/dns' },
  { path: '/hostings', page: hostings, title: 'Хостинги и серверы', nav: '/hostings' },
  { path: '/credentials', page: credentials, title: 'Доступы', nav: '/credentials' },
  { path: '/billing', page: billing, title: 'Биллинг', nav: '/billing' },
  { path: '/sync', page: syncPage, title: 'Синхронизация', nav: '/sync' },
  { path: '/api', page: apiPage, title: 'API REG.RU', nav: '/api' },
  { path: '/settings', page: settings, title: 'Настройки', nav: '/settings' },
  { path: '/audit', page: audit, title: 'Журнал действий', nav: '/audit' },
];

const els = {};
let currentRoute = null;
let routerStarted = false;

async function main() {
  try {
    await boot();
  } catch (error) {
    renderFatal(error);
    return;
  }

  await initI18n(state.bootstrap.locale);
  translateDom(document.body);

  onUnauthorized(() => {
    // Session expired or revoked: a reload brings the login screen back.
    window.location.reload();
  });

  if (!state.bootstrap.authenticated) {
    renderLogin();
    return;
  }

  renderShell();
  startRouterOnce();

  window.addEventListener('rg:locale', () => {
    if (!isAuthenticated()) return;
    renderShell();
    if (currentRoute) dispatch(currentRoute);
  });
}

function renderLogin() {
  const root = document.getElementById('app');

  loginPage.render(root, {
    onLogin: async () => {
      await refreshBootstrap();
      window.location.reload();
    },
    themeToggle: toggleTheme,
    theme: state.theme,
    rerender: (ctx) => {
      loginPage.render(root, ctx);
      translateDom(root);
    },
  });

  translateDom(root);
}

function renderShell() {
  const app = document.getElementById('app');
  clear(app);
  app.className = 'app';

  const sidebar = buildSidebar();
  const overlay = h('div', { class: 'sidebar-overlay hidden', onClick: closeSidebar });

  const topbar = h('header', { class: 'topbar' },
    h('button', { class: 'icon-btn hamburger', type: 'button', 'aria-label': 'Меню', onClick: toggleSidebar }, iconEl('list')),
    h('div', { class: 'grow' }, h('h1', { id: 'page-title' }, 'Обзор')),
    buildSearch(),
    buildSyncButton(),
    buildLocaleSelect(),
    h('button', {
      class: 'icon-btn', type: 'button', title: 'Переключить тему', 'aria-label': 'Переключить тему',
      onClick: () => { toggleTheme(); updateThemeIcon(els.themeButton); },
    }),
    buildUserMenu(),
  );

  const content = h('main', { class: 'content', id: 'content', tabindex: '-1' });
  const mainEl = h('div', { class: 'main' }, topbar, content, buildFooter());
  els.app = app;
  els.sidebar = sidebar;
  els.overlay = overlay;
  els.content = content;
  els.title = topbar.querySelector('#page-title');
  els.themeButton = topbar.querySelector('button[title="Переключить тему"]');
  updateThemeIcon(els.themeButton);
  app.append(sidebar, overlay, mainEl);
  translateDom(app);
}

function startRouterOnce() {
  if (routerStarted) return;
  routerStarted = true;
  startRouter(ROUTES, dispatch);
}

function buildSidebar() {
  const nav = h('nav', { class: 'nav', 'aria-label': 'Основная навигация' });
  for (const group of NAV) {
    nav.appendChild(h('div', { class: 'nav-group' }, group.group));
    for (const item of group.items) {
      nav.appendChild(h('a', { class: 'nav-item', href: href(item.path), dataset: { path: item.path } },
        iconEl(item.icon), h('span', null, item.label)));
    }
  }

  return h('aside', { class: 'sidebar', 'aria-label': 'Боковая панель' },
    h('a', { class: 'brand', href: href('/') },
      h('span', { class: 'brand-mark' }, iconEl('shield')),
      h('span', { class: 'brand-text' },
        h('span', { class: 'brand-name' }, 'REG.RU Control'),
        h('span', { class: 'brand-sub' }, 'домены · dns · хостинги'),
      ),
    ),
    nav,
    h('div', { class: 'sidebar-foot' },
      h('div', { class: 'status-line' }, h('span', { class: 'dot ok' }), h('span', null, `v${appInfo().version ?? '1.0.0'} · PHP 8.5`)),
      h('div', { class: 'status-line' }, h('span', null, 'Данные:'), h('a', { href: href('/sync') }, 'синхронизация')),
    ),
  );
}

function buildSearch() {
  const input = h('input', {
    type: 'search',
    placeholder: 'Поиск домена…',
    'aria-label': 'Поиск домена',
    style: { maxWidth: '240px' },
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      const query = input.value.trim();
      navigate('/domains' + (query ? `?q=${encodeURIComponent(query)}` : ''));
      input.value = '';
    }
  });
  return h('div', { class: 'search-field hide-sm', style: { maxWidth: '260px' } }, iconEl('search'), input);
}

function buildSyncButton() {
  const button = btn('', { tone: 'ghost', iconName: 'refresh', title: 'Синхронизировать всё', onClick: () => runSync('all') });
  button.setAttribute('aria-label', 'Синхронизировать всё');
  return button;
}

function buildLocaleSelect() {
  const select = h('select', {
    class: 'lang-select',
    'aria-label': 'Язык интерфейса',
    title: 'Язык интерфейса',
  });

  for (const locale of LOCALES) {
    select.appendChild(h('option', { value: locale.code, selected: locale.code === currentLocale() }, locale.native));
  }

  select.addEventListener('change', async () => {
    select.disabled = true;
    await setLocale(select.value);
    select.disabled = false;
  });

  return select;
}

function buildUserMenu() {
  const menu = h('div', {
    class: 'card hidden',
    style: { position: 'absolute', right: '16px', top: '58px', width: '230px', zIndex: '40', padding: '6px' },
  });
  const name = user()?.name ?? 'Пользователь';
  const link = (label, iconName, onClick) => h('button', {
    class: 'nav-item', type: 'button', style: { width: '100%', background: 'none', border: '0', cursor: 'pointer', textAlign: 'left' },
    onClick,
  }, iconEl(iconName), h('span', null, label));

  menu.append(
    h('div', { style: { padding: '8px 10px', borderBottom: '1px solid var(--line)', marginBottom: '4px' } },
      h('div', { class: 'row-main' }, name),
      h('div', { class: 'row-sub' }, isAdmin() ? 'администратор' : 'пользователь'),
    ),
    link('Настройки', 'settings', () => { menu.classList.add('hidden'); navigate('/settings'); }),
    link('Журнал действий', 'activity', () => { menu.classList.add('hidden'); navigate('/audit'); }),
    link('Выйти', 'logout', async () => {
      try {
        await api('auth/logout', { method: 'POST' });
      } finally {
        window.location.reload();
      }
    }),
  );

  const wrapper = h('div', { style: { position: 'relative' } });
  const trigger = h('button', { class: 'btn ghost', type: 'button', 'aria-haspopup': 'true' },
    iconEl('user'), h('span', { class: 'hide-sm' }, name));
  trigger.addEventListener('click', (event) => {
    event.stopPropagation();
    menu.classList.toggle('hidden');
  });
  document.addEventListener('click', () => menu.classList.add('hidden'));

  wrapper.append(trigger, menu);
  translateDom(menu);

  return wrapper;
}

function buildFooter() {
  return h('footer', {
    style: {
      marginTop: 'auto', padding: '14px 22px', borderTop: '1px solid var(--line)',
      color: 'var(--dim)', fontSize: '12px', display: 'flex', gap: '14px', flexWrap: 'wrap',
    },
  },
  h('span', null, `${appInfo().name ?? 'REG.RU Control'} ${appInfo().version ?? ''}`),
  h('a', { href: 'api/v1/openapi.yaml', target: '_blank', rel: 'noopener' }, 'OpenAPI'),
  h('span', null, 'Секреты хранятся зашифрованными; каждое раскрытие пишется в журнал.'),
  );
}

function updateThemeIcon(button) {
  if (!button) return;
  clear(button);
  button.appendChild(iconEl(state.theme === 'dark' ? 'sun' : 'moon'));
}

function toggleSidebar() {
  state.sidebarOpen = !state.sidebarOpen;
  els.sidebar?.classList.toggle('open', state.sidebarOpen);
  els.overlay?.classList.toggle('hidden', !state.sidebarOpen);
}

function closeSidebar() {
  state.sidebarOpen = false;
  els.sidebar?.classList.remove('open');
  els.overlay?.classList.add('hidden');
}

function setActiveNav(path) {
  els.sidebar?.querySelectorAll('.nav-item').forEach((item) => {
    const itemPath = item.dataset.path;
    item.classList.toggle('active', itemPath === path || (itemPath !== '/' && path.startsWith(itemPath)));
  });
}

async function dispatch(route) {
  currentRoute = route;
  closeSidebar();
  setActiveNav(route.nav ?? route.path);
  if (els.title) els.title.textContent = t(route.title);
  document.title = `${t(route.title)} — REG.RU Control`;
  clear(els.content);
  window.scrollTo({ top: 0 });

  if (!route.page) {
    els.content.appendChild(h('div', { class: 'card' }, h('div', { class: 'card-body' },
      h('h2', null, 'Страница не найдена'),
      h('p', { class: 'muted' }, 'Проверьте адрес или вернитесь на обзор.'),
      h('a', { class: 'btn', href: href('/') }, 'На обзор'),
    )));
    translateDom(els.content);

    return;
  }

  const ctx = {
    params: route.params,
    query: route.query,
    navigate,
    runSync,
    isAdmin: isAdmin(),
  };

  try {
    await route.page.render(els.content, ctx);
    translateDom(els.content);
    if (els.title) els.title.textContent = t(route.title);
    document.title = `${t(route.title)} — REG.RU Control`;
    document.documentElement.lang = localeTag();
  } catch (error) {
    renderPageError(error);
  }
}

async function runSync(scope = 'all') {
  if (state.syncRunning) {
    toast('Синхронизация уже выполняется.', 'warn');
    return null;
  }

  state.syncRunning = true;
  const pending = toast(t('Синхронизация ({p}) запущена…').replace('{p}', scope), 'warn', 0);

  try {
    const result = await api('sync', { method: 'POST', body: { scope } });
    pending.remove();
    const tone = result.status === 'ok' ? 'ok' : result.status === 'partial' ? 'warn' : 'bad';
    const details = result.errors?.length ? `${result.errors.length}` : '0';
    toast(`${t('Синхронизация')}: ${result.status} · ${details}`, tone);
    if (els.content && currentRoute) dispatch(currentRoute);

    return result;
  } catch (error) {
    pending.remove();
    toast(error.message || 'Синхронизация не удалась', 'bad');

    return null;
  } finally {
    state.syncRunning = false;
  }
}

function renderPageError(error) {
  const isApi = error instanceof ApiError;
  const card = h('div', { class: 'card' }, h('div', { class: 'card-body' },
    h('div', { class: 'notice bad' }, iconEl('alert'),
      h('div', null,
        h('strong', null, isApi ? `Ошибка ${error.status}` : 'Ошибка'),
        h('div', null, error.message || 'Не удалось загрузить данные.'),
        isApi && error.requestId ? h('div', { class: 'muted fs-12 mt-8' }, `Код обращения: ${error.requestId}`) : null,
      ),
    ),
    h('div', { class: 'btn-row mt-16' },
      btn('Повторить', { tone: 'ghost', iconName: 'refresh', onClick: () => dispatch(currentRoute) }),
      btn('На обзор', { tone: 'soft', onClick: () => navigate('/') }),
    ),
  ));
  clear(els.content);
  els.content.appendChild(card);
  translateDom(els.content);
}

function renderFatal(error) {
  const app = document.getElementById('app');
  clear(app);
  app.className = 'app-boot';
  app.appendChild(h('div', { class: 'card', style: { maxWidth: '520px' } }, h('div', { class: 'card-body' },
    h('h1', { style: { fontSize: '20px', marginTop: 0 } }, 'Панель недоступна'),
    h('p', { class: 'muted' }, error?.message ?? 'Не удалось загрузить конфигурацию.'),
    h('p', { class: 'muted fs-13' }, 'Проверьте, что бэкенд запущен и база данных инициализирована (php backend/bin/regru doctor).'),
    h('button', { class: 'btn', type: 'button', onClick: () => window.location.reload() }, 'Повторить'),
  )));
  translateDom(app);
}

main();
