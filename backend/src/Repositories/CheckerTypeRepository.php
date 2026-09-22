<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

/**
 * Runtime checker settings.
 *
 * Rows are read on most checker requests, so the table is loaded once per
 * request and indexed by slug in memory.
 */
final class CheckerTypeRepository extends Repository
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->indexed()[strtolower(trim($slug))] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        foreach ($this->indexed() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->indexed());
    }

    /** @return list<array<string, mixed>> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->indexed(),
            static fn (array $row): bool => (bool) $row['is_enabled'],
        ));
    }

    public function idForSlug(string $slug): ?int
    {
        $row = $this->findBySlug($slug);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Updates the admin-editable fields.
     *
     * The column list is fixed here rather than taken from the request, so no
     * request body can reach a column this method does not name.
     *
     * @param array<string, mixed> $changes
     */
    public function update(int $id, array $changes): bool
    {
        $allowed = ['label', 'description', 'credit_cost', 'max_batch_size', 'rate_limit_per_minute', 'is_enabled', 'sort_order'];
        $data = array_intersect_key($changes, array_flip($allowed));

        if ($data === []) {
            return false;
        }

        $this->cache = null;

        return $this->database->update('checker_types', $data, ['id' => $id]) > 0;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        $this->cache = null;

        return $this->database->update('checker_types', ['is_enabled' => $enabled ? 1 : 0], ['id' => $id]) > 0;
    }

    /** @return array<string, array<string, mixed>> */
    private function indexed(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->database->select(
            'SELECT id, slug, label, category, input_kind, description, credit_cost,
                    max_batch_size, rate_limit_per_minute, is_enabled, sort_order
             FROM checker_types
             ORDER BY sort_order, id',
        );

        $indexed = [];
        foreach ($rows as $row) {
            $row['is_enabled'] = (bool) $row['is_enabled'];
            $indexed[(string) $row['slug']] = $row;
        }

        return $this->cache = $indexed;
    }
}
