<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Access\EmailIdentity;
use App\Models\User;
use App\Rules\CorporateEmailDomain;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Http\Requests\Concerns\CatalogueMessages;

/**
 * FSD-201 — the fields an administrator may set when creating a person.
 *
 * The list is the requirement sentence, field by field: name, e-mail,
 * department, roles (multi), supplier link "only when a supplier-scoped role is
 * chosen", active. There is deliberately no password field, because the same
 * sentence says the password is set by the system and delivered as a single-use
 * link — a form that could type one would be the prototype's behaviour coming
 * back. The supplier link is not here either: FSD-203 binds it to the supplier
 * master, which is Phase 4, and AccessService refuses the role until then rather
 * than issuing an account that cannot be linked to anything.
 *
 * prepareForValidation() normalises the address before anything reads it, so the
 * unique rule, the exists rule, and UserService all face one shape of the value
 * (V-101).
 */
class StoreUserRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:190',
                new CorporateEmailDomain(),
                Rule::unique('users', 'email'),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            'phone' => ['nullable', 'string', 'max:32', new PhoneNumber()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'string',
                Rule::in(array_keys((array) config('matrix.identities', []))),
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
            // FSD-201 lists "active" among the creation fields: an account can
            // be prepared before the person starts, and it must not be forced to
            // go live the day it is typed.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The wording of any failure comes from the FSD catalogue rather than from
     * this file; see Concerns\CatalogueMessages (TSD §2.3, stage 2).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => EmailIdentity::normalise(is_string($this->input('email')) ? $this->input('email') : null),
            'phone' => is_string($this->input('phone')) && trim($this->input('phone')) !== ''
                ? trim($this->input('phone'))
                : null,
            'roles' => $this->input('roles') === null
                ? []
                : array_values(array_filter((array) $this->input('roles'), static fn (mixed $v): bool => $v !== '')),
        ]);
    }

    /**
     * The validated set, with the two defaults the requirement assumes.
     *
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'department_id' => (int) $data['department_id'],
            'phone' => $data['phone'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        /** @var list<string> $slugs */
        $slugs = array_values((array) $this->validated()['roles'] ?? []);

        return $slugs;
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
            'roles' => 'Peran',
            'is_active' => 'Status aktif',
        ];
    }
}
