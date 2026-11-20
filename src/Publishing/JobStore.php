<?php
declare(strict_types=1);

namespace CMSModular\Publishing;

use CMSModular\Core\Database;

/** Cola persistente de trabajos de publicación/reversión con estados, registro y recuperación tras caídas. */
final class JobStore
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Encola un trabajo. Una publicación pendiente equivalente se reutiliza (evita acumular clics repetidos). */
    public function enqueue(string $kind, string $source, ?int $by, string $label, bool $force = false, ?string $target = null, int $maxAttempts = 3): int
    {
        return (int)$this->db->transaction(function () use ($kind, $source, $by, $label, $force, $target, $maxAttempts): int {
            if ($kind === 'publish') {
                $dup = $this->db->val("SELECT id FROM publish_jobs WHERE kind='publish' AND status='queued' AND force=? ORDER BY id LIMIT 1", [$force ? 1 : 0]);
                if ($dup !== null) {
                    return (int)$dup;
                }
            }
            $this->db->run('INSERT INTO publish_jobs (kind,source,requested_by,label,force,target_release,max_attempts,created_at) VALUES (?,?,?,?,?,?,?,?)', [$kind, $source, $by, $label, $force ? 1 : 0, $target, $maxAttempts, time()]);
            return $this->db->lastId();
        });
    }

    /** Reserva atómicamente un trabajo ($id concreto o el más antiguo disponible). */
    public function claim(?int $id = null): ?array
    {
        $now = time();
        $cand = $id !== null
            ? $this->db->val("SELECT id FROM publish_jobs WHERE id=? AND status='queued' AND not_before<=?", [$id, $now])
            : $this->db->val("SELECT id FROM publish_jobs WHERE status='queued' AND not_before<=? ORDER BY id LIMIT 1", [$now]);
        if ($cand === null) {
            return null;
        }
        $st = $this->db->run("UPDATE publish_jobs SET status='running', attempts=attempts+1, started_at=?, heartbeat_at=?, error=NULL WHERE id=? AND status='queued'", [$now, $now, (int)$cand]);
        return $st->rowCount() === 1 ? $this->get((int)$cand) : null;
    }

    public function heartbeat(int $id): void
    {
        $this->db->run("UPDATE publish_jobs SET heartbeat_at=? WHERE id=? AND status='running'", [time(), $id]);
    }

    /** @param array<string,mixed> $extra release_id, skipped */
    public function succeed(int $id, array $extra = []): void
    {
        $this->db->run("UPDATE publish_jobs SET status='succeeded', finished_at=?, release_id=?, skipped=? WHERE id=?", [time(), $extra['release_id'] ?? null, !empty($extra['skipped']) ? 1 : 0, $id]);
    }

    public function fail(int $id, string $error): void
    {
        $this->db->run("UPDATE publish_jobs SET status='failed', finished_at=?, error=? WHERE id=?", [time(), mb_substr($error, 0, 500), $id]);
    }

    /** Devuelve el trabajo a la cola con espera. Si agotó los intentos, lo marca fallido. @return bool true si se reencoló */
    public function retryLater(int $id, string $error, int $delay): bool
    {
        $j = $this->get($id);
        if ($j === null || (int)$j['attempts'] >= (int)$j['max_attempts']) {
            $this->fail($id, $error);
            return false;
        }
        $this->db->run("UPDATE publish_jobs SET status='queued', not_before=?, error=? WHERE id=?", [time() + $delay, mb_substr($error, 0, 500), $id]);
        return true;
    }

    public function cancel(int $id): bool
    {
        return $this->db->run("UPDATE publish_jobs SET status='cancelled', finished_at=? WHERE id=? AND status='queued'", [time(), $id])->rowCount() === 1;
    }

    /**
     * Trabajos «en ejecución» cuyo proceso murió: sin latido reciente y con el bloqueo de publicación libre.
     * Se reencolan (o fallan si agotaron intentos). @param callable():bool $lockFree @return int cantidad recuperada
     */
    public function recoverStale(callable $lockFree, int $graceSeconds = 60): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id FROM publish_jobs WHERE status='running' AND heartbeat_at < ?", [time() - $graceSeconds]) as $row) {
            if (!$lockFree()) {
                continue;
            }
            $id = (int)$row['id'];
            $this->log($id, 'warn', 'El proceso se interrumpió; se recupera el trabajo.');
            $this->retryLater($id, 'El proceso de publicación se interrumpió.', 0);
            $n++;
        }
        return $n;
    }

    public function log(int $id, string $level, string $message): void
    {
        $this->db->run('INSERT INTO publish_job_log (job_id,ts,level,message) VALUES (?,?,?,?)', [$id, time(), $level, mb_substr($message, 0, 1000)]);
    }

    public function get(int $id): ?array
    {
        return $this->db->one('SELECT * FROM publish_jobs WHERE id=?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->db->all('SELECT * FROM publish_jobs ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    /** @return list<array<string,mixed>> */
    public function logs(int $id): array
    {
        return $this->db->all('SELECT ts,level,message FROM publish_job_log WHERE job_id=? ORDER BY id', [$id]);
    }

    /** Conserva los últimos $keep trabajos terminados (los registros se borran en cascada). */
    public function prune(int $keep = 200): void
    {
        $this->db->run("DELETE FROM publish_jobs WHERE status IN ('succeeded','failed','cancelled') AND id <= (SELECT COALESCE(MAX(id),0) FROM publish_jobs) - ?", [$keep]);
    }
}
