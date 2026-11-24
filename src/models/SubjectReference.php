<?php
namespace verbb\protect\models;

use craft\base\ElementInterface;

class SubjectReference
{
    // Static Methods
    // =========================================================================

    public static function fromElement(ElementInterface $element): self
    {
        return new self($element::class, (string)$element->id, $element->siteId, $element->getUrl());
    }

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?int $siteId = null,
        public readonly ?string $url = null,
    ) {
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->type, $this->id, $this->siteId], JSON_THROW_ON_ERROR));
    }
}
