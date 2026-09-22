<?php

declare(strict_types=1);

namespace AccountCheck\Support;

/**
 * Server-side pagination parameters and envelope.
 *
 * Result sets can reach thousands of rows, so every list endpoint pages at the
 * database rather than filtering in PHP or in the browser.
 */
final class Paginator
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public static function fromInput(mixed $page, mixed $perPage): self
    {
        $pageNumber = is_numeric($page) ? (int) $page : 1;
        $size = is_numeric($perPage) ? (int) $perPage : self::DEFAULT_PER_PAGE;

        return new self(
            max(1, $pageNumber),
            min(self::MAX_PER_PAGE, max(1, $size)),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    /**
     * @param list<mixed> $items
     * @return array{items: list<mixed>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_more: bool}}
     */
    public function envelope(array $items, int $total): array
    {
        $totalPages = $this->perPage > 0 ? (int) ceil($total / $this->perPage) : 0;

        return [
            'items' => $items,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $this->page < $totalPages,
            ],
        ];
    }
}
