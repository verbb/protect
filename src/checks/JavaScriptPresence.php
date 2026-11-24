<?php
namespace verbb\protect\checks;

use verbb\protect\base\Check;
use verbb\protect\base\PreparableCheckInterface;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\ProtectionContext;

class JavaScriptPresence extends Check implements PreparableCheckInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'java-script-presence';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Passive;
    }

    // Public Methods
    // =========================================================================

    public function prepare(ProtectionContext $context): array
    {
        return ['javascript' => true];
    }

    public function check(ProtectionContext $context): CheckResult
    {
        return $context->evidence?->javascript ? $this->passed() : $this->failed(FindingType::PassiveCheckFailed, 'javascript-missing');
    }
}
