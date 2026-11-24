<?php
namespace verbb\protect\base;

use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

interface ClassifierFeedbackInterface
{
    // Public Methods
    // =========================================================================

    public function feedback(ProtectionContext $context, bool $spam): ProviderResult;
}
