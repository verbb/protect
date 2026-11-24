<?php
namespace verbb\protect\services;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\base\ComponentTypesTrait;
use verbb\protect\base\PreparableCheckInterface;
use verbb\protect\checks\Challenge;
use verbb\protect\checks\Classification;
use verbb\protect\checks\Honeypot;
use verbb\protect\checks\JavaScriptPresence;
use verbb\protect\checks\MinimumTime;
use verbb\protect\checks\RateLimit;
use verbb\protect\checks\ReplayProtection;
use verbb\protect\enums\CheckStatus;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\enums\ExecutionStage;
use verbb\protect\enums\FindingType;
use verbb\protect\errors\PreparationException;
use verbb\protect\errors\StateException;
use verbb\protect\models\CheckResult;
use verbb\protect\models\Finding;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProtectionResult;

use craft\base\Component;

class Checks extends Component
{
    // Constants
    // =========================================================================

    public const EVENT_REGISTER_CHECK_TYPES = 'registerCheckTypes';

    // Traits
    // =========================================================================

    use ComponentTypesTrait;

    // Properties
    // =========================================================================

    private ?array $_types = null;

    // Public Methods
    // =========================================================================

    public function getAllCheckTypes(): array
    {
        return $this->_types ??= $this->_resolveTypes(self::EVENT_REGISTER_CHECK_TYPES, [
            Honeypot::class,
            MinimumTime::class,
            JavaScriptPresence::class,
            ReplayProtection::class,
            RateLimit::class,
            Challenge::class,
            Classification::class,
        ], Check::class);
    }

    public function requireCheckType(string $class): void
    {
        $this->_requireType($class, $this->getAllCheckTypes());
    }

    public function run(ProtectionContext $context, ProtectionPlan $plan): ProtectionResult
    {
        $plan->validate();

        $start = hrtime(true);
        $checks = $plan->checks();
        usort($checks, fn(Check $a, Check $b) => [$a::stage()->value, $a->priority] <=> [$b::stage()->value, $b->priority]);

        $preparations = Protect::getInstance()->getPreparations();
        $results = $diagnostics = [];
        $stopped = $prepared = false;

        foreach ($checks as $check) {
            $handle = $check::handle();

            if (!$check->enabled || $stopped) {
                $diagnostics[] = ['check' => $handle, 'status' => $stopped ? 'bypassed' : 'disabled'];

                continue;
            }

            $checkStart = hrtime(true);

            if (($check->condition && !($check->condition)($context)) || !$check->isApplicable($context)) {
                $result = new CheckResult($check::class, CheckStatus::Skipped, reason: 'not-applicable');
            } else {
                try {
                    if ($check instanceof PreparableCheckInterface && !$prepared) {
                        $context = $context->withPreparation($preparations->verify($context, $plan));
                        $prepared = true;
                    }

                    $result = $check->check($context);
                } catch (PreparationException $e) {
                    $type = $check::stage() === ExecutionStage::Challenge ? FindingType::ChallengeFailed : FindingType::PassiveCheckFailed;
                    $result = new CheckResult($check::class, CheckStatus::Failed, [new Finding($type, $check::class, $e->getMessage(), 'Preparation must be refreshed.')]);
                } catch (StateException) {
                    $result = new CheckResult($check::class, CheckStatus::Error, [new Finding(FindingType::ProviderUnavailable, $check::class, 'state-unavailable', 'Protection state is unavailable.', error: true)]);
                }
            }

            $result->durationMs = (hrtime(true) - $checkStart) / 1e6;
            $results[] = $result;
            $diagnostics[] = ['check' => $handle, 'status' => $result->status->value, 'durationMs' => $result->durationMs];

            $strategy = $check->continuation ?? $plan->continuation;
            $stopped = ($result->status === CheckStatus::Failed && $strategy !== ContinuationStrategy::RunAll)
                || ($result->status === CheckStatus::Error && $strategy === ContinuationStrategy::StopOnFailureOrError);
        }

        $executionId = bin2hex(random_bytes(16));
        $this->_logErrors($results, $context->action, $executionId);

        return new ProtectionResult($results, $executionId, $plan->fingerprint(), (hrtime(true) - $start) / 1e6, $diagnostics, $context->action);
    }

    // Private Methods
    // =========================================================================

    /**
     * Each error is logged here once, so consumers don't need their own logging to find out
     * why a check couldn't run. Machine codes only: no content, identities or provider responses.
     */
    private function _logErrors(array $results, string $action, string $executionId): void
    {
        foreach ($results as $result) {
            if ($result->status !== CheckStatus::Error) {
                continue;
            }

            $codes = array_map(fn(Finding $finding) => $finding->code, iterator_to_array($result->findings));

            Protect::error('Protection check ' . $result->check::handle() . ' returned an error (' . (implode(', ', $codes) ?: 'no code') . ') for ' . $action . ', execution ' . $executionId . '.');
        }
    }
}
