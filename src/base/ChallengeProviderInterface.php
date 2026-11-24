<?php
namespace verbb\protect\base;

use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProviderResult;

use craft\base\ComponentInterface;

interface ChallengeProviderInterface extends ComponentInterface
{
    // Static Methods
    // =========================================================================

    public static function handle(): string;

    // Public Methods
    // =========================================================================

    public function prepare(ProtectionContext $context): array;
    public function verify(string $token, ProtectionContext $context): ProviderResult;
    public function secretAttributes(): array;
    public function fingerprintData(): array;
}
