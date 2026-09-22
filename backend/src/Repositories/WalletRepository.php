<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

use AccountCheck\Support\Paginator;

/**
 * Credit wallet storage.
 *
 * Two invariants hold everywhere:
 *
 *   1. The client never sets a balance. Every method here is reached only from
 *      a server-side service; no request field maps onto `balance`.
 *   2. Balance changes are atomic. Each mutation is a single conditional
 *      UPDATE whose WHERE clause carries the precondition, so two concurrent
 *      requests cannot both pass the same check. The ledger row is written
 *      inside the same transaction as the balance change.
 *
 * `available` = balance - reserved. Reserved credits are committed to a running
 * job and cannot be spent twice.
 */
final class WalletRepository extends Repository
{
    /** Creates the wallet row if the user has none, and returns its id. */
    public function ensureWallet(int $userId): int
    {
        $existing = $this->database->scalar(
            'SELECT id FROM wallets WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        if ($existing !== null) {
            return (int) $existing;
        }

        // A UNIQUE key on user_id makes the insert the arbiter if two requests
        // race; the loser re-reads the winner's row.
        $this->database->run(
            'INSERT IGNORE INTO wallets (user_id, balance, reserved) VALUES (:user_id, 0, 0)',
            ['user_id' => $userId],
        );

        return (int) ($this->database->scalar(
            'SELECT id FROM wallets WHERE user_id = :user_id',
            ['user_id' => $userId],
        ) ?? 0);
    }

    /** @return array{id: int, balance: int, reserved: int, available: int, updated_at: string}|null */
    public function find(int $userId): ?array
    {
        $row = $this->database->selectOne(
            'SELECT id, balance, reserved, updated_at FROM wallets WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        if ($row === null) {
            return null;
        }

        $balance = (int) $row['balance'];
        $reserved = (int) $row['reserved'];

        return [
            'id' => (int) $row['id'],
            'balance' => $balance,
            'reserved' => $reserved,
            'available' => $balance - $reserved,
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Adds credits and writes the matching ledger entry.
     *
     * @param int $amount Positive number of credits to add.
     * @return int The balance after the change.
     */
    public function credit(
        int $userId,
        int $amount,
        string $type,
        string $description,
        ?string $reference = null,
        ?int $performedBy = null,
    ): int {
        if ($amount <= 0) {
            return $this->find($userId)['balance'] ?? 0;
        }

        return $this->database->transaction(function () use ($userId, $amount, $type, $description, $reference, $performedBy): int {
            $walletId = $this->lockWallet($userId);

            $this->database->run(
                'UPDATE wallets SET balance = balance + :amount WHERE id = :id',
                ['amount' => $amount, 'id' => $walletId],
            );

            $balanceAfter = (int) ($this->database->scalar(
                'SELECT balance FROM wallets WHERE id = :id',
                ['id' => $walletId],
            ) ?? 0);

            $this->recordTransaction($walletId, $userId, $amount, $type, $balanceAfter, $description, $reference, $performedBy);

            return $balanceAfter;
        });
    }

    /**
     * Removes credits, refusing to go below zero.
     *
     * @return int|null The balance after the change, or null when the wallet
     *                  did not have enough available credits.
     */
    public function debit(
        int $userId,
        int $amount,
        string $description,
        ?string $reference = null,
        ?int $performedBy = null,
        string $type = 'DEBIT',
    ): ?int {
        if ($amount <= 0) {
            return $this->find($userId)['balance'] ?? 0;
        }

        return $this->database->transaction(function () use ($userId, $amount, $description, $reference, $performedBy, $type): ?int {
            $walletId = $this->lockWallet($userId);

            // The precondition lives in the WHERE clause, so the check and the
            // decrement are one indivisible step.
            $changed = $this->database->run(
                'UPDATE wallets SET balance = balance - :amount
                 WHERE id = :id AND balance - reserved >= :amount',
                ['amount' => $amount, 'id' => $walletId],
            )->rowCount();

            if ($changed === 0) {
                return null;
            }

            $balanceAfter = (int) ($this->database->scalar(
                'SELECT balance FROM wallets WHERE id = :id',
                ['id' => $walletId],
            ) ?? 0);

            $this->recordTransaction($walletId, $userId, -$amount, $type, $balanceAfter, $description, $reference, $performedBy);

            return $balanceAfter;
        });
    }

    /**
     * Holds credits for a job that is about to start.
     *
     * No ledger entry is written: the credits are still the user's, they are
     * merely unavailable. The ledger entry comes at settlement.
     *
     * @return bool False when the wallet has too few available credits.
     */
    public function reserve(int $userId, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        return $this->database->run(
            'UPDATE wallets SET reserved = reserved + :amount
             WHERE user_id = :user_id AND balance - reserved >= :amount',
            ['amount' => $amount, 'user_id' => $userId],
        )->rowCount() === 1;
    }

    /**
     * Settles a reservation: charges what was used, frees the rest.
     *
     * Both columns move in one statement, so the reserved <= balance constraint
     * can never be momentarily violated.
     *
     * @param int $reservedAmount What was held when the job started.
     * @param int $spentAmount    What the job actually used (<= reserved).
     */
    public function settle(
        int $userId,
        int $reservedAmount,
        int $spentAmount,
        string $description,
        ?string $reference = null,
    ): void {
        $spentAmount = max(0, min($spentAmount, $reservedAmount));

        $this->database->transaction(function () use ($userId, $reservedAmount, $spentAmount, $description, $reference): void {
            $walletId = $this->lockWallet($userId);

            $this->database->run(
                'UPDATE wallets
                 SET balance = balance - :spent,
                     reserved = GREATEST(0, reserved - :reserved)
                 WHERE id = :id',
                ['spent' => $spentAmount, 'reserved' => $reservedAmount, 'id' => $walletId],
            );

            if ($spentAmount === 0) {
                return;
            }

            $balanceAfter = (int) ($this->database->scalar(
                'SELECT balance FROM wallets WHERE id = :id',
                ['id' => $walletId],
            ) ?? 0);

            $this->recordTransaction(
                $walletId,
                $userId,
                -$spentAmount,
                'DEBIT',
                $balanceAfter,
                $description,
                $reference,
                null,
            );
        });
    }

    /** Frees a reservation without charging anything (a cancelled job). */
    public function release(int $userId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->database->run(
            'UPDATE wallets SET reserved = GREATEST(0, reserved - :amount) WHERE user_id = :user_id',
            ['amount' => $amount, 'user_id' => $userId],
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function transactions(int $userId, Paginator $paginator, ?string $type = null): array
    {
        $bindings = ['user_id' => $userId];
        $where = 'user_id = :user_id';

        if ($type !== null && $type !== '') {
            $where .= ' AND type = :type';
            $bindings['type'] = $type;
        }

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM wallet_transactions WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT id, amount, type, balance_after, description, reference, created_at
             FROM wallet_transactions
             WHERE ' . $where . '
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function allTransactions(Paginator $paginator, ?string $type = null, ?int $userId = null): array
    {
        $conditions = ['1 = 1'];
        $bindings = [];

        if ($type !== null && $type !== '') {
            $conditions[] = 't.type = :type';
            $bindings['type'] = $type;
        }

        if ($userId !== null) {
            $conditions[] = 't.user_id = :user_id';
            $bindings['user_id'] = $userId;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) ($this->database->scalar(
            'SELECT COUNT(*) FROM wallet_transactions t WHERE ' . $where,
            $bindings,
        ) ?? 0);

        $items = $this->database->select(
            'SELECT t.id, t.user_id, t.amount, t.type, t.balance_after, t.description,
                    t.reference, t.created_at, u.uuid AS user_uuid, u.email AS user_email, u.name AS user_name
             FROM wallet_transactions t
             INNER JOIN users u ON u.id = t.user_id
             WHERE ' . $where . '
             ORDER BY t.id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $paginator->limit(), 'offset' => $paginator->offset()],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Lifetime totals for the wallet summary.
     *
     * Derived from the ledger rather than stored, so they cannot drift away
     * from the entries they describe.
     *
     * @return array{added: int, spent: int, refunded: int, adjusted: int, entries: int}
     */
    public function totalsForUser(int $userId): array
    {
        $row = $this->database->selectOne(
            "SELECT
                 COALESCE(SUM(CASE WHEN type = 'CREDIT' THEN amount ELSE 0 END), 0)          AS added,
                 COALESCE(SUM(CASE WHEN type = 'DEBIT' THEN -amount ELSE 0 END), 0)          AS spent,
                 COALESCE(SUM(CASE WHEN type = 'REFUND' THEN amount ELSE 0 END), 0)          AS refunded,
                 COALESCE(SUM(CASE WHEN type = 'ADJUSTMENT' THEN amount ELSE 0 END), 0)      AS adjusted,
                 COUNT(*)                                                                    AS entries
             FROM wallet_transactions
             WHERE user_id = :user_id",
            ['user_id' => $userId],
        );

        return [
            'added' => (int) ($row['added'] ?? 0),
            'spent' => (int) ($row['spent'] ?? 0),
            'refunded' => (int) ($row['refunded'] ?? 0),
            'adjusted' => (int) ($row['adjusted'] ?? 0),
            'entries' => (int) ($row['entries'] ?? 0),
        ];
    }

    /**
     * Credits spent per day, for the wallet's usage chart.
     *
     * @return list<array{date: string, credits: int}>
     */
    public function spendByDay(int $userId, int $days = 30): array
    {
        $days = max(1, min(120, $days));

        $rows = $this->database->select(
            "SELECT DATE(created_at) AS day, COALESCE(SUM(-amount), 0) AS credits
             FROM wallet_transactions
             WHERE user_id = :user_id
               AND type = 'DEBIT'
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)
             ORDER BY day",
            ['user_id' => $userId, 'days' => $days],
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['credits'];
        }

        // Every day in the window is present, so the chart has no gaps where
        // nothing was spent.
        $series = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', time() - ($offset * 86400));
            $series[] = ['date' => $date, 'credits' => $byDay[$date] ?? 0];
        }

        return $series;
    }

    /**
     * What each running job is currently holding.
     *
     * The reserved figure on its own is a number with no explanation; this is
     * what it is reserved for.
     *
     * @return list<array<string, mixed>>
     */
    public function activeHolds(int $userId): array
    {
        return $this->database->select(
            "SELECT j.id, j.uuid, j.status, j.credits_reserved, j.credits_spent,
                    j.total_items, j.processed_items, c.label AS checker_label
             FROM checker_jobs j
             INNER JOIN checker_types c ON c.id = j.checker_type_id
             WHERE j.user_id = :user_id
               AND j.status IN ('PENDING', 'QUEUED', 'PROCESSING')
               AND j.credits_reserved > 0
             ORDER BY j.id DESC
             LIMIT 25",
            ['user_id' => $userId],
        );
    }

    public function totalCreditsSpent(int $userId): int
    {
        return (int) abs((int) ($this->database->scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions
             WHERE user_id = :user_id AND type = 'DEBIT'",
            ['user_id' => $userId],
        ) ?? 0));
    }

    /**
     * Takes a row lock for the duration of the enclosing transaction.
     *
     * Reads that then write (credit, debit, settle) serialise here rather than
     * interleaving between the read and the update.
     */
    private function lockWallet(int $userId): int
    {
        $walletId = $this->database->scalar(
            'SELECT id FROM wallets WHERE user_id = :user_id FOR UPDATE',
            ['user_id' => $userId],
        );

        if ($walletId === null) {
            $walletId = $this->ensureWallet($userId);
        }

        return (int) $walletId;
    }

    private function recordTransaction(
        int $walletId,
        int $userId,
        int $amount,
        string $type,
        int $balanceAfter,
        string $description,
        ?string $reference,
        ?int $performedBy,
    ): void {
        $this->database->insert('wallet_transactions', [
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'amount' => $amount,
            'type' => $type,
            'balance_after' => $balanceAfter,
            'description' => substr($description, 0, 255),
            'reference' => $reference !== null ? substr($reference, 0, 191) : null,
            'performed_by' => $performedBy,
        ]);
    }
}
