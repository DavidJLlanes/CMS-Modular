<?php
declare(strict_types=1);

namespace CMSModular\Security;

use CMSModular\Core\Database;
use CMSModular\Core\LuxException;

final class Users
{
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly Database $db, private readonly Secrets $secrets)
    {
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function byEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [trim($email)]);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT id, email, name, role, status, totp_enabled, last_login_at, created_at FROM users ORDER BY id');
    }

    public function create(string $email, string $role, string $name = ''): int
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new LuxException('El correo no es válido.');
        }
        if (!Authorizer::validRole($role)) {
            throw new LuxException('Rol no válido (admin o editor).');
        }
        if ($this->byEmail($email) !== null) {
            throw new LuxException('Ya existe un usuario con ese correo.');
        }
        $now = time();
        $this->db->run('INSERT INTO users (email, name, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$email, mb_substr(trim($name), 0, 100), $role, 'invited', $now, $now]);
        return $this->db->lastId();
    }

    /** Fija contraseña, activa la cuenta e invalida todas sus sesiones (cambio de credenciales). */
    public function setPassword(int $id, string $plain): void
    {
        $now = time();
        $this->db->run(
            "UPDATE users SET password_hash = ?, password_changed_at = ?, updated_at = ?, status = CASE WHEN status = 'invited' THEN 'active' ELSE status END, session_epoch = session_epoch + 1 WHERE id = ?",
            [Password::hash($plain), $now, $now, $id]
        );
    }

    public function rehashIfNeeded(int $id, string $plain, ?string $hash): void
    {
        if ($hash !== null && Password::needsRehash($hash)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [Password::hash($plain), $id]);
        }
    }

    public function bumpEpoch(int $id): int
    {
        $this->db->run('UPDATE users SET session_epoch = session_epoch + 1, updated_at = ? WHERE id = ?', [time(), $id]);
        return (int)$this->db->val('SELECT session_epoch FROM users WHERE id = ?', [$id]);
    }

    public function setRole(int $id, string $role): void
    {
        if (!Authorizer::validRole($role)) {
            throw new LuxException('Rol no válido (admin o editor).');
        }
        $this->db->run('UPDATE users SET role = ?, updated_at = ? WHERE id = ?', [$role, time(), $id]);
        $this->bumpEpoch($id);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->run('UPDATE users SET status = ?, updated_at = ? WHERE id = ?', [$status, time(), $id]);
        $this->bumpEpoch($id);
    }

    public function recordLogin(int $id): void
    {
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [time(), $id]);
    }

    /* ---- MFA ---- */

    public function totpSecret(array $user): ?string
    {
        $blob = (string)($user['totp_secret'] ?? '');
        return $blob === '' ? null : $this->secrets->decrypt('totp', $blob);
    }

    /** Guarda un secreto pendiente (aún no activo). */
    public function stageTotp(int $id, string $secretBase32): void
    {
        $this->db->run('UPDATE users SET totp_secret = ?, totp_enabled = 0, totp_last_step = 0 WHERE id = ?', [$this->secrets->encrypt('totp', $secretBase32), $id]);
    }

    public function activateTotp(int $id, int $step): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1, totp_last_step = ?, updated_at = ? WHERE id = ?', [$step, time(), $id]);
    }

    public function markTotpStep(int $id, int $step): bool
    {
        return $this->db->run('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?', [$step, $id, $step])->rowCount() === 1;
    }

    public function disableTotp(int $id): void
    {
        $this->db->run('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, updated_at = ? WHERE id = ?', [time(), $id]);
        $this->db->run('DELETE FROM recovery_codes WHERE user_id = ?', [$id]);
        $this->bumpEpoch($id);
    }

    /** @return list<string> códigos en claro (se muestran una sola vez) */
    public function generateRecoveryCodes(int $id, int $count = 10): array
    {
        $codes = [];
        $this->db->transaction(function () use ($id, $count, &$codes): void {
            $this->db->run('DELETE FROM recovery_codes WHERE user_id = ?', [$id]);
            for ($i = 0; $i < $count; $i++) {
                $raw = '';
                for ($j = 0; $j < 10; $j++) {
                    $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
                }
                $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
                $this->db->run('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)', [$id, $this->codeHash($raw)]);
            }
        });
        return $codes;
    }

    public function useRecoveryCode(int $id, string $input): bool
    {
        $raw = strtolower(preg_replace('/[^a-z0-9]/i', '', $input) ?? '');
        if (strlen($raw) !== 10) {
            return false;
        }
        return $this->db->run(
            'UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [time(), $id, $this->codeHash($raw)]
        )->rowCount() === 1;
    }

    public function remainingRecoveryCodes(int $id): int
    {
        return (int)$this->db->val('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$id]);
    }

    private function codeHash(string $raw): string
    {
        return $this->secrets->hmac('recovery', $raw);
    }
}
