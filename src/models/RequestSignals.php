<?php
namespace verbb\protect\models;

use verbb\protect\helpers\Ip;

use craft\web\Request;

use yii\base\Request as BaseRequest;

class RequestSignals
{
    // Static Methods
    // =========================================================================

    /**
     * Collects only what a plan needs. Akismet's accuracy depends on the user agent and referrer,
     * so pass both when classifying. A console request has no client, so its signals are empty.
     */
    public static function fromRequest(BaseRequest $request, bool $ipAddress = true, bool $userAgent = false, bool $referrer = false): self
    {
        if (!$request instanceof Request) {
            return new self();
        }

        return new self(
            $ipAddress ? Ip::clientIp($request) : null,
            $userAgent ? $request->getUserAgent() : null,
            $referrer ? $request->getReferrer() : null,
        );
    }

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $referrer = null,
    ) {
    }
}
