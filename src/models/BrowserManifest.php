<?php
namespace verbb\protect\models;

use JsonSerializable;

class BrowserManifest implements JsonSerializable
{
    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly string $instanceId,
        public readonly string $preparation,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly ?string $honeypot,
        public readonly bool $javascript,
        public readonly array $challenges = [],
        public readonly ?int $notBefore = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $data = ['version' => 1, ...get_object_vars($this)];

        // Only present when the plan has a minimum time, so a form with none never waits.
        if ($this->notBefore === null) {
            unset($data['notBefore']);
        }

        return $data;
    }
}
