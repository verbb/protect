<?php
namespace verbb\protect\models;

use verbb\protect\enums\IdentitySignalType;

use InvalidArgumentException;

class ProtectionIdentity
{
    // Properties
    // =========================================================================

    private readonly array $_signals;

    // Public Methods
    // =========================================================================

    /**
     * Keys are built-in `IdentitySignalType` values or `<plugin>.<name>`, such as
     * `['userId' => 5, 'myplugin.participant' => $hash]`. Null values are dropped.
     */
    public function __construct(array $signals = [])
    {
        $values = [];

        foreach ($signals as $key => $value) {
            if (!is_string($key) || (!IdentitySignalType::tryFrom($key) && !preg_match('/^[a-z][a-z0-9-]*\.[a-zA-Z][a-zA-Z0-9_-]*$/', $key))) {
                throw new InvalidArgumentException('Identity keys must be built-in signals or <plugin>.<name>.');
            }

            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Identity signals must be scalar.');
            }

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        $this->_signals = $values;
    }

    public function get(IdentitySignalType|string $type): string|int|float|bool|null
    {
        return $this->_signals[$type instanceof IdentitySignalType ? $type->value : $type] ?? null;
    }
}
