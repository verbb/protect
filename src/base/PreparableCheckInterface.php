<?php
namespace verbb\protect\base;

use verbb\protect\models\ProtectionContext;

interface PreparableCheckInterface
{
    // Public Methods
    // =========================================================================

    public function prepare(ProtectionContext $context): array;
}
