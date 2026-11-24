<?php
namespace verbb\protect\base;

use verbb\protect\enums\ExecutionStage;
use verbb\protect\models\CheckResult;
use verbb\protect\models\ProtectionContext;

use craft\base\ComponentInterface;

interface CheckInterface extends ComponentInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string;
    public static function stage(): ExecutionStage;

    // Public Methods
    // =========================================================================

    public function isApplicable(ProtectionContext $context): bool;
    public function check(ProtectionContext $context): CheckResult;
    public function secretAttributes(): array;
    public function fingerprintData(): array;
}
