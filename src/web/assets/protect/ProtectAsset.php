<?php
namespace verbb\protect\web\assets\protect;

use craft\web\AssetBundle;

/**
 * Protect's prebuilt browser bundle. It mounts protection on every form that holds the marker from
 * `ProtectionPreparation::renderBrowserInputs()`, holds a native submission until the evidence is
 * ready, and sets `window.VerbbProtect` for scripts of your own.
 */
class ProtectAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->js = ['protect.js'];
        $this->jsOptions = ['type' => 'module'];

        parent::init();
    }
}
