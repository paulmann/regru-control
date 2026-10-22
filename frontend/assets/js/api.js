const BASE = new URL('api/v1/', document.baseURI).pathname;

let csrfToken = '';
let unauthorizedHandler = null;

export class ApiError extends Error {
  constructor(message, { status = 0, code = 'ERROR', details = null, requestId = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
    this.requestId = requestId;
  }

  get fieldErrors() {
    return this.details?.fields ?? null;
  }
}

export function setCsrf(token) {
  csrfToken = token ?? '';
}

export function onUnauthorized(handler) {
  unauthorizedHandler = handler;
}

function buildUrl(path, query) {
  const clean = String(path).replace(/^\/+/, '');
  const url = new URL(BASE + clean, document.baseURI);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === '') continue;
      url.searchParams.set(key, String(value));
    }
  }
  return url;
}

async function request(path, { method = 'GET', body, query, headers = {}, raw = false } = {}) {
  const url = buildUrl(path, query);
  const init = {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...headers,
    },
  };

  if (csrfToken) init.headers['X-CSRF-Token'] = csrfToken;

  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url, init);
  } catch (error) {
    throw new ApiError('Нет связи с сервером. Проверьте подключение и повторите.', { code: 'NETWORK' });
  }

  if (response.status === 204) return raw ? { data: null, meta: {} } : null;

  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) {
    const text = await response.text();
    throw new ApiError(text || `HTTP ${response.status}`, { status: response.status });
  }

  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError('Сервер вернул некорректный ответ.', { status: response.status, code: 'BAD_JSON' });
  }

  if (!response.ok) {
    const error = payload?.error ?? {};
    const apiError = new ApiError(error.message || `Ошибка ${response.status}`, {
      status: response.status,
      code: error.code || 'HTTP_ERROR',
      details: error.details ?? null,
      requestId: payload?.request_id ?? null,
    });

    if (response.status === 401 && unauthorizedHandler) unauthorizedHandler(apiError);

    throw apiError;
  }

  if (raw) return { data: payload.data ?? null, meta: payload.meta ?? {} };
  return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}

export function api(path, options = {}) {
  return request(path, options);
}

export function apiRaw(path, options = {}) {
  return request(path, { ...options, raw: true });
}

export { BASE as apiBase };
