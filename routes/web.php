<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\MatrixController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AnnouncementDismissController;
use App\Http\Controllers\Master\PackagingCategoryController;
use App\Http\Controllers\Master\PackagingItemController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Spec\SpecificationController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * There is no public landing page. FSD-201 describes a system whose users are
 * all named accounts, so the root address sends a visitor to the login screen
 * and an authenticated user to their own workspace, which is the same thing the
 * prototype's /welcome page was, minus the page.
 */
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

/*
 * Phase 1 landing. The real queue widgets are part of the workflow engine and
 * arrive in Phase 3; what is here now is the account's own facts, because a
 * user signing off this phase should be able to check something that is true
 * rather than admire an empty box.
 */
Route::get('/dashboard', function () {
    /** @var User $user */
    $user = request()->user();

    return Inertia::render('Dashboard', [
        'account' => [
            'name' => $user->name,
            'email' => $user->email,
            'department' => $user->department?->name,
            'roles' => $user->roles->pluck('label')->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ],
    ]);
})->middleware(['auth', 'active', 'password.changed', 'localize'])->name('dashboard');

/*
 * SCR-1.3 — the account's own facts. Email is not in the writable set: FSD-201
 * makes the address the administrator's record of a person, and the RBAC grid,
 * the audit trail and the login key all hang off it. There is no delete route
 * either; FSD-202 ends a person's access by deactivating the account, so the
 * documents they authored keep their author.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

/*
 * F2 — Administration (SCR-2.1). The group carries the matrix cell rather than
 * a role name: §6 gives admin.access to the Admin identity alone at FULL, and
 * TSD-504 makes the route list and that grid the same fact seen twice. Putting
 * the check in front of the controller means a person whose cell says NONE is
 * refused before UserService is ever constructed, and the denial is written to
 * access_logs by the middleware, which is what §5.3 asks for.
 *
 * Only the routes FSD §5 names for SCR-2.1 are here. The departments routes have
 * no screen id of their own (FSD-206 gives the reference lists a requirement and
 * leaves the account directory as the only place they are used), so they hang
 * off this screen's own cell rather than inventing a feature key the grid does
 * not have. SCR-2.2, SCR-2.3, SCR-2.4 and SCR-2.5 arrive with their phases.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize', 'feature:admin.access,FULL'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/roles', [UserController::class, 'assignRoles'])->name('users.roles');
        Route::patch('users/{user}/active', [UserController::class, 'setActive'])->name('users.active');
        Route::post('users/{user}/reset-link', [UserController::class, 'sendResetLink'])->name('users.reset-link');

        Route::get('departments/{department}/usage', [DepartmentController::class, 'usage'])
            ->name('departments.usage');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::patch('departments/{department}', [DepartmentController::class, 'update'])
            ->name('departments.update');
    });

/*
 * SCR-2.3 — the access matrix (FSD-204, FSD-205), and the reason its routes are
 * not in the group above: the grid is the one admin screen whose two halves are
 * different acts. Reading it is a READ-level thing — a reviewer asked to approve
 * a change has to be able to see the sheet — while writing a cell is a FULL-level
 * one. TSD §5.3 puts the cell in front of the route, so the split is drawn here
 * rather than inside a controller that would have to remember it.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::middleware('feature:admin.access,READ')->group(function (): void {
            Route::get('matrix', [MatrixController::class, 'index'])->name('matrix.index');
            Route::post('matrix/preview', [MatrixController::class, 'preview'])->name('matrix.preview');
        });

        Route::middleware('feature:admin.access,FULL')
            ->patch('matrix/cell', [MatrixController::class, 'update'])
            ->name('matrix.cell');
    });

/*
 * SCR-2.5 — the audit viewer (FSD-207 with BR-10). Its gate is a different
 * reading of the same cell, which is why it is not filed with the two above:
 * the trail has no write to protect, so admin.access at READ opens it and there
 * is no FULL route underneath it. FSD §6 gives the administration area one key,
 * and the level the screen needs is the level of the person who is allowed to
 * look. There is also deliberately no {audit} parameter — a row's before/after
 * pair is already in the list the screen loaded, so expansion is a browser act
 * and not a URL, and the only verb this address answers is GET.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize', 'feature:admin.access,READ'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    });

/*
 * SCR-2.4 — the announcements sheet (FSD-208), and the one route on this file
 * that is not in the administration area.
 *
 * The split is the matrix's split for the same reason: reading what is going up
 * is a READ act, and publishing, stopping or taking back a notice is a FULL one.
 * The dismissal below the group carries no cell at all, because the banner it
 * switches off is shown to the staff an announcement addresses — everybody — and
 * a control behind admin.access would leave the population that actually sees the
 * notice unable to dismiss it. See AnnouncementDismissController for that
 * argument in full.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::middleware('feature:admin.access,READ')
            ->get('announcements', [AnnouncementController::class, 'index'])
            ->name('announcements.index');

        Route::middleware('feature:admin.access,FULL')->group(function (): void {
            Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
            Route::patch('announcements/{announcement}/stop', [AnnouncementController::class, 'stop'])
                ->name('announcements.stop');
            Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
                ->name('announcements.destroy');
        });
    });

Route::middleware(['auth', 'active', 'password.changed', 'localize'])
    ->post('announcements/{announcement}/dismiss', [AnnouncementDismissController::class, 'store'])
    ->name('announcements.dismiss');

/*
 * F4 — the packaging master data (SCR-4.1, SCR-4.2).
 *
 * Both screens hang off one cell because FSD §6 has one key for them: the grid's
 * `master.category` covers "the lists a specification is described with", and an
 * item is the second half of that vocabulary rather than a separate permission.
 * Inventing `master.item` would put a cell on the Phase 1.3 grid that the signed
 * document does not contain.
 *
 * The READ/FULL split is the matrix screen's split for the same reason. Most of
 * the fourteen identities hold this cell at READ (FSD-401's lists are what an
 * assignment picker is filled from, and a picker cannot work if the person in
 * front of it cannot see the options), so reading is the common act and editing
 * is rnd_staff, rnd_manager and admin's.
 *
 * There is deliberately no DELETE under master/items. FSD-402 retires a pack and
 * keeps every record that named it; the only delete verb in this area is on a
 * category that nothing points at, and PackagingCategoryService refuses it with
 * MSG-070 and the live count when something does.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize'])
    ->prefix('master')
    ->name('master.')
    ->group(function (): void {
        Route::middleware('feature:master.category,READ')->group(function (): void {
            Route::get('categories', [PackagingCategoryController::class, 'index'])->name('categories.index');
            Route::get('items', [PackagingItemController::class, 'index'])->name('items.index');
        });

        Route::middleware('feature:master.category,FULL')->group(function (): void {
            Route::post('categories', [PackagingCategoryController::class, 'store'])->name('categories.store');
            Route::patch('categories/{category}', [PackagingCategoryController::class, 'update'])
                ->name('categories.update');
            Route::delete('categories/{category}', [PackagingCategoryController::class, 'destroy'])
                ->name('categories.destroy');

            Route::post('items', [PackagingItemController::class, 'store'])->name('items.store');
            Route::patch('items/{item}', [PackagingItemController::class, 'update'])->name('items.update');
            Route::patch('items/{item}/active', [PackagingItemController::class, 'setActive'])
                ->name('items.active');
        });
    });

/*
 * F4 — the product master (SCR-4.5).
 *
 * Its own cell rather than master.category, and the grid already says so: FSD §6
 * gives "Master produk" a row of its own, F to Mkt and R&D Staff and R to the
 * rest but BOD, because a product is the subject a specification is written
 * about rather than part of the vocabulary a pack is described with.
 *
 * READ opens the list and FULL writes it, which is the same split the two
 * packaging screens use and for the same reason: FSD-409 makes this the list a
 * specification and a design review pick a product name from, and a picker is
 * useless to a person who cannot see its options.
 *
 * There is deliberately no DELETE here either. FSD-812 only ever speaks of
 * deactivating a product, and G-13's "deletion is not, once referenced" cannot be
 * answered while no table can name a product — so the destructive verb is
 * withheld rather than shipped unguarded, and ProductScreenTest holds both halves
 * of that decision.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize'])
    ->prefix('master')
    ->name('master.')
    ->group(function (): void {
        Route::middleware('feature:master.product,READ')
            ->get('products', [ProductController::class, 'index'])
            ->name('products.index');

        Route::middleware('feature:master.product,FULL')->group(function (): void {
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::patch('products/{product}/active', [ProductController::class, 'setActive'])
                ->name('products.active');
        });
    });

/*
 * F4 — the packaging specification sheet (SCR-4.3).
 *
 * Its own cell because FSD §6 has one: `specification.manage` is held FULL by the
 * two QC managers' identities and QA manager, READ by nine more, and closed to
 * scm_staff and BOD. The READ half is what §6 grants so widely because a
 * specification is read by everybody an assignment later names — FSD-405 keeps
 * drafts and pending sheets visible in the list with their status while the
 * inspection picker, which is a different screen, withholds them.
 *
 * Reading is the list and the sheet; writing is the blank sheet, the first save,
 * the draft save and the revision. `create` sits behind FULL on purpose: a person
 * who cannot save the sheet should not be handed an empty one to type into, and
 * the sequence is untouched either way because the number is taken at the first
 * save rather than here.
 *
 * The blank sheet is declared above the parameter route so /spec/create is never
 * bound as a specification id. There is no DELETE under this prefix at all, and
 * SpecificationScreenTest asserts the route table says so: FSD-404's number is
 * not reused and a withdrawal is the approval engine's act, so no author's button
 * may remove a document from the history.
 */
Route::middleware(['auth', 'active', 'password.changed', 'localize'])
    ->prefix('spec')
    ->name('spec.')
    ->group(function (): void {
        Route::middleware('feature:specification.manage,FULL')
            ->get('create', [SpecificationController::class, 'create'])
            ->name('create');

        Route::middleware('feature:specification.manage,READ')->group(function (): void {
            Route::get('/', [SpecificationController::class, 'index'])->name('index');
            Route::get('{specification}', [SpecificationController::class, 'show'])->name('show');
        });

        Route::middleware('feature:specification.manage,FULL')->group(function (): void {
            Route::post('/', [SpecificationController::class, 'store'])->name('store');
            Route::patch('{specification}', [SpecificationController::class, 'update'])->name('update');
            Route::post('{specification}/revise', [SpecificationController::class, 'revise'])->name('revise');
        });
    });

require __DIR__.'/auth.php';
