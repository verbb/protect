<?php
namespace verbb\protect\models;

use verbb\protect\enums\FindingType;

use JsonSerializable;

class Finding implements JsonSerializable
{
    // Properties
    // =========================================================================

    public readonly array $metadata;

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly FindingType $type,
        public readonly string $check,
        public readonly string $code,
        public readonly string $summary,
        array $metadata = [],
        public readonly ?string $provider = null,
        public readonly bool $error = false,
    ) {
        // An allowlist prevents accidental raw provider/request data in built-in diagnostics.
        $this->metadata = array_filter(array_intersect_key($metadata, array_flip(['limit', 'window', 'retryAfter', 'score', 'threshold'])), fn($value) => is_int($value) || is_float($value));
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type->value,
            'check' => $this->check::handle(),
            'provider' => $this->provider ? $this->provider::handle() : null,
            'code' => $this->code,
            'metadata' => $this->metadata,
            'error' => $this->error,
        ];
    }
}
