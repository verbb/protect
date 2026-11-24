<?php
namespace verbb\protect\services;

use verbb\protect\base\PreparableCheckInterface;
use verbb\protect\errors\PreparationException;
use verbb\protect\models\BrowserManifest;
use verbb\protect\models\ProtectionContext;
use verbb\protect\models\ProtectionPlan;
use verbb\protect\models\ProtectionPreparation;

use Craft;
use craft\base\Component;

use Closure;
use JsonException;

class Preparations extends Component
{
    // Constants
    // =========================================================================

    // Nodes on a multi-node site can disagree by a moment, and a fast submit shouldn't be
    // rejected as expired because of it.
    private const CLOCK_TOLERANCE = 5;

    // Properties
    // =========================================================================

    public ?Closure $clock = null;

    // Public Methods
    // =========================================================================

    public function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    public function prepare(ProtectionContext $context, ProtectionPlan $plan): ProtectionPreparation
    {
        $plan->validate();

        $now = $this->now();
        $parts = [];

        foreach ($plan->checks() as $check) {
            if ($check->enabled && $check instanceof PreparableCheckInterface) {
                $parts = array_merge_recursive($parts, $check->prepare($context));
            }
        }

        $claims = [
            'v' => 1,
            'nonce' => bin2hex(random_bytes(24)),
            'issuedAt' => $now,
            'expiresAt' => $now + $plan->preparationTtl,
            'action' => $context->action,
            'subject' => $context->subject?->fingerprint() ?? '',
            'plan' => $plan->fingerprint(),
            'honeypot' => isset($parts['honeypot']) ? bin2hex(random_bytes(12)) : null,
        ];

        $token = base64_encode(Craft::$app->getSecurity()->hashData(json_encode($claims, JSON_THROW_ON_ERROR)));
        $minimumTime = (int)($parts['minimumTime'] ?? 0);

        $manifest = new BrowserManifest(
            instanceId: bin2hex(random_bytes(12)),
            preparation: $token,
            issuedAt: $now,
            expiresAt: $claims['expiresAt'],
            honeypot: $claims['honeypot'],
            javascript: (bool)($parts['javascript'] ?? false),
            challenges: $parts['challenges'] ?? [],
            notBefore: $minimumTime > 0 ? $now + $minimumTime : null,
        );

        return new ProtectionPreparation($manifest, $context->action);
    }

    public function verify(ProtectionContext $context, ProtectionPlan $plan): array
    {
        $token = $context->evidence?->preparation ?? '';

        if ($token === '' || strlen($token) > 8192 || ($decoded = base64_decode($token, true)) === false || ($json = Craft::$app->getSecurity()->validateData($decoded)) === false) {
            throw new PreparationException('preparation-invalid');
        }

        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PreparationException('preparation-invalid');
        }

        if (!is_array($claims) || ($claims['v'] ?? null) !== 1 || !is_int($claims['expiresAt'] ?? null) || !is_int($claims['issuedAt'] ?? null) || !is_string($claims['nonce'] ?? null)) {
            throw new PreparationException('preparation-invalid');
        }

        $now = $this->now();

        if ($claims['expiresAt'] <= $now || $claims['issuedAt'] > $now + self::CLOCK_TOLERANCE) {
            throw new PreparationException('preparation-expired');
        }

        if (($claims['action'] ?? null) !== $context->action || ($claims['subject'] ?? null) !== ($context->subject?->fingerprint() ?? '') || ($claims['plan'] ?? null) !== $plan->fingerprint()) {
            throw new PreparationException('preparation-mismatch');
        }

        return $claims;
    }
}
