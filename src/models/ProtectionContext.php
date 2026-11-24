<?php
namespace verbb\protect\models;

use InvalidArgumentException;

class ProtectionContext
{
    // Properties
    // =========================================================================

    public readonly ?string $idempotencyKey;

    private array $_preparation = [];

    // Public Methods
    // =========================================================================

    /**
     * `$idempotencyKey` lets a retry-safe consumer present the same proof again for the same
     * attempt. An empty key means strict single use, because consumers often read it from the
     * request before validating it. The same key must never produce a second side effect, so
     * dedupe on it before any side effect and independently of any actor one creates. Two parallel
     * requests with one key both pass here, so the dedupe needs an atomic constraint.
     */
    public function __construct(
        public readonly string $action,
        public readonly ?ProtectionIdentity $identity = null,
        public readonly ?SubjectReference $subject = null,
        public readonly ?ProtectionContent $content = null,
        public readonly ?RequestSignals $request = null,
        public readonly ?ProtectionEvidence $evidence = null,
        public readonly array $metadata = [],
        ?string $idempotencyKey = null,
    ) {
        if ($action === '' || strlen($action) > 200) {
            throw new InvalidArgumentException('A stable action is required.');
        }

        foreach ($metadata as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Metadata must be scalar.');
            }
        }

        $this->idempotencyKey = $idempotencyKey === '' ? null : $idempotencyKey;
    }

    public function withPreparation(array $claims): self
    {
        $copy = clone $this;
        $copy->_preparation = $claims;

        return $copy;
    }

    public function prepared(string $key): mixed
    {
        return $this->_preparation[$key] ?? null;
    }

    public function signal(string $name): string|int|float|bool|null
    {
        if ($name === 'request.ipAddress') {
            return $this->request?->ipAddress;
        }

        if ($name === 'request.userAgent') {
            return $this->request?->userAgent;
        }

        if (str_starts_with($name, 'identity.')) {
            return $this->identity?->get(substr($name, 9));
        }

        return null;
    }
}
