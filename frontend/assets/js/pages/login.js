import { ApiError, api } from '../api.js';
import { appInfo, applyTheme, state } from '../store.js';
import { btn, clear, h, iconEl, toast } from '../dom.js';
import { LOCALES, currentLocale, setLocale } from '../i18n.js';

export function render(root, ctx) {
  clear(root);
  root.className = 'login-wrap';
  document.title = 'Вход — REG.RU Control';

  const errorBox = h('div', { class: 'notice bad hidden' }, iconEl('alert'), h('div', { class: 'grow' }));
  const login = h('input', { type: 'text', name: 'login', autocomplete: 'username', placeholder: 'Логин', required: true });
  const password = h('input', { type: 'password', name: 'password', autocomplete: 'current-password', placeholder: 'Пароль', required: true });
  const submit = btn('Войти', { iconName: 'logout', type: 'submit' });

  const form = h('form', { class: 'login-form', novalidate: true },
    h('div', { class: 'field' }, h('label', { for: 'login' }, 'Логин'), login),
    h('div', { class: 'field' }, h('label', { for: 'password' }, 'Пароль'), password),
    errorBox,
    submit,
  );

  const showError = (message) => {
    errorBox.classList.remove('hidden');
    errorBox.lastChild.textContent = message;
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorBox.classList.add('hidden');
    submit.disabled = true;
    submit.textContent = 'Проверяем…';

    try {
      await api('auth/login', { method: 'POST', body: { login: login.value.trim(), password: password.value } });
      toast('Добро пожаловать!', 'ok', 1500);
      await ctx.onLogin?.();
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        showError(error.fieldErrors?.login || error.fieldErrors?.password || 'Проверьте поля.');
      } else {
        showError(error.message || 'Не удалось войти.');
      }
      password.value = '';
      password.focus();
    } finally {
      submit.disabled = false;
      submit.textContent = 'Войти';
    }
  });

  const localeSelect = h('select', { class: 'lang-select', 'aria-label': 'Язык интерфейса', title: 'Язык интерфейса' });
  for (const locale of LOCALES) {
    localeSelect.appendChild(h('option', { value: locale.code, selected: locale.code === currentLocale() }, locale.native));
  }
  localeSelect.addEventListener('change', async () => {
    localeSelect.disabled = true;
    await setLocale(localeSelect.value);
    localeSelect.disabled = false;
    ctx.rerender?.(ctx);
  });

  const themeButton = h('button', {
    class: 'icon-btn', type: 'button', title: 'Тема', 'aria-label': 'Переключить тему',
    onClick: () => {
      const theme = ctx.themeToggle?.();
      applyTheme(theme);
      clear(themeButton);
      themeButton.appendChild(iconEl(theme === 'dark' ? 'sun' : 'moon'));
    },
  }, iconEl(state.theme === 'dark' ? 'sun' : 'moon'));

  root.appendChild(h('div', { class: 'login-card' },
    h('div', { class: 'login-head' },
      h('div', { class: 'brand-mark' }, iconEl('shield')),
      h('h1', null, 'REG.RU Control'),
      h('p', null, 'Домены, DNS и хостинги в одном месте'),
    ),
    h('section', { class: 'card' }, h('div', { class: 'card-body' }, form)),
    h('div', { class: 'login-foot' },
      h('div', null, `${appInfo().name ?? 'REG.RU Control'} ${appInfo().version ?? ''} · доступ только для владельца`),
      h('div', { class: 'flex items-center justify-between gap-8', style: { marginTop: '10px' } }, localeSelect, themeButton),
    ),
  ));

  login.focus();
}
