<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\PasswordPolicy;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-1.3 — the account's own page.
 *
 * Three things are deliberately absent. The email address cannot be edited
 * here: FSD-201 makes it the administrator's record of a person, and the login
 * key, the RBAC grid and the audit trail all hang off it. There is no account
 * deletion: FSD-202 ends access by deactivating, so a document never loses the
 * name of the person who wrote it. And there is no email-verification block:
 * nobody signs up here, so nobody has an unverified address to chase.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'department' => $user->department?->name,
                'roles' => $user->roles->pluck('label')->values(),
            ],
            'languages' => [
                ['value' => 'id', 'label' => 'Bahasa Indonesia'],
                ['value' => 'en', 'label' => 'English'],
            ],
            'passwordRules' => PasswordPolicy::describe(),
            'sessionLifetimeMinutes' => (int) config('session.lifetime'),
        ]);
    }

    /**
     * Update the writable half of the profile (name, phone, language).
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill($request->validated());
        $user->save();

        return Redirect::route('profile.edit')->with('status', 'Profil Anda sudah disimpan.');
    }
}
