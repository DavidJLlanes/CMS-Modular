<?php
declare(strict_types=1);

namespace CMSModular\Core;

/** Log privado en JSON por líneas, un archivo por día, dentro de var/logs. */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            return; // El log nunca debe romper la operación.
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'op' => Operation::id(),
            'level' => $level,
            'msg' => $message,
            'ctx' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($this->dir . '/lux-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
}
