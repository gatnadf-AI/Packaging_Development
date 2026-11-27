<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\SetUserLocale;
use App\Rules\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SCR-1.3 / FSD-106 — the fields the account owns.
 *
 * Name (V-103), phone (V-104) and language are here. Email is not, and that is
 * a decision rather than an omission: the address is how the directory, the
 * login key and the audit trail name a person, so moving it between people has
 * to be an administrator's action with a record behind it (FSD-201/203).
 *
 * V-104 has no digit pattern in the FSD, so the rule chosen is the permissive
 * one an Indonesian office actually needs: an optional +, digits, spaces and
 * dashes, between 7 and 15 digits of substance (E.164's own limit), stored in
 * the 32-character column.
 */
class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'nullable',
                'string',
                'max:32',
                new PhoneNumber(),
            ],
            'locale' => ['required', 'string', 'in:'.implode(',', SetUserLocale::SUPPORTED)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locale.in' => 'Bahasa yang dipilih tidak tersedia.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'phone' => is_string($this->input('phone')) ? trim($this->input('phone')) : $this->input('phone'),
        ]);
    }
}
