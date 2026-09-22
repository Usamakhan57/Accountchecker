<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;
use AccountCheck\Support\Str;

final class UserRepository extends Repository
{
    private const SELECT_COLUMNS = 'u.id, u.uuid, u.name, u.email, u.password_hash, u.role_id,
        u.status, u.email_verified_at, u.last_login_at, u.created_at, u.updated_at, r.slug AS role_slug';

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->database->selectOne(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email',
            ['email' => strtolower(trim($email))],
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->database->selectOne(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id',
            ['id' => $id],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->database->selectOne(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE u.uuid = :uuid',
            ['uuid' => $uuid],
        );
    }

    public function emailExists(string $email): bool
    {
        return $this->database->scalar(
            'SELECT 1 FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower(trim($email))],
        ) !== null;
    }

    /**
     * Creates a user with an already-hashed password.
     *
     * Hashing happens in AuthService; this layer never sees a plaintext
     * password, so one cannot be logged from here by accident.
     */
    public function create(string $name, string $email, string $passwordHash, string $roleSlug = 'USER'): int
    {
        $roleId = (int) ($this->database->scalar(
            'SELECT id FROM roles WHERE slug = :slug',
            ['slug' => $roleSlug],
        ) ?? 1);

        return $this->database->insert('users', [
            'uuid' => Str::uuid4(),
            'name' => $name,
            'email' => strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
        ]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->database->update('users', ['password_hash' => $passwordHash], ['id' => $userId]);
    }

    public function updateProfile(int $userId, string $name): void
    {
        $this->database->update('users', ['name' => $name], ['id' => $userId]);
    }

    public function markEmailVerified(int $userId): void
    {
        $this->database->update('users', ['email_verified_at' => $this->now()], ['id' => $userId]);
    }

    public function recordLogin(int $userId, ?string $ip): void
    {
        $this->database->update(
            'users',
            ['last_login_at' => $this->now(), 'last_login_ip' => $this->packIp($ip)],
            ['id' => $userId],
        );
    }

    public function updateStatus(int $userId, string $status): void
    {
        $this->database->update('users', ['status' => $status], ['id' => $userId]);
    }

    public function updateRole(int $userId, string $roleSlug): bool
    {
        $roleId = $this->database->scalar('SELECT id FROM roles WHERE slug = :slug', ['slug' => $roleSlug]);

        if ($roleId === null) {
            return false;
        }

        $this->database->update('users', ['role_id' => (int) $roleId], ['id' => $userId]);

        return true;
    }

    /**
     * Permissions granted by the user's role.
     *
     * @return list<string>
     */
    public function permissionsFor(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT DISTINCT p.slug
             FROM users u
             INNER JOIN role_permissions rp ON rp.role_id = u.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = :user_id
             UNION
             SELECT DISTINCT p.slug
             FROM user_roles ur
             INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id',
            ['user_id' => $userId],
        );

        return array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }

    /**
     * Admin user listing with search and filters.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(Paginator $paginator, ?string $search, ?string $status, ?string $role, ?string $sort, ?string $direction): array
    {
        $conditions = ['1 = 1'];
        $bindings = [];

        if ($search !== null && $search !== '') {
            // A LIKE with a leading wildcard cannot use the index; the admin
            // list is small enough that correctness wins over the scan.
            $conditions[] = '(u.email LIKE :search OR u.name LIKE :search OR u.uuid = :exact)';
            $bindings['search'] = '%' . $search . '%';
            $bindings['exact'] = $search;
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'u.status = :status';
            $bindings['status'] = $status;
        }

        if ($role !== null && $role !== '') {
            $conditions[] = 'r.slug = :role';
            $bindings['role'] = $role;
        }

        $where = implode(' AND ', $conditions);

        $column = $this->sortColumn($sort, [
            'created_at' => 'u.created_at',
            'name' => 'u.name',
            'email' => 'u.email',
            'last_login_at' => 'u.last_login_at',
        ], 'u.created_at');

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT u.id, u.uuid, u.name, u.email, u.status, u.email_verified_at, u.last_login_at,
                    u.created_at, r.slug AS role_slug,
                    COALESCE(w.balance, 0) AS wallet_balance,
                    COALESCE(w.reserved, 0) AS wallet_reserved
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE ' . $where . '
             ORDER BY ' . $column . ' ' . $this->sortDirection($direction) . ', u.id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The roles an administrator can assign, staff flag included.
     *
     * Read from the table rather than hard-coded, so a role added by a
     * migration appears in the panel without a code change.
     *
     * @return list<array<string, mixed>>
     */
    public function roles(): array
    {
        return array_map(
            static fn (array $row): array => [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'is_staff' => (bool) $row['is_staff'],
            ],
            $this->database->select('SELECT slug, name, description, is_staff FROM roles ORDER BY id'),
        );
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        $rows = $this->database->select('SELECT status, COUNT(*) AS total FROM users GROUP BY status');

        $counts = ['ACTIVE' => 0, 'SUSPENDED' => 0, 'PENDING' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countCreatedSince(string $since): int
    {
        return (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM users WHERE created_at >= :since',
            ['since' => $since],
        ) ?? 0);
    }
}
