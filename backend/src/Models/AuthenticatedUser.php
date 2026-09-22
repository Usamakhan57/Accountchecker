<?php

declare(strict_types=1);

namespace AccountCheck\Models;

/**
 * The user attached to a request by AuthMiddleware.
 *
 * Immutable and free of the password hash, so nothing downstream can leak it.
 */
final class AuthenticatedUser
{
    /** Roles that may open the admin panel. */
    private const STAFF_ROLES = ['SUPPORT', 'MANAGER', 'ADMIN', 'SUPER_ADMIN'];

    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly bool $emailVerified,
        public readonly int $sessionId,
        public readonly array $permissions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row A users row joined with roles.slug.
     * @param list<string> $permissions
     */
    public static function fromRow(array $row, int $sessionId, array $permissions = []): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['name'],
            (string) $row['email'],
            (string) ($row['role_slug'] ?? 'USER'),
            (string) $row['status'],
            !empty($row['email_verified_at']),
            $sessionId,
            $permissions,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function can(string $permission): bool
    {
        // SUPER_ADMIN is deliberately unconditional: it is the break-glass role
        // and must not be lockable out by a permission row.
        return $this->role === 'SUPER_ADMIN' || in_array($permission, $this->permissions, true);
    }

    /** @return array<string, mixed> The shape the API exposes as `user`. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'email_verified' => $this->emailVerified,
        ];
    }
}
