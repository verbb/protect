<?php
namespace verbb\protect\models;

use verbb\protect\enums\CheckStatus;

class ProviderResult
{
    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly CheckStatus $status,
        public readonly string $code,
        public readonly array $metadata = [],
    ) {
    }
}
