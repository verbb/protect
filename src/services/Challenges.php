<?php
namespace verbb\protect\services;

use verbb\protect\base\ChallengeProvider;
use verbb\protect\base\ComponentTypesTrait;
use verbb\protect\integrations\challenges\FriendlyCaptcha;
use verbb\protect\integrations\challenges\Hcaptcha;
use verbb\protect\integrations\challenges\RecaptchaV3;
use verbb\protect\integrations\challenges\Turnstile;

use craft\base\Component;

class Challenges extends Component
{
    // Constants
    // =========================================================================

    public const EVENT_REGISTER_CHALLENGE_TYPES = 'registerChallengeTypes';

    // Traits
    // =========================================================================

    use ComponentTypesTrait;

    // Properties
    // =========================================================================

    private ?array $_types = null;

    // Public Methods
    // =========================================================================

    public function getAllChallengeTypes(): array
    {
        return $this->_types ??= $this->_resolveTypes(self::EVENT_REGISTER_CHALLENGE_TYPES, [
            Turnstile::class,
            RecaptchaV3::class,
            Hcaptcha::class,
            FriendlyCaptcha::class,
        ], ChallengeProvider::class);
    }

    public function requireChallengeType(string $class): void
    {
        $this->_requireType($class, $this->getAllChallengeTypes());
    }
}
