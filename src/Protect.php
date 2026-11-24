<?php
namespace verbb\protect;

use verbb\protect\base\PluginTrait;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProtectionPreparation;
use verbb\protect\models\ProtectionResult;

use Craft;
use craft\helpers\Template;
use craft\web\View;

use InvalidArgumentException;

use verbb\base\base\Module;

use Twig\Markup;

class Protect extends Module
{
    // Constants
    // =========================================================================

    public const ID = 'verbb-protect';

    // Traits
    // =========================================================================

    use PluginTrait;

    // Properties
    // =========================================================================

    public string $handle = 'protect';

    public ?string $t9nCategory = 'protect';

    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        $this->_registerComponents();
    }

    public function prepare(ProtectionContext $context, ProtectionPlan $plan): ProtectionPreparation
    {
        return $this->getPreparations()->prepare($context, $plan);
    }

    public function check(ProtectionContext $context, ProtectionPlan $plan): ProtectionResult
    {
        return $this->getChecks()->run($context, $plan);
    }

    /**
     * The control panel fields for a plugin's challenge provider (`challenge`) or spam classifier
     * (`classifier`), named as `ProviderSettingsTrait` names them. Pass `settings` and `pluginHandle`,
     * which translates the labels and finds config file overrides. `secrets` holds what to show for a
     * saved secret instead of its value, `suggestEnvVars` defaults to whether the person is an admin,
     * and the classifier takes its own `instructions`. The template is read from its file rather than a
     * template root, because Protect boots lazily, after Craft may have collected its roots.
     */
    public function renderProviderSettings(string $section, array $variables): Markup
    {
        if (!in_array($section, ['challenge', 'classifier'], true)) {
            throw new InvalidArgumentException("Unknown provider settings section “{$section}”.");
        }

        $variables += [
            'secrets' => [],
            'suggestEnvVars' => (bool)Craft::$app->getUser()->getIdentity()?->admin,
        ];

        $template = (string)file_get_contents(__DIR__ . "/templates/settings/{$section}.html");

        return Template::raw(Craft::$app->getView()->renderString($template, $variables, View::TEMPLATE_MODE_CP));
    }

    public function stateKey(array $dimensions): string
    {
        return 'protect:' . hash_hmac('sha256', json_encode([Craft::$app->id, $dimensions], JSON_THROW_ON_ERROR), Craft::$app->getConfig()->getGeneral()->securityKey);
    }
}
