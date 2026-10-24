<?php

namespace PryTax\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Class NoteTextRequired.
 *
 * Requires the text of an invoice note, which its level switches on (design.md §4.1): a level, whose note is on, owns
 * the note whole, so it takes no text from the levels below. Implicit, so it runs on an empty text too.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Rules
 */
class NoteTextRequired implements DataAwareRule, ValidationRule
{
    /**
     * Indicates whether the rule should be implicit.
     *
     * @var bool
     */
    public $implicit = true;

    /**
     * Data under validation.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * NoteTextRequired constructor.
     *
     * @param string $enabledField Path of the field, which switches the note on: `note_enabled`, `invoice_note_enabled`
     *                             or `invoice.note_enabled`.
     */
    public function __construct(protected string $enabledField) {}

    /**
     * Set the data under validation.
     *
     * @param array<string, mixed> $data
     *
     * @return static
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param string $attribute
     * @param mixed $value
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     *
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $enabled = filter_var(Arr::get($this->data, $this->enabledField), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === true && trim((string) $value) === '') {
            $fail(__('Enter the note text.'));
        }
    }
}
