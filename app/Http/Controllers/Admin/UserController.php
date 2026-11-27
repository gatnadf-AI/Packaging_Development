<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessService;
use App\Domain\Access\UserDirectory;
use App\Domain\Access\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRolesRequest;
use App\Http\Requests\Admin\SetUserActiveRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit\AuditHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-2.1 — the account directory, one screen with two faces: the list at
 * /admin/users and the detail at /admin/users/{user} (FSD §5 names both routes
 * for this screen id, so both live in this controller rather than in a second
 * one invented for a URL).
 *
 * TSD §2.3, stage 4: thin. Every method answers a request, asks one service to
 * act, and hands Inertia the props the screen renders. There is no SQL here, no
 * audit call, and no `hasRole('admin')` — a permission question in a controller
 * is a question the next controller answers differently, and FSD §6 is the only
 * place either is allowed to come from. Authorisation is Gate, which resolves
 * UserPolicy, which reads PermissionSet: one source, three layers deep.
 *
 * The per-row "can" flags are computed server-side for every row (TSD-704)
 * rather than derived from a role name in React. That is what lets the Phase 1.6
 * test walk the row menu and the route middleware cell by cell and report a
 * match: both are reading the same grant.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly AccessService $access,
        private readonly UserDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();
        $can = $actor->can('create', User::class);
        $selfKey = (int) $actor->getKey();

        $page = $this->directory->paginate($request->query());

        // FSD §2.4's row menu: Lihat is always there, the rest only where the
        // grant — and, for deactivation, where the row is not the reader's own.
        // The refusal itself is UserService's; drawing no button for your own row
        // is what keeps a person from having to be told.
        $page->getCollection()->transform(
            static fn (array $row): array => $row + [
                'can_edit' => $can,
                'can_deactivate' => $can && $row['id'] !== $selfKey,
                'can_send_link' => $can,
            ]
        );

        return Inertia::render('Admin/Users', [
            'screen' => 'SCR-2.1',
            'users' => $page,
            'filters' => $this->directory->resolvedFilters($request->query()),
            'columns' => [
                'searchable' => UserDirectory::SEARCHABLE,
                'sortable' => UserDirectory::SORTABLE,
                'defaultSort' => ['column' => 'name', 'direction' => 'asc'],
                'perPageOptions' => UserDirectory::PER_PAGE,
                'defaultPerPage' => UserDirectory::DEFAULT_PER_PAGE,
                'searchFloor' => UserDirectory::SEARCH_FLOOR,
            ],
            'reference' => $this->directory->referenceOptions(),
            'can' => [
                'create' => $can,
                // The class-level question the panel actually asks — may this
                // actor add a department at all — because FSD §6 gives departments
                // no feature key of their own (FSD-206 edits them from this
                // screen) and the same cell is the only honest answer.
                // DepartmentPolicy::update() answers the per-row question and
                // needs a Department instance, so it belongs to the routes;
                // asking it of the class name hands a string to a model
                // parameter and the whole screen dies with an ArgumentCountError.
                'manageDepartments' => $actor->can('create', Department::class),
            ],
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        $actor = $request->user();

        return Inertia::render('Admin/UserDetail', [
            'screen' => 'SCR-2.1',
            'user' => UserDirectory::row($user) + [
                'phone' => $user->phone,
                'can_edit' => $actor->can('update', $user),
                'can_assign_roles' => $actor->can('assignRoles', $user),
                // The switch is offered on the strength of the grant, and withheld
                // on your own row: UserService would refuse the act, and a button
                // that only ever produces an error is not a feature.
                'can_deactivate' => $actor->can('setActive', $user)
                    && (int) $actor->getKey() !== (int) $user->getKey(),
                'can_send_link' => $actor->can('sendResetLink', $user),
            ],
            // FSD-202 makes the confirm dialog enumerate what closing an account
            // touches, so the count has to be known before the request is made —
            // and it has to come from the same method the deactivation uses.
            'impact' => $this->users->impactOfDeactivating($user),
            'history' => AuditHistory::forRecord($user, 25),
            'reference' => $this->directory->referenceOptions(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $created = $this->users->create(
            $request->accountAttributes(),
            $request->roleSlugs(),
            $request->user()
        );

        return to_route('admin.users.show', $created->user)->with(
            'status',
            $created->linkDelivered
                // FSD-201: the person is told where the password comes from, so
                // the admin who has just typed one is not asked to invent one.
                ? 'Akun dibuat. Tautan sekali pakai untuk menentukan password sudah dikirim ke email yang bersangkutan.'
                // The account is real and the mail is not. Saying "dibuat" alone
                // would leave the person waiting for a message that never came,
                // so the screen says which half happened and points at the button
                // that retries the other half.
                : 'Akun dibuat, tetapi tautan reset belum berhasil dikirim. Gunakan tombol Kirim ulang tautan di halaman ini.'
        );
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->edits(), $request->user(), $request->expectedVersion());

        return back()->with('status', 'Data akun disimpan.');
    }

    public function assignRoles(AssignRolesRequest $request, User $user): RedirectResponse
    {
        $this->access->assignRoles(
            $user,
            $request->roleSlugs(),
            $request->user(),
            $request->expectedVersion()
        );

        return back()->with('status', 'Peran disimpan. Perubahan berlaku pada permintaan berikutnya.');
    }

    public function setActive(SetUserActiveRequest $request, User $user): RedirectResponse
    {
        $version = $request->expectedVersion();

        if ($request->goesInactive()) {
            $impact = $this->users->deactivate($user, $request->user(), $version);

            return back()->with('status', 'Akun dinonaktifkan; '.$impact['sessions'].' sesi login ditutup.');
        }

        $this->users->reactivate($user, $request->user(), $version);

        return back()->with('status', 'Akun diaktifkan kembali.');
    }

    /**
     * FSD-201's delivery, offered again: the link expires (TSD-1104, 60 minutes
     * in the default configuration) and a person who lost the mail is otherwise
     * locked out of a system by a screen that never existed for them.
     */
    public function sendResetLink(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('sendResetLink', $user);

        return $this->users->sendInitialLink($user, $request->user())
            ? back()->with('status', 'Tautan reset baru sudah dikirim ke email akun ini.')
            : back()->with('error', 'Tautan reset belum dapat dikirim. Periksa alamat email akun ini.');
    }
}
