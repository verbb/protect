<?php
namespace verbb\protect\base;

use verbb\protect\Protect;
use verbb\protect\enums\CheckStatus;
use verbb\protect\models\ProviderResult;

use craft\base\Component;

use GuzzleHttp\ClientInterface;

abstract class ClassifierProvider extends Component implements ClassifierProviderInterface
{
    // Traits
    // =========================================================================

    use FingerprintTrait;

    // Properties
    // =========================================================================

    public ?ClientInterface $client = null;

    // Public Methods
    // =========================================================================

    public function requiredSignals(): array
    {
        return [];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Feedback runs outside `Checks::run()`, so its errors would go unrecorded otherwise.
     */
    protected function logFeedbackError(ProviderResult $result): void
    {
        if ($result->status === CheckStatus::Error) {
            Protect::error('Classifier feedback through ' . static::handle() . ' failed with ' . $result->code . '.');
        }
    }
}
