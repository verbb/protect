<?php
namespace verbb\protect\models;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class ProtectionContent
{
    // Properties
    // =========================================================================

    public readonly ?DateTimeImmutable $dateCreated;

    // Public Methods
    // =========================================================================

    /**
     * `$dateCreated` and `$edited` are for classifiers: leave the date null only when checking a
     * brand new submission. `$fields` carries scalar attributes for a custom classifier, and no
     * built-in provider sends them.
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?string $authorName = null,
        public readonly ?string $authorUrl = null,
        ?DateTimeInterface $dateCreated = null,
        public readonly bool $edited = false,
        public readonly array $fields = [],
    ) {
        $this->dateCreated = $dateCreated ? DateTimeImmutable::createFromInterface($dateCreated) : null;

        array_walk_recursive($fields, static function($value): void {
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Content fields must contain scalar values.');
            }
        });
    }
}
