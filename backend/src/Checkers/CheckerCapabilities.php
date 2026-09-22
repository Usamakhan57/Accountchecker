<?php

declare(strict_types=1);

namespace AccountCheck\Checkers;

/**
 * What a checker can do, as the API advertises it.
 *
 * `configured` is the honest signal the UI needs: false means no authorized
 * verification source is set up, so every check would return UNAVAILABLE. The
 * workspace shows that up front instead of letting someone spend credits
 * finding out.
 */
final class CheckerCapabilities
{
    /**
     * @param list<string> $inputFormats
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $category,
        public readonly string $inputKind,
        public readonly string $description,
        public readonly int $creditCost,
        public readonly int $maxBatchSize,
        public readonly bool $enabled,
        public readonly bool $configured,
        public readonly string $mode,
        public readonly array $inputFormats = ['paste', 'txt', 'csv'],
        public readonly bool $supportsMetadata = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'category' => $this->category,
            'input_kind' => $this->inputKind,
            'description' => $this->description,
            'credit_cost' => $this->creditCost,
            'max_batch_size' => $this->maxBatchSize,
            'enabled' => $this->enabled,
            'configured' => $this->configured,
            'mode' => $this->mode,
            'input_formats' => $this->inputFormats,
            'supports_metadata' => $this->supportsMetadata,
        ];
    }
}
