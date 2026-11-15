<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/** Instalación repetible e idempotente: configuración, directorios privados y protección de var/. */
final class Installer
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> acciones realizadas */
    public function install(string $baseUrl, bool $force = false): array
    {
        $actions = [];
        $baseUrl = Config::normalizeBaseUrl($baseUrl);
        $cfgFile = $this->root . '/config/lux.json';
        if (!is_file($cfgFile) || $force) {
            $example = json_decode((string)file_get_contents($this->root . '/config/lux.example.json'), true);
            if (!is_array($example)) {
                throw new LuxException('Falta config/lux.example.json.');
            }
            $example['site']['base_url'] = $baseUrl;
            Fs::atomicWrite($cfgFile, json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0640);
            $actions[] = 'Configuración creada: config/lux.json';
        } else {
            $actions[] = 'Configuración existente conservada (usa --force para regenerarla)';
        }
        $config = Config::load($this->root);
        $data = $config->path('data');
        foreach (Doctor::DATA_SUBDIRS as $sub) {
            Fs::ensureDir($data . '/' . $sub, 0750);
        }
        // Defensa adicional para hostings con Apache si var/ quedara dentro de la raíz web.
        Fs::atomicWrite($data . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", 0640);
        $actions[] = 'Directorios privados listos: ' . $data;
        Fs::ensureDir($config->path('publish') . '/releases', 0755);
        $actions[] = 'Directorio de publicación listo: ' . $config->path('publish');
        return $actions;
    }
}
