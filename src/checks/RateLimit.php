<?php
namespace verbb\protect\checks;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\helpers\Ip;
use verbb\protect\models\CheckResult;
use verbb\protect\models\Finding;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\RateLimitRule;

class RateLimit extends Check
{
    // Static Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'rate-limit';
    }

    public static function stage(): ExecutionStage
    {
        return ExecutionStage::Stateful;
    }

    // Properties
    // =========================================================================

    public array $rules = [];

    // Public Methods
    // =========================================================================

    public function validateRules(): void
    {
        if (!$this->rules) {
            $this->addError('rules', 'At least one rate rule is required.');
        }

        $handles = [];

        foreach ($this->rules as $rule) {
            if (!$rule instanceof RateLimitRule || !$rule->validate() || isset($handles[$rule->handle])) {
                $this->addError('rules', 'Rate rules must be valid and have unique handles.');

                continue;
            }

            $handles[$rule->handle] = true;

            foreach ($rule->dimensions as $dimension) {
                if (!is_string($dimension) || !preg_match('/^(request\.ipAddress|identity\.[a-zA-Z0-9_.-]+)$/', $dimension)) {
                    $this->addError('rules', 'Invalid rate rule dimension.');
                }
            }
        }
    }

    public function check(ProtectionContext $context): CheckResult
    {
        $resolved = [];

        // Resolve every rule first, so a missing dimension on a later rule charges nothing.
        foreach ($this->rules as $rule) {
            $dimensions = [];

            foreach ($rule->dimensions as $dimension) {
                $value = $context->signal($dimension);

                if ($value === null || $value === '') {
                    return new CheckResult(static::class, CheckStatus::Error, [new Finding(FindingType::MissingRequiredSignal, static::class, 'rate-identity-missing', 'A required rate dimension is missing.', error: true)]);
                }

                // One IPv6 visitor controls a whole /64, so the limit applies to the prefix.
                $dimensions[$dimension] = $dimension === 'request.ipAddress' ? Ip::bucket((string)$value) : $value;
            }

            $resolved[] = [$rule, $dimensions];
        }

        $protect = Protect::getInstance();
        $now = $protect->getPreparations()->now();
        $findings = [];

        foreach ($resolved as [$rule, $dimensions]) {
            $windowStart = intdiv($now, $rule->window) * $rule->window;
            $retryAfter = $windowStart + $rule->window - $now;

            $key = $protect->stateKey([
                'rate',
                $rule->fingerprintData(),
                $dimensions,
                $rule->includeAction ? $context->action : null,
                $rule->includeSubject ? $context->subject?->fingerprint() : null,
                $rule->includeSite ? $context->subject?->siteId : null,
                $windowStart,
            ]);

            if ($protect->getState()->increment($key, $retryAfter) > $rule->limit) {
                $findings[] = new Finding(FindingType::RateLimitExceeded, static::class, 'rate-limit-exceeded', 'Rate limit exceeded.', ['limit' => $rule->limit, 'window' => $rule->window, 'retryAfter' => $retryAfter]);
            }
        }

        return new CheckResult(static::class, $findings ? CheckStatus::Failed : CheckStatus::Passed, $findings);
    }

    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['rules'], 'validateRules', 'skipOnEmpty' => false];

        return $rules;
    }
}
