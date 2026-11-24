<?php
namespace verbb\protect\models;

use verbb\protect\web\assets\protect\ProtectAsset;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;

use JsonSerializable;

use Twig\Markup;

class ProtectionPreparation implements JsonSerializable
{
    // Public Methods
    // =========================================================================

    public function __construct(
        private readonly BrowserManifest $_manifest,
        public readonly string $action,
    ) {
    }

    public function browserManifest(): BrowserManifest
    {
        return $this->_manifest;
    }

    public function hiddenInputs(): array
    {
        $inputs = ['__protect[version]' => '1', '__protect[preparation]' => $this->_manifest->preparation];

        if ($this->_manifest->javascript) {
            $inputs['__protect[javascript]'] = '';
        }

        if ($this->_manifest->honeypot) {
            $inputs['__protect[honeypot][' . $this->_manifest->honeypot . ']'] = '';
        }

        return $inputs;
    }

    public function renderHiddenInputs(): Markup
    {
        $html = '';

        foreach ($this->hiddenInputs() as $name => $value) {
            if (str_contains($name, '[honeypot]')) {
                // `hidden` keeps this safe in any template, whether or not the consumer loads CSS.
                $html .= Html::tag('div', Html::input('text', $name, '', ['tabindex' => '-1', 'autocomplete' => 'off', 'aria-label' => 'Leave this field blank']), ['class' => 'protect-honeypot', 'aria-hidden' => 'true', 'hidden' => true]);
            } else {
                $html .= Html::hiddenInput($name, $value);
            }
        }

        return Template::raw($html);
    }

    /**
     * The hidden inputs plus a marker that Protect's bundled script mounts on the surrounding form,
     * for challenges and the JavaScript check on a form that posts normally. It registers that
     * script with the view, so the page needs no front-end build. A form your own script submits
     * should use `renderHiddenInputs()` and mount the npm package itself instead.
     *
     * Pass the page's CSP nonce when it has one. It goes on the bundled script's own tag, and the
     * script passes it on to the challenge scripts it loads. A fragment rendered without a full
     * page, such as an AJAX response, doesn't output registered scripts, so register `ProtectAsset`
     * on the host page instead. The script mounts a marked form whenever it arrives.
     */
    public function renderBrowserInputs(?string $nonce = null): Markup
    {
        $bundle = Craft::$app->getView()->registerAssetBundle(ProtectAsset::class);

        // Yii reads script options when the page ends, so this applies to the tag it outputs.
        if ($nonce !== null && $nonce !== '') {
            $bundle->jsOptions['nonce'] = $nonce;
        }

        $marker = Html::tag('div', '', ['hidden' => true, 'data' => ['verbb-protect' => Json::encode($this->_manifest)]]);

        return Template::raw($this->renderHiddenInputs() . $marker);
    }

    public function jsonSerialize(): array
    {
        return ['version' => 1, 'hiddenInputs' => $this->hiddenInputs(), 'manifest' => $this->_manifest, 'expiresAt' => $this->_manifest->expiresAt];
    }
}
