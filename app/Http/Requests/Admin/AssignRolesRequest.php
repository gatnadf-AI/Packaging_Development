<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FSD-201's "roles (multi)" as its own endpoint.
 *
 * Separate from the ordinary edit because the consequence is different: an edit
 * changes what a record says, this changes what a person is allowed to do. Keeping
 * them apart is what lets AccessService audit the change with its own verb, refuse
 * the TSD-523 combinations, and let the audit viewer filter on "permission change"
 * without also catching every spelling correction (AC-C10).
 */
class AssignRolesRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('assignRoles', $this->userModel()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // An empty set is allowed and means a person with no rights at all —
            // which is the state a departing employee is left in when their
            // account stays for the documents they wrote. FSD-201 requires roles
            // when *creating*, not when editing.
            'roles' => ['present', 'array'],
            'roles.*' => [
                'string',
                Rule::in(array_keys((array) config('matrix.identities', []))),
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
            'row_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'roles' => $this->input('roles') === null
                ? []
                : array_values(array_filter((array) $this->input('roles'), static fn (mixed $v): bool => $v !== '')),
        ]);
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        /** @var list<string> $slugs */
        $slugs = array_values((array) ($this->validated()['roles'] ?? []));

        return $slugs;
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
            'roles' => 'Peran',
            'row_version' => 'Versi data',
        ];
    }
}
