<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Core\HttpException;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\AuditLogRepository;
use AccountCheck\Repositories\CheckerTypeRepository;
use AccountCheck\Repositories\PlanRepository;
use AccountCheck\Repositories\SettingsRepository;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Repositories\WalletRepository;

/**
 * Administrative actions, and the rules that bound them.
 *
 * Everything an administrator can change to somebody else's account goes
 * through here, for three reasons: the permission is checked in one place, the
 * self-protection rules cannot be forgotten by a new controller, and every
 * change lands in the audit log with the actor attached.
 *
 * Two rules are worth stating plainly because they are what stop an admin panel
 * becoming a way to lose control of the installation:
 *
 *   - An administrator cannot suspend, delete or demote their own account. A
 *     single careless click must not lock the last administrator out.
 *   - Only SUPER_ADMIN grants SUPER_ADMIN, and only SUPER_ADMIN edits system
 *     settings. Privilege cannot be escalated sideways by an ADMIN promoting
 *     somebody and then asking them for a favour.
 *
 * Wallet adjustments run through the ordinary ledger, not a balance write. An
 * administrator moves credits the same way a purchase or a job does, so the
 * running balance stays derivable from the transactions.
 */
final class AdminService
{
    /** The largest single adjustment, as a guard against a slipped digit. */
    private const MAX_ADJUSTMENT = 1_000_000;

    /** @var list<string> */
    private const USER_STATUSES = ['ACTIVE', 'SUSPENDED', 'PENDING'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
        private readonly CheckerTypeRepository $checkers,
        private readonly PlanRepository $plans,
        private readonly SettingsRepository $settings,
        private readonly AuditLogRepository $audit,
    ) {
    }

    // -- Users ---------------------------------------------------------------

    /** @return array<string, mixed> */
    public function findUser(string $reference): array
    {
        $user = ctype_digit($reference)
            ? $this->users->findById((int) $reference)
            : $this->users->findByUuid($reference);

        if ($user === null) {
            throw HttpException::notFound('That user does not exist.', 'USER_NOT_FOUND');
        }

        return $user;
    }

    /**
     * Suspends or reactivates an account.
     *
     * @param array<string, mixed> $target
     */
    public function setUserStatus(AuthenticatedUser $actor, array $target, string $status, ?string $ip = null): void
    {
        $this->require($actor, 'admin.users.manage');
        $status = strtoupper(trim($status));

        if (!in_array($status, self::USER_STATUSES, true)) {
            throw HttpException::badRequest('That is not a valid account status.', 'INVALID_STATUS');
        }

        $targetId = (int) $target['id'];

        if ($targetId === $actor->id) {
            throw HttpException::badRequest(
                'You cannot change your own account status.',
                'SELF_ACTION_REFUSED',
            );
        }

        $this->guardPrivilegedTarget($actor, (string) ($target['role_slug'] ?? 'USER'));

        if ((string) $target['status'] === $status) {
            return;
        }

        $this->users->updateStatus($targetId, $status);

        $this->audit->record(
            'admin.user.status_changed',
            $actor->id,
            $actor->role,
            'user',
            $targetId,
            ['from' => $target['status'], 'to' => $status],
            $status === 'SUSPENDED' ? 'WARNING' : 'NOTICE',
            $ip,
        );
    }

    /**
     * Moves an account to a different role.
     *
     * @param array<string, mixed> $target
     */
    public function setUserRole(AuthenticatedUser $actor, array $target, string $role, ?string $ip = null): void
    {
        $this->require($actor, 'admin.users.manage');
        $role = strtoupper(trim($role));

        $known = array_column($this->users->roles(), 'slug');

        if (!in_array($role, $known, true)) {
            throw HttpException::badRequest('That is not a valid role.', 'INVALID_ROLE');
        }

        $targetId = (int) $target['id'];

        if ($targetId === $actor->id) {
            throw HttpException::badRequest('You cannot change your own role.', 'SELF_ACTION_REFUSED');
        }

        // Granting or removing the break-glass role is a super-admin act, in
        // both directions: an ADMIN must not be able to create a peer above
        // themselves, nor strip the account that outranks them.
        if (($role === 'SUPER_ADMIN' || (string) ($target['role_slug'] ?? '') === 'SUPER_ADMIN')
            && $actor->role !== 'SUPER_ADMIN') {
            throw HttpException::forbidden(
                'Only a super administrator can change that role.',
                'SUPER_ADMIN_REQUIRED',
            );
        }

        if (!$this->users->updateRole($targetId, $role)) {
            throw HttpException::badRequest('That role could not be applied.', 'ROLE_NOT_APPLIED');
        }

        $this->audit->record(
            'admin.user.role_changed',
            $actor->id,
            $actor->role,
            'user',
            $targetId,
            ['from' => $target['role_slug'] ?? null, 'to' => $role],
            'WARNING',
            $ip,
        );
    }

    /**
     * Credits or debits a user's wallet.
     *
     * Positive adds, negative removes. The movement is written as an ordinary
     * ADJUSTMENT transaction, so it appears in the user's own credit history
     * with the reason the administrator gave, rather than a balance changing
     * for no visible cause.
     *
     * @param array<string, mixed> $target
     * @return array{balance: int, amount: int}
     */
    public function adjustWallet(
        AuthenticatedUser $actor,
        array $target,
        int $amount,
        string $reason,
        ?string $ip = null,
    ): array {
        $this->require($actor, 'admin.wallet.adjust');

        $reason = trim($reason);

        if ($reason === '') {
            throw HttpException::badRequest('A reason is required.', 'REASON_REQUIRED');
        }

        if ($amount === 0) {
            throw HttpException::badRequest('An adjustment cannot be zero.', 'INVALID_AMOUNT');
        }

        if (abs($amount) > self::MAX_ADJUSTMENT) {
            throw HttpException::badRequest(
                sprintf('A single adjustment is limited to %s credits.', number_format(self::MAX_ADJUSTMENT)),
                'AMOUNT_TOO_LARGE',
            );
        }

        $targetId = (int) $target['id'];
        $this->wallets->ensureWallet($targetId);

        $description = sprintf('Adjusted by %s: %s', $actor->name, $reason);

        if ($amount > 0) {
            $balance = $this->wallets->credit(
                $targetId,
                $amount,
                'ADJUSTMENT',
                $description,
                null,
                $actor->id,
            );
        } else {
            $balance = $this->wallets->debit(
                $targetId,
                abs($amount),
                $description,
                null,
                $actor->id,
                'ADJUSTMENT',
            );

            if ($balance === null) {
                // Reserved credits belong to a running job. Taking them would
                // leave that job unable to settle, so the refusal is correct.
                throw HttpException::conflict(
                    'That account does not have enough available credits. Credits held by a running job cannot be removed.',
                    'INSUFFICIENT_CREDITS',
                );
            }
        }

        $this->audit->record(
            'admin.wallet.adjusted',
            $actor->id,
            $actor->role,
            'user',
            $targetId,
            ['amount' => $amount, 'reason' => $reason, 'balance_after' => $balance],
            'WARNING',
            $ip,
        );

        return ['balance' => $balance, 'amount' => $amount];
    }

    // -- Checkers ------------------------------------------------------------

    /**
     * Changes a checker's runtime settings.
     *
     * Only the three an administrator owns: whether it runs at all, what it
     * costs and how large a batch may be. Provider credentials are not editable
     * from the panel and are not returned by it; they live in the environment.
     *
     * @param array<string, mixed> $changes
     */
    public function updateChecker(AuthenticatedUser $actor, string $slug, array $changes, ?string $ip = null): array
    {
        $this->require($actor, 'admin.checkers.manage');

        $checker = $this->checkers->findBySlug($slug);

        if ($checker === null) {
            throw HttpException::notFound('That checker does not exist.', 'CHECKER_NOT_FOUND');
        }

        $applied = [];

        if (array_key_exists('is_enabled', $changes)) {
            $applied['is_enabled'] = (bool) $changes['is_enabled'] ? 1 : 0;
        }

        if (array_key_exists('credit_cost', $changes)) {
            $cost = (int) $changes['credit_cost'];

            if ($cost < 0 || $cost > 1000) {
                throw HttpException::badRequest('A credit cost must be between 0 and 1000.', 'INVALID_COST');
            }

            $applied['credit_cost'] = $cost;
        }

        if (array_key_exists('max_batch_size', $changes)) {
            $size = (int) $changes['max_batch_size'];

            if ($size < 1 || $size > 5000) {
                throw HttpException::badRequest('A batch limit must be between 1 and 5000.', 'INVALID_BATCH_SIZE');
            }

            $applied['max_batch_size'] = $size;
        }

        if ($applied === []) {
            throw HttpException::badRequest('Nothing to change.', 'NO_CHANGES');
        }

        $this->checkers->update((int) $checker['id'], $applied);

        $this->audit->record(
            'admin.checker.updated',
            $actor->id,
            $actor->role,
            'checker_type',
            (int) $checker['id'],
            ['slug' => $checker['slug']] + $applied,
            'NOTICE',
            $ip,
        );

        return $this->checkers->findBySlug($slug) ?? [];
    }

    // -- Plans ---------------------------------------------------------------

    /**
     * Edits a pricing plan.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updatePlan(AuthenticatedUser $actor, string $slug, array $changes, ?string $ip = null): array
    {
        $this->require($actor, 'admin.plans.manage');

        $plan = $this->plans->findBySlug($slug);

        if ($plan === null) {
            throw HttpException::notFound('That plan does not exist.', 'PLAN_NOT_FOUND');
        }

        $applied = [];

        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $changes)) {
                $applied[$field] = trim((string) $changes[$field]);
            }
        }

        if (array_key_exists('price_cents', $changes)) {
            $price = (int) $changes['price_cents'];

            if ($price < 0) {
                throw HttpException::badRequest('A price cannot be negative.', 'INVALID_PRICE');
            }

            $applied['price_cents'] = $price;
        }

        if (array_key_exists('credits', $changes)) {
            $credits = (int) $changes['credits'];

            if ($credits < 0) {
                throw HttpException::badRequest('A credit allowance cannot be negative.', 'INVALID_CREDITS');
            }

            $applied['credits'] = $credits;
        }

        if (array_key_exists('features', $changes)) {
            $applied['features'] = array_values(array_filter(
                array_map(
                    static fn (mixed $line): string => trim((string) $line),
                    (array) $changes['features'],
                ),
                static fn (string $line): bool => $line !== '',
            ));
        }

        if (array_key_exists('is_active', $changes)) {
            $applied['is_active'] = (bool) $changes['is_active'] ? 1 : 0;
        }

        if (array_key_exists('sort_order', $changes)) {
            $applied['sort_order'] = (int) $changes['sort_order'];
        }

        if ($applied === []) {
            throw HttpException::badRequest('Nothing to change.', 'NO_CHANGES');
        }

        $this->plans->update((int) $plan['id'], $applied);

        $this->audit->record(
            'admin.plan.updated',
            $actor->id,
            $actor->role,
            'plan',
            (int) $plan['id'],
            ['slug' => $plan['slug'], 'fields' => array_keys($applied)],
            'NOTICE',
            $ip,
        );

        return $this->plans->findBySlug($slug) ?? [];
    }

    // -- Settings ------------------------------------------------------------

    /**
     * Writes a system setting.
     *
     * Restricted to SUPER_ADMIN: these change how the installation behaves for
     * everyone, and some of them (registration, maintenance mode) can shut the
     * product off.
     */
    public function updateSetting(AuthenticatedUser $actor, string $key, string $value, ?string $ip = null): void
    {
        if ($actor->role !== 'SUPER_ADMIN') {
            throw HttpException::forbidden(
                'Only a super administrator can change system settings.',
                'SUPER_ADMIN_REQUIRED',
            );
        }

        if (!$this->settings->exists($key)) {
            throw HttpException::notFound('That setting does not exist.', 'SETTING_NOT_FOUND');
        }

        $this->settings->set($key, $value, $actor->id);

        $this->audit->record(
            'admin.setting.updated',
            $actor->id,
            $actor->role,
            'setting',
            null,
            ['key' => $key, 'value' => $value],
            'WARNING',
            $ip,
        );
    }

    // -- Guards --------------------------------------------------------------

    private function require(AuthenticatedUser $actor, string $permission): void
    {
        if (!$actor->can($permission)) {
            throw HttpException::forbidden('You do not have permission to do that.', 'PERMISSION_DENIED');
        }
    }

    /**
     * Stops a staff account being acted on by someone who does not outrank it.
     */
    private function guardPrivilegedTarget(AuthenticatedUser $actor, string $targetRole): void
    {
        if ($targetRole === 'SUPER_ADMIN' && $actor->role !== 'SUPER_ADMIN') {
            throw HttpException::forbidden(
                'Only a super administrator can act on that account.',
                'SUPER_ADMIN_REQUIRED',
            );
        }
    }
}
