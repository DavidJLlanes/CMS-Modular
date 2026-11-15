<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Content\ContentRepository;
use LuxAeterna\Content\Menus;
use LuxAeterna\Content\Settings;
use LuxAeterna\Content\SiteBuilder;
use LuxAeterna\Content\Taxonomy;
use LuxAeterna\Content\TypeRegistry;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Database;
use LuxAeterna\Core\Migrator;
use LuxAeterna\Publishing\Preview;
use LuxAeterna\Security\Audit;
use LuxAeterna\Security\Csrf;
use LuxAeterna\Security\Secrets;
use LuxAeterna\Security\Sessions;
use LuxAeterna\Security\Throttle;
use LuxAeterna\Security\Tokens;
use LuxAeterna\Security\Users;

/** Contenedor mínimo: construye cada servicio una sola vez, a demanda. */
final class Services
{
    public readonly Database $db;
    public readonly Secrets $secrets;
    public readonly Users $users;
    public readonly Sessions $sessions;
    public readonly Tokens $tokens;
    public readonly Throttle $throttle;
    public readonly Audit $audit;
    public readonly Csrf $csrf;
    public readonly TypeRegistry $types;
    public readonly Taxonomy $taxonomy;
    public readonly ContentRepository $content;
    public readonly Menus $menus;
    public readonly Settings $settings;
    public readonly SiteBuilder $site;
    public readonly Preview $preview;

    public function __construct(public readonly Config $config, bool $migrate = false)
    {
        $data = $config->path('data');
        $this->db = new Database($data . '/db/lux.sqlite');
        if ($migrate) {
            (new Migrator($this->db, $config->root . '/migrations'))->migrate();
        }
        $this->secrets = new Secrets($data . '/secrets/app.key');
        $this->users = new Users($this->db, $this->secrets);
        $this->sessions = new Sessions(
            $this->db,
            $this->secrets,
            (int)$config->get('security.session_idle', 1800),
            (int)$config->get('security.session_absolute', 28800)
        );
        $this->tokens = new Tokens($this->db);
        $this->throttle = new Throttle($this->db, $this->secrets);
        $this->audit = new Audit($this->db);
        $this->csrf = new Csrf($this->secrets);
        $this->types = TypeRegistry::fromConfig($config);
        $this->taxonomy = new Taxonomy($this->db);
        $this->content = new ContentRepository($this->db, $this->types, $this->taxonomy);
        $this->menus = new Menus($this->db);
        $this->settings = new Settings($this->db);
        $this->site = new SiteBuilder($this->db, $this->types, $this->menus, $this->settings);
        $this->preview = new Preview($config, $this->types, $this->menus, $this->db, $config->root . '/components/layouts/base');
    }

    public function mfaRequired(): bool
    {
        return (string)$this->config->get('security.mfa', 'required') !== 'optional';
    }

    /** @return list<string> */
    public function trustedProxies(): array
    {
        $v = $this->config->get('security.trusted_proxies', []);
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }
}
