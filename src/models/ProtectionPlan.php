<?php
namespace verbb\protect\models;

use verbb\protect\Protect;
use verbb\protect\base\Check;
use verbb\protect\enums\ContinuationStrategy;
use verbb\protect\errors\InvalidPlanException;

class ProtectionPlan
{
    // Properties
    // =========================================================================

    private array $_checks = [];

    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly ?string $handle = null,
        public readonly ContinuationStrategy $continuation = ContinuationStrategy::StopOnFailure,
        public readonly int $preparationTtl = 3600,
    ) {
    }

    public function add(Check $check): self
    {
        if (isset($this->_checks[$check::class])) {
            throw new InvalidPlanException('A check can appear only once in a plan.');
        }

        $this->_checks[$check::class] = $check;

        return $this;
    }

    public function checks(): array
    {
        return array_values($this->_checks);
    }

    public function validate(): void
    {
        if ($this->preparationTtl < 1 || $this->preparationTtl > 86400) {
            throw new InvalidPlanException('Preparation TTL is out of range.', ['preparationTtl' => ['Preparation TTL is out of range.']]);
        }

        $checks = Protect::getInstance()->getChecks();

        foreach ($this->_checks as $check) {
            $checks->requireCheckType($check::class);

            if ($check->enabled && !$check->validate()) {
                $details = [];

                foreach ($check->getErrors() as $attribute => $errors) {
                    $details[] = $attribute . ': ' . implode(' ', $errors);
                }

                throw new InvalidPlanException('Invalid configuration for ' . $check::handle() . '. ' . implode(' ', $details), $check->getErrors());
            }
        }
    }

    public function fingerprint(): string
    {
        $data = [$this->handle, $this->continuation->value, $this->preparationTtl];

        foreach ($this->_checks as $check) {
            $data[] = $check->fingerprintData();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
