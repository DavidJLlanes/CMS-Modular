<?php
declare(strict_types=1);

namespace CMSModular\Security;

/** Autorización de servidor por acción y por objeto. Todo lo no listado está denegado. */
final class Authorizer
{
    /** @var array<string,list<string>> */
    private const ROLES = [
        'admin' => ['*'],
        'editor' => ['panel.access', 'account.self', 'content.read', 'content.write', 'content.publish', 'media.read', 'media.write'],
    ];

    /** @param array<string,mixed>|null $user */
    public static function can(?array $user, string $permission): bool
    {
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return false;
        }
        $perms = self::ROLES[(string)($user['role'] ?? '')] ?? [];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** Acceso a un objeto con propietario: el propio usuario, o quien gestiona usuarios. */
    public static function canOnOwned(?array $user, int $ownerId, string $permission = 'account.self'): bool
    {
        return self::can($user, $permission) && ((int)($user['id'] ?? 0) === $ownerId || self::can($user, 'users.manage'));
    }

    public static function validRole(string $role): bool
    {
        return isset(self::ROLES[$role]);
    }
}
