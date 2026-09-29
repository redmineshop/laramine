<?php

namespace App\Domain\CustomFields;

use App\Domain\DomainException;

/**
 * Value, scope, visibility, or workflow failure for a custom field write.
 *
 * @phpstan-type FieldError array{id: int, name: string, messages: list<string>}
 */
class CustomFieldValidationException extends DomainException
{
    /**
     * @param  list<FieldError>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        $name = $errors[0]['name'] ?? 'Custom field';
        $message = $errors[0]['messages'][0] ?? 'Custom field validation failed.';

        parent::__construct($name.': '.$message);
    }
}
