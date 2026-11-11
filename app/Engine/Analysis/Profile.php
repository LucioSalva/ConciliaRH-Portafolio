<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class Profile
{
    /**
     * @param list<Field> $fields
     * @param list<string> $required
     * @param list<string> $identifiers
     * @param list<string> $complementary
     * @param list<string> $keyFields
     */
    public function __construct(
        public readonly string $codigo,
        public readonly string $familia,
        public readonly string $tipoDocumento,
        public readonly string $extractor,
        public readonly array $fields = [],
        public readonly array $required = [],
        public readonly array $identifiers = [],
        public readonly ?string $amount = null,
        public readonly bool $conceptColumns = false,
        public readonly bool $multiSheet = false,
        public readonly array $complementary = [],
        public readonly bool $orderSignificant = false,
        public readonly ?string $variant = null,
        public readonly ?string $variantValue = null,
        public readonly bool $detailRequiresAmount = false,
        public readonly array $keyFields = [],
        public readonly bool $rowTotals = true,
    ) {
    }

    public function field(string $canon): ?Field
    {
        foreach ($this->fields as $f) {
            if ($f->canon === $canon) {
                return $f;
            }
        }
        return null;
    }
}
