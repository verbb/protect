<?php
namespace verbb\protect\checks;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\base\PreparableCheckInterface;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\ProtectionContext;

class MinimumTime extends Check implements PreparableCheckInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'minimum-time';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Passive;
    }

    // Properties
    // =========================================================================

    public int $seconds = 3;

    // Public Methods
    // =========================================================================

    public function prepare(ProtectionContext $context): array
    {
        return ['minimumTime' => $this->seconds];
    }

    public function check(ProtectionContext $context): CheckResult
    {
        $age = Protect::getInstance()->getPreparations()->now() - $context->prepared('issuedAt');

        return $age < $this->seconds ? $this->failed(FindingType::PassiveCheckFailed, 'minimum-time') : $this->passed();
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['seconds'], 'integer', 'min' => 0, 'max' => 300];

        return $rules;
    }
}
