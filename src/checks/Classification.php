<?php
namespace verbb\protect\checks;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\base\ClassifierProvider;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\Finding;
use verbb\protect\models\ProtectionContext;

class Classification extends Check
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'classification';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Classification;
    }

    // Properties
    // =========================================================================

    public ?ClassifierProvider $provider = null;

    // Public Methods
    // =========================================================================

    public function validateProvider(): void
    {
        if (!$this->provider) {
            $this->addError('provider', 'A classifier provider is required.');

            return;
        }

        Protect::getInstance()->getClassifiers()->requireClassifierType($this->provider::class);

        if (!$this->provider->validate()) {
            foreach ($this->provider->getErrors() as $attribute => $errors) {
                $this->addErrors(['provider.' . $attribute => $errors]);
            }
        }
    }

    public function isApplicable(ProtectionContext $context): bool
    {
        return $context->content !== null;
    }

    public function check(ProtectionContext $context): CheckResult
    {
        foreach ($this->provider->requiredSignals() as $signal) {
            $value = $context->signal($signal);

            if ($value === null || $value === '') {
                return new CheckResult(static::class, CheckStatus::Error, [new Finding(FindingType::MissingRequiredSignal, static::class, 'missing-required-signal', 'A required classifier signal is missing.', provider: $this->provider::class, error: true)]);
            }
        }

        return $this->providerResult($this->provider->classify($context), $this->provider::class, FindingType::SpamDetected);
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['provider'], 'validateProvider', 'skipOnEmpty' => false];

        return $rules;
    }
}
