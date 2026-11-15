-- Fase 3: contenido, revisiones, taxonomías, menús, redirecciones y ajustes.
CREATE TABLE contents (
    id INTEGER PRIMARY KEY,
    uid TEXT NOT NULL UNIQUE,                       -- identificador estable (no cambia con el slug)
    type TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('draft', 'published', 'trashed')),
    prev_status TEXT,
    slug TEXT NOT NULL,                             -- slug de la revisión en edición
    current_rev INTEGER,                            -- revisión en edición
    published_rev INTEGER,                          -- revisión publicada (puede ir por detrás de current_rev)
    published_slug TEXT,                            -- slug de la revisión publicada (para redirecciones)
    version INTEGER NOT NULL DEFAULT 1,             -- bloqueo optimista
    created_by INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    published_at INTEGER,
    trashed_at INTEGER
);
CREATE UNIQUE INDEX uq_contents_slug ON contents (type, slug) WHERE status <> 'trashed';
CREATE INDEX ix_contents_status ON contents (status, type, updated_at);

CREATE TABLE revisions (
    id INTEGER PRIMARY KEY,
    content_id INTEGER NOT NULL REFERENCES contents (id) ON DELETE CASCADE,
    number INTEGER NOT NULL,                        -- 0 = autoguardado (uno por contenido)
    kind TEXT NOT NULL CHECK (kind IN ('save', 'restore', 'autosave')),
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    fields TEXT NOT NULL DEFAULT '{}',
    blocks TEXT NOT NULL DEFAULT '[]',
    author_id INTEGER,
    created_at INTEGER NOT NULL,
    UNIQUE (content_id, number)
);

CREATE TABLE terms (
    id INTEGER PRIMARY KEY,
    uid TEXT NOT NULL UNIQUE,
    taxonomy TEXT NOT NULL CHECK (taxonomy IN ('category', 'tag')),
    slug TEXT NOT NULL,
    name TEXT NOT NULL,
    UNIQUE (taxonomy, slug)
);
CREATE TABLE content_terms (
    content_id INTEGER NOT NULL REFERENCES contents (id) ON DELETE CASCADE,
    term_id INTEGER NOT NULL REFERENCES terms (id) ON DELETE CASCADE,
    PRIMARY KEY (content_id, term_id)
);

CREATE TABLE menu_items (
    id INTEGER PRIMARY KEY,
    menu TEXT NOT NULL,
    position INTEGER NOT NULL,
    label TEXT NOT NULL,
    target_uid TEXT,                                -- contenido por identificador estable…
    url TEXT                                        -- …o ruta interna / URL https externa
);
CREATE INDEX ix_menu_items ON menu_items (menu, position);

CREATE TABLE redirects (
    from_path TEXT PRIMARY KEY,
    to_path TEXT NOT NULL,
    code INTEGER NOT NULL DEFAULT 301,
    content_uid TEXT,
    created_at INTEGER NOT NULL
);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
