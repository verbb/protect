<?php
namespace verbb\protect\errors;

use Throwable;

class InvalidPlanException extends ProtectException
{
    // Properties
    // =========================================================================

    public array $errors;

    // Public Methods
    // =========================================================================

    public function __construct(string $message = '', array $errors = [], int $code = 0, ?Throwable $previous = null)
    {
        $this->errors = $errors;

        parent::__construct($message, $code, $previous);
    }
}
