-- Fase 6: biblioteca multimedia. Los originales viven fuera de la web; aquí solo sus metadatos editoriales.
CREATE TABLE media (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uid         TEXT NOT NULL UNIQUE,                 -- identificador estable que usan los bloques (img-xxxxxxxxxxxx)
    title       TEXT NOT NULL DEFAULT '',             -- nombre editorial (no el nombre del archivo subido)
    alt         TEXT NOT NULL DEFAULT '',             -- texto alternativo por defecto; cada uso puede sustituirlo
    caption     TEXT NOT NULL DEFAULT '',
    credit      TEXT NOT NULL DEFAULT '',
    mime        TEXT NOT NULL,
    ext         TEXT NOT NULL,
    bytes       INTEGER NOT NULL,
    width       INTEGER NOT NULL,
    height      INTEGER NOT NULL,
    sha256      TEXT NOT NULL,                        -- huella del original: identifica sus derivados
    has_alpha   INTEGER NOT NULL DEFAULT 0,
    notes       TEXT NOT NULL DEFAULT '',             -- avisos de la ingesta (perfil de color, orientación…)
    focus_x     INTEGER NOT NULL DEFAULT 50 CHECK (focus_x BETWEEN 0 AND 100),
    focus_y     INTEGER NOT NULL DEFAULT 50 CHECK (focus_y BETWEEN 0 AND 100),
    created_by  INTEGER,
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL
);
CREATE INDEX media_created ON media(created_at DESC);

-- Derivados WebP: la clave es la huella del original + la receta + la versión del procesado.
CREATE TABLE media_derivs (
    key          TEXT PRIMARY KEY,
    media_id     INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    orig_sha     TEXT NOT NULL,                       -- huella del original del que procede
    recipe       TEXT NOT NULL,
    width        INTEGER NOT NULL,
    height       INTEGER NOT NULL,
    bytes        INTEGER NOT NULL,
    created_at   INTEGER NOT NULL,
    last_used_at INTEGER NOT NULL
);
CREATE INDEX media_derivs_media ON media_derivs(media_id);
