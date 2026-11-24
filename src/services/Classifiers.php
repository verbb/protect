<?php
namespace verbb\protect\services;

use verbb\protect\base\ClassifierProvider;
use verbb\protect\base\ComponentTypesTrait;
use verbb\protect\integrations\classifiers\Akismet;
use verbb\protect\integrations\classifiers\CleanTalk;
use verbb\protect\integrations\classifiers\OopSpam;

use craft\base\Component;

class Classifiers extends Component
{
    // Constants
    // =========================================================================

    public const EVENT_REGISTER_CLASSIFIER_TYPES = 'registerClassifierTypes';

    // Traits
    // =========================================================================

    use ComponentTypesTrait;

    // Properties
    // =========================================================================

    private ?array $_types = null;

    // Public Methods
    // =========================================================================

    public function getAllClassifierTypes(): array
    {
        return $this->_types ??= $this->_resolveTypes(self::EVENT_REGISTER_CLASSIFIER_TYPES, [
            Akismet::class,
            CleanTalk::class,
            OopSpam::class,
        ], ClassifierProvider::class);
    }

    public function requireClassifierType(string $class): void
    {
        $this->_requireType($class, $this->getAllClassifierTypes());
    }
}
