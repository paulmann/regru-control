export function parsePath() {
  const raw = window.location.hash.replace(/^#/, '') || '/';
  const [pathPart, queryPart] = raw.split('?');
  const path = normalize(pathPart);
  const query = {};
  if (queryPart) {
    for (const [key, value] of new URLSearchParams(queryPart)) query[key] = value;
  }
  return { path, query };
}

export function normalize(path) {
  const clean = `/${String(path).replace(/^\/+|\/+$/g, '')}`;
  return clean === '/' ? '/' : clean;
}

export function navigate(path, { replace = false } = {}) {
  const target = `#${normalize(path.split('?')[0])}${path.includes('?') ? '?' + path.split('?').slice(1).join('?') : ''}`;
  if (replace) {
    window.history.replaceState(null, '', target);
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  } else if (window.location.hash !== target) {
    window.location.hash = target;
  }
}

export function href(path) {
  return `#${normalize(path.split('?')[0])}${path.includes('?') ? '?' + path.split('?').slice(1).join('?') : ''}`;
}

export function match(pattern, path) {
  const patternParts = normalize(pattern).split('/').filter(Boolean);
  const pathParts = normalize(path).split('/').filter(Boolean);
  if (patternParts.length !== pathParts.length) return null;
  const params = {};
  for (let i = 0; i < patternParts.length; i++) {
    const expected = patternParts[i];
    if (expected.startsWith(':')) {
      params[expected.slice(1)] = decodeURIComponent(pathParts[i]);
    } else if (expected !== pathParts[i]) {
      return null;
    }
  }
  return params;
}

export function startRouter(routes, onChange) {
  const handle = () => {
    const { path, query } = parsePath();
    for (const route of routes) {
      const params = match(route.path, path);
      if (params) {
        onChange({ ...route, params, query, path });
        return;
      }
    }
    onChange({ path, params: {}, query, page: 'notfound', title: 'Страница не найдена' });
  };
  window.addEventListener('hashchange', handle);
  handle();
}
