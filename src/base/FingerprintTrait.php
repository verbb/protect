<?php
namespace verbb\protect\base;

use BackedEnum;

/**
 * Plan fingerprints bind a signed preparation to the configuration it was issued for, so they
 * cover every public setting except the ones a component declares as secret.
 */
trait FingerprintTrait
{
    // Public Methods
    // =========================================================================

    public function secretAttributes(): array
    {
        return [];
    }

    public function fingerprintData(): array
    {
        $data = ['class' => static::class];
        $secrets = $this->secretAttributes();

        foreach ($this->attributes() as $attribute) {
            if (!in_array($attribute, $secrets, true)) {
                $data[$attribute] = $this->_fingerprintValue($this->$attribute);
            }
        }

        return $data;
    }

    // Private Methods
    // =========================================================================

    private function _fingerprintValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn($item) => $this->_fingerprintValue($item), $value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            // Collaborators such as HTTP clients and condition callbacks don't identify a plan.
            return method_exists($value, 'fingerprintData') ? $value->fingerprintData() : null;
        }

        return $value;
    }
}
