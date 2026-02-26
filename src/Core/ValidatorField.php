<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Fluent field validator for a single named field.
 *
 * Instantiated by Validator::field() — do not construct directly.
 * Errors are written back to the parent Validator instance.
 *
 * If required() fails (field is empty), the field is marked "stopped"
 * and all subsequent rule checks are skipped, since format rules
 * are meaningless on an absent value.
 */
final class ValidatorField
{
    private bool $stopped = false;

    public function __construct(
        private readonly string    $name,
        private readonly string    $value,
        private readonly Validator $validator,
    ) {}

    // -----------------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------------

    public function required(string $message = ''): static
    {
        if (trim($this->value) === '') {
            $this->addError($message ?: "The {$this->name} field is required.");
            $this->stopped = true;
        }

        return $this;
    }

    public function email(string $message = ''): static
    {
        if ($this->stopped) {
            return $this;
        }

        if ($this->value !== '' && filter_var($this->value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($message ?: 'Please enter a valid email address.');
        }

        return $this;
    }

    public function url(string $message = ''): static
    {
        if ($this->stopped) {
            return $this;
        }

        if ($this->value !== '' && filter_var($this->value, FILTER_VALIDATE_URL) === false) {
            $this->addError($message ?: 'Please enter a valid URL (e.g. https://example.com).');
        }

        return $this;
    }

    public function maxLength(int $max, string $message = ''): static
    {
        if ($this->stopped) {
            return $this;
        }

        if (mb_strlen($this->value, 'UTF-8') > $max) {
            $this->addError($message ?: "The {$this->name} field must not exceed {$max} characters.");
        }

        return $this;
    }

    public function minLength(int $min, string $message = ''): static
    {
        if ($this->stopped) {
            return $this;
        }

        if (mb_strlen($this->value, 'UTF-8') < $min) {
            $this->addError($message ?: "The {$this->name} field must be at least {$min} characters.");
        }

        return $this;
    }

    /**
     * Allows only Unicode letters, Unicode digits, and whitespace.
     */
    public function alphaNumericSpaces(string $message = ''): static
    {
        if ($this->stopped) {
            return $this;
        }

        if ($this->value !== '' && !preg_match('/^[\pL\pN\s]+$/u', $this->value)) {
            $this->addError(
                $message ?: "The {$this->name} field may only contain letters, numbers, and spaces."
            );
        }

        return $this;
    }

    /**
     * Value must be one of the allowed strings (strict string comparison).
     *
     * An empty value is allowed to pass — combine with required() to reject empty values.
     *
     * @param list<string> $allowed
     */
    public function in(array $allowed, string $message = ''): static
    {
        if ($this->stopped) {
            return $this;
        }

        if ($this->value !== '' && !in_array($this->value, $allowed, true)) {
            $this->addError($message ?: "The {$this->name} field contains an invalid value.");
        }

        return $this;
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private function addError(string $message): void
    {
        $this->validator->addFieldError($this->name, $message);
    }
}
