<?php
namespace verbb\protect\models;

use verbb\protect\errors\PreparationException;

use craft\web\Request;

class ProtectionEvidence
{
    // Static Methods
    // =========================================================================

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->getBodyParam('__protect', []));
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || !in_array($data['version'] ?? 1, [1, '1'], true)) {
            throw new PreparationException('Unsupported protection evidence.');
        }

        foreach (['preparation', 'javascript'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new PreparationException('Malformed protection evidence.');
            }
        }

        foreach (['honeypot', 'challenges'] as $key) {
            if (isset($data[$key]) && (!is_array($data[$key]) || count($data[$key]) > 20)) {
                throw new PreparationException('Malformed protection evidence.');
            }

            foreach ($data[$key] ?? [] as $value) {
                if (!is_string($value) || strlen($value) > 8192) {
                    throw new PreparationException('Malformed protection evidence.');
                }
            }
        }

        return new self($data['preparation'] ?? '', $data['honeypot'] ?? [], ($data['javascript'] ?? '') === '1', $data['challenges'] ?? []);
    }

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly string $preparation = '',
        public readonly array $honeypot = [],
        public readonly bool $javascript = false,
        public readonly array $challenges = [],
    ) {
    }
}
