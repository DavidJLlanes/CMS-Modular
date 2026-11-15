-- Fase 8: galerías. Una galería es un conjunto ordenado de imágenes de la biblioteca con un diseño y opciones; los bloques
-- de contenido la referencian por identificador estable (gal-xxxxxxxxxxxx), igual que las imágenes.
CREATE TABLE galleries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uid         TEXT NOT NULL UNIQUE,
    title       TEXT NOT NULL DEFAULT '',
    layout      TEXT NOT NULL,                      -- id del diseño (components/galerias/<id>)
    crop        TEXT NOT NULL DEFAULT 'free' CHECK (crop IN ('free','square','landscape','portrait')),
    options     TEXT NOT NULL DEFAULT '{}',         -- valores de los campos del diseño (validados contra su manifiesto)
    lightbox    TEXT NOT NULL DEFAULT '{}',         -- visor: activado, variante, controles
    protect     TEXT NOT NULL DEFAULT '{}',         -- disuasión: arrastre y menú contextual
    created_by  INTEGER,
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL
);

CREATE TABLE gallery_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    gallery_id  INTEGER NOT NULL REFERENCES galleries(id) ON DELETE CASCADE,
    media_uid   TEXT NOT NULL,                      -- sin clave foránea: una imagen borrada se detecta al publicar con un mensaje claro
    position    INTEGER NOT NULL,
    title       TEXT,                               -- NULL = usar el de la biblioteca
    alt         TEXT,
    caption     TEXT,
    focus_x     INTEGER CHECK (focus_x IS NULL OR focus_x BETWEEN 0 AND 100),   -- NULL = foco de la biblioteca
    focus_y     INTEGER CHECK (focus_y IS NULL OR focus_y BETWEEN 0 AND 100),
    crop        TEXT CHECK (crop IS NULL OR crop IN ('free','square','landscape','portrait'))  -- NULL = el de la galería
);
CREATE INDEX gallery_items_g ON gallery_items(gallery_id, position);
CREATE INDEX gallery_items_m ON gallery_items(media_uid);
