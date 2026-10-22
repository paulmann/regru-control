-- 0001 — initial schema
-- All timestamps are UTC ISO-8601 strings.

CREATE TABLE users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    login           TEXT    NOT NULL UNIQUE,
    password_hash   TEXT    NOT NULL,
    role            TEXT    NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'viewer')),
    display_name    TEXT    NOT NULL DEFAULT '',
    is_active       INTEGER NOT NULL DEFAULT 1,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT    NULL,
    last_login_at   TEXT    NULL,
    last_login_ip   TEXT    NULL,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE regru_account (
    id               INTEGER PRIMARY KEY CHECK (id = 1),
    username         TEXT    NOT NULL,
    password_enc     TEXT    NOT NULL,
    currency         TEXT    NOT NULL DEFAULT 'RUR',
    last_verified_at TEXT    NULL,
    last_error       TEXT    NULL,
    updated_at       TEXT    NOT NULL
);

CREATE TABLE services (
    service_id        TEXT PRIMARY KEY,
    servtype          TEXT    NOT NULL,
    dname             TEXT    NOT NULL DEFAULT '',
    subtype           TEXT    NOT NULL DEFAULT '',
    state             TEXT    NOT NULL DEFAULT 'A',
    creation_date     TEXT    NULL,
    expiration_date   TEXT    NULL,
    uplink_service_id TEXT    NOT NULL DEFAULT '0',
    folder            TEXT    NULL,
    raw               TEXT    NOT NULL DEFAULT '{}',
    details_enc       TEXT    NULL,
    details_synced_at TEXT    NULL,
    synced_at         TEXT    NOT NULL,
    updated_at        TEXT    NOT NULL
);

CREATE INDEX idx_services_servtype ON services (servtype);
CREATE INDEX idx_services_dname ON services (dname);
CREATE INDEX idx_services_expiration ON services (expiration_date);

CREATE TABLE domains (
    dname           TEXT PRIMARY KEY,
    service_id      TEXT    NOT NULL,
    state           TEXT    NOT NULL,
    creation_date   TEXT    NULL,
    expiration_date TEXT    NULL,
    nss             TEXT    NOT NULL DEFAULT '[]',
    dns_managed     INTEGER NOT NULL DEFAULT 0,
    autorenew       INTEGER NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE dns_records (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    dname     TEXT    NOT NULL,
    subname   TEXT    NOT NULL,
    rectype   TEXT    NOT NULL,
    content   TEXT    NOT NULL,
    prio      INTEGER NOT NULL DEFAULT 0,
    synced_at TEXT    NOT NULL,
    UNIQUE (dname, subname, rectype, content, prio)
);

CREATE INDEX idx_dns_dname ON dns_records (dname);

CREATE TABLE hostings (
    service_id        TEXT PRIMARY KEY,
    servtype          TEXT    NOT NULL,
    dname             TEXT    NOT NULL DEFAULT '',
    server_ip         TEXT    NULL,
    panel_url         TEXT    NULL,
    panel_login       TEXT    NULL,
    panel_password_enc TEXT   NULL,
    nss               TEXT    NOT NULL DEFAULT '[]',
    plan              TEXT    NULL,
    details_enc       TEXT    NULL,
    state             TEXT    NOT NULL DEFAULT 'A',
    expiration_date   TEXT    NULL,
    synced_at         TEXT    NOT NULL,
    updated_at        TEXT    NOT NULL
);

CREATE TABLE credentials (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    title            TEXT    NOT NULL,
    kind             TEXT    NOT NULL DEFAULT 'other',
    host             TEXT    NOT NULL DEFAULT '',
    port             INTEGER NULL,
    username         TEXT    NOT NULL DEFAULT '',
    password_enc     TEXT    NOT NULL,
    url              TEXT    NOT NULL DEFAULT '',
    notes            TEXT    NOT NULL DEFAULT '',
    service_id       TEXT    NULL,
    dname            TEXT    NULL,
    source           TEXT    NOT NULL DEFAULT 'manual',
    last_revealed_at TEXT    NULL,
    created_at       TEXT    NOT NULL,
    updated_at       TEXT    NOT NULL,
    UNIQUE (title, host, username)
);

CREATE INDEX idx_credentials_kind ON credentials (kind);
CREATE INDEX idx_credentials_dname ON credentials (dname);

CREATE TABLE api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    prefix       TEXT    NOT NULL,
    token_hash   TEXT    NOT NULL UNIQUE,
    scopes       TEXT    NOT NULL DEFAULT '["read"]',
    created_by   INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at   TEXT    NOT NULL,
    last_used_at TEXT    NULL,
    expires_at   TEXT    NULL,
    revoked_at   TEXT    NULL
);

CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    at         TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    actor      TEXT NOT NULL,
    action     TEXT NOT NULL,
    target     TEXT NOT NULL DEFAULT '',
    result     TEXT NOT NULL DEFAULT 'ok',
    ip         TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    details    TEXT NOT NULL DEFAULT '{}',
    request_id TEXT NOT NULL DEFAULT ''
);

CREATE INDEX idx_audit_at ON audit_log (at DESC);
CREATE INDEX idx_audit_action ON audit_log (action);

CREATE TABLE sync_runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at  TEXT NOT NULL,
    finished_at TEXT NULL,
    status      TEXT NOT NULL DEFAULT 'running',
    trigger     TEXT NOT NULL DEFAULT 'manual',
    actor       TEXT NOT NULL DEFAULT '',
    stats       TEXT NOT NULL DEFAULT '{}',
    error       TEXT NULL
);

CREATE INDEX idx_sync_started ON sync_runs (started_at DESC);

CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE rate_limits (
    bucket       TEXT    NOT NULL,
    key          TEXT    NOT NULL,
    window_start INTEGER NOT NULL,
    counter      INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, key)
);
