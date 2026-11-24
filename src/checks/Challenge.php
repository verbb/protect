<?php
namespace verbb\protect\checks;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\base\ChallengeProvider;
use verbb\protect\base\PreparableCheckInterface;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\ProtectionContext;

class Challenge extends Check implements PreparableCheckInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'challenge';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Challenge;
    }

    // Properties
    // =========================================================================

    public ?ChallengeProvider $provider = null;

    // Public Methods
    // =========================================================================

    public function validateProvider(): void
    {
        if (!$this->provider) {
            $this->addError('provider', 'A challenge provider is required.');

            return;
        }

        Protect::getInstance()->getChallenges()->requireChallengeType($this->provider::class);

        if (!$this->provider->validate()) {
            foreach ($this->provider->getErrors() as $attribute => $errors) {
                $this->addErrors(['provider.' . $attribute => $errors]);
            }
        }
    }

    public function prepare(ProtectionContext $context): array
    {
        $challenge = array_diff_key($this->provider->prepare($context), array_flip($this->provider->secretAttributes()));

        return ['challenges' => [$challenge]];
    }

    public function check(ProtectionContext $context): CheckResult
    {
        $token = $context->evidence?->challenges[$this->provider::handle()] ?? '';

        return $this->providerResult($this->provider->verify($token, $context), $this->provider::class, FindingType::ChallengeFailed);
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
