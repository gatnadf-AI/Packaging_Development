<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Access\PermissionSet;
use App\Http\Requests\Concerns\CatalogueMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-2.4 — publishing a notice (FSD-208).
 *
 * The shape rules are here and the meaning rules are in AnnouncementService, and
 * the line is drawn where TSD §2.3 draws it: a request can say that a title was
 * typed and that an audience named a type the screen knows; it cannot say whether
 * the roles named are ones the grid holds, whether the window has any room in it,
 * or whether the words are only whitespace. Those are facts about the record, and
 * the same record can arrive from a scheduler as from a browser.
 *
 * Which is also why an empty role list is not made "required" here: the request
 * would then answer with an error under audience.roles, and the sentence the
 * person needs — "an announcement to no roles in particular is not an
 * announcement" — belongs under the audience as a whole, which is what the
 * domain's refusal puts there.
 */
class StoreAnnouncementRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        /** @var PermissionSet $set */
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct('admin.access');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', 'string', Rule::in(['all', 'roles', 'departments'])],
            'audience.roles' => ['nullable', 'array'],
            'audience.departments' => ['nullable', 'array'],
            'publish_at' => ['required', 'date'],
            'expire_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The input as the domain wants it: the audience reduced to the keys its own
     * type uses, so a role list left in the form state from a previous choice
     * cannot arrive beside a department audience and be stored beside it.
     *
     * @return array<string, mixed>
     */
    public function announcementInput(): array
    {
        $data = $this->validated();

        return [
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'audience' => self::audience($data['audience']),
            'publish_at' => CarbonImmutable::parse($data['publish_at']),
            'expire_at' => isset($data['expire_at']) && $data['expire_at'] !== ''
                ? CarbonImmutable::parse($data['expire_at'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return array<string, mixed>
     */
    private static function audience(array $audience): array
    {
        $type = (string) $audience['type'];

        return match ($type) {
            'roles' => ['type' => $type, 'roles' => array_values(array_map(strval(...), (array) ($audience['roles'] ?? [])))],
            'departments' => ['type' => $type, 'departments' => array_values(array_map(intval(...), (array) ($audience['departments'] ?? [])))],
            default => ['type' => $type],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'title' => 'Judul pengumuman',
            'body' => 'Isi pengumuman',
            'audience' => 'Sasaran',
            'audience.type' => 'Jenis sasaran',
            'audience.roles' => 'Peran sasaran',
            'audience.departments' => 'Departemen sasaran',
            'publish_at' => 'Waktu tayang',
            'expire_at' => 'Waktu selesai',
        ];
    }
}
