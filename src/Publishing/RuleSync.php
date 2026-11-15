<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Core\Fs;

/**
 * Entrega al servidor las reglas HTTP (301) de la versión activa.
 *
 * La aplicación NO puede tocar la configuración de Nginx: deja la propuesta en <publish>/rules/redirects.nginx.conf
 * y avisa con <publish>/rules/apply.request. Un servicio con privilegios (deploy/lux-apply-rules.sh) valida cada
 * línea con una expresión estricta, ejecuta `nginx -t`, recarga y deja el resultado en un archivo de estado.
 * Si el servicio no existe (hosting compartido) no se hace nada: el .htaccess de la versión cubre Apache y las
 * páginas-puente (meta refresh) siguen funcionando en cualquier servidor.
 */
final class RuleSync
{
    public function __construct(private readonly string $publishDir, private readonly ?string $statusFile, private readonly int $waitSeconds = 8)
    {
    }

    public function rulesDir(): string
    {
        return $this->publishDir . '/rules';
    }

    public function sync(string $releaseId, JobStore $jobs, int $jobId): void
    {
        if ($this->statusFile === null) {
            return;
        }
        $src = $this->publishDir . '/releases/' . $releaseId . '/.lux-redirects.nginx.conf';
        if (!is_file($src) && is_file($this->publishDir . '/current/.lux-redirects.nginx.conf') && (new ReleaseStore($this->publishDir))->currentId() === $releaseId) {
            $src = $this->publishDir . '/current/.lux-redirects.nginx.conf';
        }
        $rules = is_file($src) ? (string)file_get_contents($src) : '';
        Fs::ensureDir($this->rulesDir(), 0755);
        $dst = $this->rulesDir() . '/redirects.nginx.conf';
        if (is_file($dst) && (string)file_get_contents($dst) === $rules && $this->lastStatus()['ok'] === true) {
            $jobs->log($jobId, 'info', 'Reglas HTTP sin cambios.');
            return;
        }
        if (is_file($dst)) {
            @copy($dst, $dst . '.prev');
        }
        Fs::atomicWrite($dst, $rules, 0644);
        $token = bin2hex(random_bytes(8));
        Fs::atomicWrite($this->rulesDir() . '/apply.request', $token . ' ' . $releaseId . "\n", 0644);
        $deadline = time() + $this->waitSeconds;
        do {
            $st = $this->lastStatus();
            if (($st['token'] ?? '') === $token) {
                $jobs->log($jobId, $st['ok'] ? 'info' : 'warn', $st['ok']
                    ? 'Reglas HTTP aplicadas en el servidor (' . (int)$st['count'] . ' redirecciones 301).'
                    : 'El servidor rechazó las reglas HTTP: ' . $st['message'] . ' Se mantienen las anteriores; las páginas-puente siguen redirigiendo.');
                return;
            }
            usleep(250000);
        } while (time() < $deadline);
        $jobs->log($jobId, 'warn', 'Las reglas HTTP quedan pendientes de aplicar por el servicio del sistema (no respondió a tiempo).');
    }

    /** @return array{ok:?bool,token:string,message:string,count:int,time:int} */
    public function lastStatus(): array
    {
        $d = $this->statusFile !== null && is_file($this->statusFile) ? json_decode((string)file_get_contents($this->statusFile), true) : null;
        return [
            'ok' => is_array($d) ? (bool)($d['ok'] ?? false) : null,
            'token' => is_array($d) ? (string)($d['token'] ?? '') : '',
            'message' => is_array($d) ? (string)($d['message'] ?? '') : '',
            'count' => is_array($d) ? (int)($d['count'] ?? 0) : 0,
            'time' => is_array($d) ? (int)($d['time'] ?? 0) : 0,
        ];
    }
}
