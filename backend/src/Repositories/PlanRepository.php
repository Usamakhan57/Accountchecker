<?php

declare(strict_types=1);

namespace AccountCheck\Repositories;

/**
 * Pricing plans.
 *
 * Reference data: a handful of rows, read on the pricing page and in the admin
 * panel. Prices are stored in minor units and never as a float, so what is read
 * back is exactly what was written.
 */
final class PlanRepository extends Repository
{
    private const SELECT = 'SELECT id, slug, name, description, price_cents, currency,
                                   credits, features, is_active, sort_order, created_at, updated_at
                            FROM plans';

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return array_map(
            [$this, 'hydrate'],
            $this->database->select(self::SELECT . ' WHERE is_active = 1 ORDER BY sort_order, id'),
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(
            [$this, 'hydrate'],
            $this->database->select(self::SELECT . ' ORDER BY sort_order, id'),
        );
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->database->selectOne(
            self::SELECT . ' WHERE slug = :slug',
            ['slug' => strtolower(trim($slug))],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->database->selectOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Applies an administrator's edits.
     *
     * Column names come from the allow-list below, never from the request, so
     * a caller cannot name a column the admin panel does not own. `features` is
     * re-encoded here rather than trusted as a string.
     *
     * @param array<string, mixed> $changes
     */
    public function update(int $id, array $changes): bool
    {
        $allowed = ['name', 'description', 'price_cents', 'credits', 'features', 'is_active', 'sort_order'];
        $data = [];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $changes)) {
                continue;
            }

            $data[$column] = $column === 'features'
                ? json_encode(array_values(array_map('strval', (array) $changes[$column])))
                : $changes[$column];
        }

        if ($data === []) {
            return false;
        }

        return $this->database->update('plans', $data, ['id' => $id]) > 0;
    }

    /**
     * Turns a stored row into the shape the rest of the application expects.
     *
     * `features` is a JSON column, so a malformed or null value has to degrade
     * to an empty list rather than reaching a caller as a string.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $features = [];

        if (is_string($row['features']) && $row['features'] !== '') {
            $decoded = json_decode($row['features'], true);

            if (is_array($decoded)) {
                $features = array_values(array_filter(
                    array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $decoded),
                    static fn (string $value): bool => $value !== '',
                ));
            }
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'price_cents' => (int) $row['price_cents'],
            'currency' => (string) $row['currency'],
            'credits' => (int) $row['credits'],
            'features' => $features,
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
