<?php
namespace verbb\protect\models;

use verbb\protect\enums\CheckStatus;

class CheckResult
{
    // Properties
    // =========================================================================

    public readonly FindingCollection $findings;

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly string $check,
        public readonly CheckStatus $status,
        array $findings = [],
        public readonly ?string $reason = null,
        public float $durationMs = 0,
    ) {
        $this->findings = new FindingCollection($findings);
    }
}
