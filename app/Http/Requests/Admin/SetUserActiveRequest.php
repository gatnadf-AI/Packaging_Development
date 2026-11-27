<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FSD-202 — the switch between a person who can work and a person who cannot.
 *
 * The confirmation flag is the requirement's own words: "with a warning listing
 * the affected count" is not a sentence about a button, it is a sentence about an
 * acknowledgement, so the POST that changes the state has to carry the fact that
 * the dialog was answered. A scripted call without it is refused rather than
 * quietly deactivating someone and closing their sessions.
 *
 * Both directions share one request and one route because they share one record
 * and one field; the audit verb and the session consequence are chosen by the
 * service from the value, not by which URL was posted to.
 */
class SetUserActiveRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('setActive', $this->userModel()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $goingInactive = ! (bool) $this->input('is_active');

        return [
            'is_active' => ['required', 'boolean'],
            'row_version' => ['required', 'integer', 'min:1'],
            // Only a move to inactive needs the acknowledgement; bringing someone
            // back opens nothing dangerous. Written as a branch rather than a
            // chained conditional rule so that the two cases are readable in the
            // list a reviewer checks against FSD-202.
            'confirm' => $goingInactive
                ? ['required', 'accepted']
                : ['nullable', 'boolean'],
        ];
    }

    public function goesInactive(): bool
    {
        return ! (bool) $this->validated('is_active');
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
            'is_active' => 'Status aktif',
            'row_version' => 'Versi data',
            'confirm' => 'Konfirmasi',
        ];
    }
}
