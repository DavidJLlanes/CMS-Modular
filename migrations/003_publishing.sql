-- Fase 4: cola de trabajos de publicación y su registro.
CREATE TABLE publish_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kind          TEXT NOT NULL CHECK (kind IN ('publish','rollback')),
    status        TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','succeeded','failed','cancelled')),
    source        TEXT NOT NULL DEFAULT 'panel',
    requested_by  INTEGER,
    label         TEXT NOT NULL DEFAULT '',
    force         INTEGER NOT NULL DEFAULT 0,
    target_release TEXT,
    release_id    TEXT,
    skipped       INTEGER NOT NULL DEFAULT 0,
    attempts      INTEGER NOT NULL DEFAULT 0,
    max_attempts  INTEGER NOT NULL DEFAULT 3,
    error         TEXT,
    created_at    INTEGER NOT NULL,
    not_before    INTEGER NOT NULL DEFAULT 0,
    started_at    INTEGER,
    heartbeat_at  INTEGER,
    finished_at   INTEGER
);
CREATE INDEX publish_jobs_status ON publish_jobs(status, not_before);

CREATE TABLE publish_job_log (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL REFERENCES publish_jobs(id) ON DELETE CASCADE,
    ts     INTEGER NOT NULL,
    level  TEXT NOT NULL,
    message TEXT NOT NULL
);
CREATE INDEX publish_job_log_job ON publish_job_log(job_id, id);
