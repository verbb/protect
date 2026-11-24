<?php
namespace verbb\protect\base;

use verbb\protect\errors\DuplicateRegistrationException;
use verbb\protect\errors\InvalidComponentException;

use craft\events\RegisterComponentTypesEvent;

/**
 * Shared by the three type services: fires the registration event and rejects malformed
 * types and conflicting handles, so a bad extension fails where it's registered.
 */
trait ComponentTypesTrait
{
    // Private Methods
    // =========================================================================

    private function _resolveTypes(string $eventName, array $defaults, string $baseClass): array
    {
        $event = new RegisterComponentTypesEvent(['types' => $defaults]);
        $this->trigger($eventName, $event);
        $types = [];

        foreach ($event->types as $class) {
            if (!is_string($class) || !is_subclass_of($class, $baseClass)) {
                throw new InvalidComponentException('Registered class must extend ' . $baseClass . '.');
            }

            $handle = $class::handle();

            if (!preg_match('/^[a-z][a-z0-9-]*$/', $handle)) {
                throw new InvalidComponentException('Invalid handle for ' . $class . '.');
            }

            if (isset($types[$handle]) && $types[$handle] !== $class) {
                throw new DuplicateRegistrationException('Conflicting registration for ' . $handle . '.');
            }

            $types[$handle] = $class;
        }

        return $types;
    }

    private function _requireType(string $class, array $types): void
    {
        if (!in_array($class, $types, true)) {
            throw new InvalidComponentException($class . ' is not registered.');
        }
    }
}
