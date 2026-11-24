<?php
namespace verbb\protect\base;

use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\enums\FindingType;
use verbb\protect\models\CheckResult;
use verbb\protect\models\Finding;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use craft\base\Component;

use Closure;

abstract class Check extends Component implements CheckInterface
{
    // Traits
    // =========================================================================

    use FingerprintTrait;

    // Properties
    // =========================================================================

    public bool $enabled = true;
    public int $priority = 0;
    public ?Closure $condition = null;
    public ?ContinuationStrategy $continuation = null;

    // Public Methods
    // =========================================================================

    public function isApplicable(ProtectionContext $context): bool
    {
        return true;
    }

    // Protected Methods
    // =========================================================================

    protected function passed(?string $reason = null): CheckResult
    {
        return new CheckResult(static::class, CheckStatus::Passed, reason: $reason);
    }

    protected function failed(FindingType $type, string $code, string $summary = 'Protection check failed.', array $metadata = []): CheckResult
    {
        return new CheckResult(static::class, CheckStatus::Failed, [new Finding($type, static::class, $code, $summary, $metadata)]);
    }

    protected function providerResult(ProviderResult $result, string $provider, FindingType $adverseType): CheckResult
    {
        $findings = [];

        if ($result->status === CheckStatus::Failed) {
            $findings[] = new Finding($adverseType, static::class, $result->code, 'Provider reported an adverse finding.', $result->metadata, $provider);
        } elseif ($result->status === CheckStatus::Error) {
            // A configuration fault isn't an outage: consumers fail open on outages, not on this.
            $type = match ($result->code) {
                'provider-configuration' => FindingType::ConfigurationError,
                'invalid-provider-response' => FindingType::InvalidProviderResponse,
                'missing-required-signal' => FindingType::MissingRequiredSignal,
                default => FindingType::ProviderUnavailable,
            };

            $findings[] = new Finding($type, static::class, $result->code, 'Provider could not complete the check.', $result->metadata, $provider, true);
        }

        return new CheckResult(static::class, $result->status, $findings);
    }
}
