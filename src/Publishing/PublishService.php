<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Core\TransientException;

/**
 * Ejecuta trabajos de publicación y reversión. Los fallos transitorios (bloqueo ocupado, E/S) se reintentan de
 * forma acotada; los permanentes (comprobación previa, configuración) fallan de inmediato. En ambos casos la
 * versión activa no se toca.
 */
final class PublishService
{
    /** @param \Closure():SiteSnapshot $snapshot */
    public function __construct(
        private readonly Config $config,
        private readonly JobStore $jobs,
        private readonly ReleaseStore $store,
        private readonly Publisher $publisher,
        private readonly \Closure $snapshot,
        private readonly Logger $logger,
        private readonly ?RuleSync $rules = null,
    ) {
    }

    public function enqueuePublish(string $source, ?int $by, string $label = '', bool $force = false): int
    {
        return $this->jobs->enqueue('publish', $source, $by, $label, $force, null, (int)$this->config->get('publishing.max_attempts', 3));
    }

    public function enqueueRollback(string $target, string $source, ?int $by, string $label = ''): int
    {
        $this->store->assertId($target);
        return $this->jobs->enqueue('rollback', $source, $by, $label, false, $target, 1);
    }

    /** Ejecuta un trabajo concreto o el siguiente disponible. @return array<string,mixed>|null trabajo tras ejecutarlo */
    public function runNext(?int $id = null): ?array
    {
        $this->store->init();
        $this->jobs->recoverStale(fn(): bool => $this->store->lockFree());
        $job = $this->jobs->claim($id);
        if ($job === null) {
            return null;
        }
        $jid = (int)$job['id'];
        $this->jobs->log($jid, 'info', ($job['kind'] === 'rollback' ? 'Reversión a ' . $job['target_release'] : 'Publicación') . ' (intento ' . $job['attempts'] . '/' . $job['max_attempts'] . ')');
        try {
            if ($job['kind'] === 'rollback') {
                $this->rollback((string)$job['target_release'], $jid);
                $this->jobs->succeed($jid, ['release_id' => $job['target_release']]);
            } else {
                $res = $this->publish((bool)$job['force'], $jid);
                $this->jobs->succeed($jid, ['release_id' => $res['id'], 'skipped' => $res['skipped']]);
            }
        } catch (TransientException $e) {
            $delay = min(300, 5 * (2 ** ((int)$job['attempts'] - 1)));
            $again = $this->jobs->retryLater($jid, $e->getMessage(), $delay);
            $this->jobs->log($jid, 'warn', $e->getMessage() . ($again ? " Se reintentará en {$delay} s." : ' Sin más intentos.'));
        } catch (\Throwable $e) {
            $msg = $e instanceof LuxException ? $e->getMessage() : 'Error interno al publicar.';
            $this->jobs->log($jid, 'error', $msg);
            $this->logger->error('trabajo de publicación fallido', ['job' => $jid, 'error' => $e->getMessage(), 'detail' => $e instanceof LuxException ? $e->detail() : get_class($e)]);
            $this->jobs->fail($jid, $msg);
        }
        $this->jobs->prune();
        return $this->jobs->get($jid);
    }

    /** Ejecuta todo lo pendiente (cron / CLI). @return int trabajos procesados */
    public function runAll(int $max = 20): int
    {
        $n = 0;
        while ($n < $max && $this->runNext() !== null) {
            $n++;
        }
        return $n;
    }

    /** @return array<string,mixed> */
    private function publish(bool $force, int $jid): array
    {
        $snap = ($this->snapshot)();
        $this->jobs->heartbeat($jid);
        $res = $this->publisher->publishSnapshot($snap, ['force' => $force]);
        foreach ($res['warnings'] as $w) {
            $this->jobs->log($jid, 'warn', $w);
        }
        $this->jobs->log($jid, 'info', $res['skipped']
            ? 'Sin cambios respecto a la versión activa ' . $res['id'] . '; no se genera otra.'
            : "Versión {$res['id']} generada y activada ({$res['pages']} páginas).");
        $this->rules?->sync($res['id'], $this->jobs, $jid);
        return $res;
    }

    private function rollback(string $target, int $jid): void
    {
        $this->store->assertId($target);
        $dir = $this->store->releasePath($target);
        $meta = $this->store->meta($target);
        if (!is_dir($dir) || $meta === null || ($meta['id'] ?? null) !== $target || !is_file("$dir/index.html") || !is_file("$dir/404.html")) {
            throw new LuxException('La versión elegida no existe o está incompleta; no se activa.', "rollback $target");
        }
        $lock = fopen($this->store->base . '/.publish.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new TransientException('Ya hay una publicación en curso.');
        }
        try {
            (new Activator($this->store, (string)$this->config->get('publishing.activation', 'auto')))->activate($target);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->jobs->log($jid, 'info', "Versión $target activada.");
        $this->rules?->sync($target, $this->jobs, $jid);
    }
}
