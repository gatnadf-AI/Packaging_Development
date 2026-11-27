<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Access\EmailIdentity;
use App\Models\User;
use App\Rules\CorporateEmailDomain;
use App\Rules\PhoneNumber;
use App\Http\Requests\Concerns\CatalogueMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-2.1 detail — editing an existing account.
 *
 * The same fields as creation, minus is_active (which belongs to
 * SetUserActiveRequest, where the sessions are closed with it and the audit verb
 * says so) and plus the version token the form contract requires (§2.5: "each
 * editable record carries a version token... silent overwrite does not exist").
 *
 * The e-mail may be corrected here. It is the administrator's record of a person
 * — V-101, and the reason SCR-1.3 will not let the account change it itself — so
 * the address moves only on a screen that audits it, and the unique rule excludes
 * this row rather than failing on the address the row already holds.
 */
class UpdateUserRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->userModel()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->userModel();

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:190',
                new CorporateEmailDomain(),
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            'phone' => ['nullable', 'string', 'max:32', new PhoneNumber()],
            'row_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => EmailIdentity::normalise(is_string($this->input('email')) ? $this->input('email') : null),
            'phone' => is_string($this->input('phone')) && trim($this->input('phone')) !== ''
                ? trim($this->input('phone'))
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function edits(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'department_id' => (int) $data['department_id'],
            'phone' => $data['phone'] ?? null,
        ];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated()['row_version'];
    }

    public function userModel(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'name' => 'Nama lengkap',
            'email' => 'Email perusahaan',
            'department_id' => 'Departemen',
            'phone' => 'No. telepon',
            'row_version' => 'Versi data',
        ];
    }
}
