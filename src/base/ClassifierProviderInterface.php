<?php
namespace verbb\protect\base;

use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use craft\base\ComponentInterface;

interface ClassifierProviderInterface extends ComponentInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string;

    // Public Methods
    // =========================================================================

    public function requiredSignals(): array;
    public function classify(ProtectionContext $context): ProviderResult;
    public function secretAttributes(): array;
    public function fingerprintData(): array;
}
