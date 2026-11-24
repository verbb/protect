<?php
namespace verbb\protect\base;

use verbb\protect\Protect;
use verbb\protect\services\Challenges;
use verbb\protect\services\Checks;
use verbb\protect\services\Classifiers;
use verbb\protect\services\Preparations;
use verbb\protect\services\State;

use Craft;

use verbb\base\LogTrait;
use verbb\base\helpers\Plugin;

trait PluginTrait
{
    // Static Methods
    // =========================================================================

    public static function getInstance(): Protect
    {
        // Protect is a Composer library, so nothing boots it automatically. A site can still
        // declare the module in its app config, which is how services get replaced.
        if ($module = Craft::$app->getModule(Protect::ID)) {
            /** @var Protect $module */
            return $module;
        }

        $module = new Protect(Protect::ID);
        Protect::setInstance($module);
        Craft::$app->setModule(Protect::ID, $module);

        return $module;
    }

    // Traits
    // =========================================================================

    use LogTrait;

    // Public Methods
    // =========================================================================

    public function getChecks(): Checks
    {
        return $this->get('checks');
    }

    public function getChallenges(): Challenges
    {
        return $this->get('challenges');
    }

    public function getClassifiers(): Classifiers
    {
        return $this->get('classifiers');
    }

    public function getPreparations(): Preparations
    {
        return $this->get('preparations');
    }

    public function getState(): State
    {
        return $this->get('state');
    }

    // Private Methods
    // =========================================================================

    private function _registerComponents(): void
    {
        Plugin::registerModule();

        // Protect's log holds codes only. Request context would dump the posted form, including
        // visitors' content and challenge tokens, after every error on a native form submission.
        Plugin::setFileLogging('protect', 'verbb\\protect\\*', ['logContext' => false]);

        $components = [
            'checks' => Checks::class,
            'challenges' => Challenges::class,
            'classifiers' => Classifiers::class,
            'preparations' => Preparations::class,
            'state' => State::class,
        ];

        // Defaults go beneath anything supplied through app or module config. This reads the
        // module's own definitions because `has()` also looks through to the application's.
        $this->setComponents(array_merge($components, $this->getComponents()));
    }
}
