<?php
namespace verbb\protect\checks;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\base\PreparableCheckInterface;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\ProtectionContext;
use verbb\protect\services\State;

class ReplayProtection extends Check implements PreparableCheckInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'replay-protection';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Stateful;
    }

    // Public Methods
    // =========================================================================

    public function prepare(ProtectionContext $context): array
    {
        return [];
    }

    public function check(ProtectionContext $context): CheckResult
    {
        $protect = Protect::getInstance();
        $key = $protect->stateKey(['replay', $context->prepared('nonce')]);
        $ttl = max(1, $context->prepared('expiresAt') - $protect->getPreparations()->now());

        // Only a hash of the consumer's key reaches the cache. The key stays out of the signed
        // claims and the plan fingerprint because it's chosen per attempt, not per form.
        $value = $context->idempotencyKey !== null ? hash('sha256', $context->idempotencyKey) : null;

        return match ($protect->getState()->claim($key, $ttl, $value)) {
            State::CLAIMED => $this->passed(),
            // Not proof the first attempt saved: the consumer's own dedupe still decides that.
            State::REPEATED => $this->passed('idempotent-retry'),
            default => $this->failed(FindingType::ReplayDetected, 'replay-detected'),
        };
    }
}
