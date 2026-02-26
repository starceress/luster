<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Fluent field validator.
 *
 * Example:
 *   $v = new Validator($request->postAll());
 *   $v->field('email')->required()->email()->maxLength(255);
 *   $v->field('username')->required()->alphaNumericSpaces()->maxLength(50);
 *
 *   if ($v->fails()) {
 *       $errors = $v->errors(); // ['email' => ['Please enter a valid email address.']]
 *   }
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<string, mixed> $data Input data (e.g. $request->postAll()). */
    public function __construct(private readonly array $data) {}

    /**
     * Returns a fluent field validator for the named field.
     * Each call creates a fresh ValidatorField bound to this Validator.
     */
    public function field(string $name): ValidatorField
    {
        $value = isset($this->data[$name]) ? (string) $this->data[$name] : '';

        return new ValidatorField($name, $value, $this);
    }

    /**
     * Appends an error message for a field.
     *
     * @internal Called by ValidatorField only.
     */
    public function addFieldError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Returns true if the field already has at least one error.
     *
     * @internal Called by ValidatorField only.
     */
    public function hasFieldErrors(string $field): bool
    {
        return !empty($this->errors[$field]);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Returns all errors as ['field' => ['message', ...], ...].
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns a flat list of all error messages across all fields.
     *
     * @return list<string>
     */
    public function allErrors(): array
    {
        return empty($this->errors) ? [] : array_merge(...array_values($this->errors));
    }

    /**
     * Returns the first error message for the given field, or null if none.
     */
    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
