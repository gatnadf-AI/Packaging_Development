<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Support\Msg;
use RuntimeException;

/**
 * An edit the domain will not perform, named for the field the screen should
 * mark up.
 *
 * TSD §2.3 puts field validation in the form request and business invariants in
 * the service, and this is the seam between them: a refusal of a rule that needs
 * data the browser never sent (who is asking, what the row held a moment ago)
 * still has to reach the user as an error under a field, because that is the
 * only way the FSD form contract §2.5 renders anything. One class for all of
 * them, so a reviewer can list every domain-level refusal in one place, and one
 * handler in bootstrap/app.php, so a new refusal cannot be forgotten there.
 *
 * The sentences are Indonesian because they are user-facing, and they are carried
 * with the refusal because the FSD's message catalogue (§2.6) covers permissions,
 * concurrency and validation but not role segregation: like V-101 and V-102, this
 * rule is stated with its own wording in the requirement text and has no MSG id.
 * That gap is reported to the FSD owner rather than papered over with a code that
 * does not exist.
 */
final class ChangeRefused extends RuntimeException
{
    private function __construct(
        private readonly string $attribute,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Which input the browser should mark up.
     */
    public function attribute(): string
    {
        return $this->attribute;
    }

    /**
     * TSD-523: an exclusive role (config/access.php lists admin) may not be held
     * alongside another role. The other slugs are named because an administrator
     * who is refused needs to know which half to remove, not merely that the
     * combination is wrong.
     *
     * @param  list<string>  $slugs
     */
    public static function heldWithExclusiveRole(array $slugs): self
    {
        $others = array_values(array_diff($slugs, (array) config('access.exclusive_roles', [])));

        return new self(
            'roles',
            'Akun administrator tidak dapat memegang peran bisnis sekaligus. '.
            'Cabut salah satunya terlebih dahulu ('.implode(', ', $others).').'
        );
    }

    /**
     * FSD-204 states the rule for the matrix ("Admin cannot revoke their own
     * admin.manage"). The same hole exists one level down: an administrator who
     * can strip the admin role from their own account can lock every future
     * administrator out of /admin with nobody left to undo it. Refusing it costs
     * nothing and protects the one account the system cannot recreate for itself.
     */
    public static function ownAdminRevoked(): self
    {
        return new self(
            'roles',
            'Anda tidak dapat mencabut peran administrator dari akun Anda sendiri.'
        );
    }

    /**
     * FSD-204's own sentence for the matrix: "Admin cannot revoke their own
     * admin.manage". Lowering the cell that governs the administration area for
     * a role the actor holds closes the screen the actor is standing in, and no
     * route left behind can reopen it — the route itself sits behind that cell.
     *
     * The feature is named admin.access because that is the key the seeded
     * matrix and the route middleware both carry; the FSD's prose says
     * admin.manage, which is one of the wording gaps reported to the document
     * owner rather than resolved by inventing a second key here.
     */
    public static function adminCellLoweredByItsHolder(string $roleSlug): self
    {
        return new self(
            'admin.access',
            'Anda tidak dapat menurunkan akses administrator pada peran Anda sendiri ('.$roleSlug.'). '
            .'Layar ini berada di balik sel tersebut, sehingga tidak ada jalan kembali setelahnya.'
        );
    }

    /**
     * FSD-201 with FSD-203: a supplier-scoped role may only be given to an
     * account bound to a supplier, and the binding needs the supplier master,
     * which is Phase 4. The alternative is to hand out the role and ship an
     * account that walks into the portal shell (FSD-102) with no supplier behind
     * it, which G-14 would then refuse on every route — a half-built identity
     * that reads like a bug rather than a phase that has not arrived.
     */
    public static function supplierLinkUnavailable(): self
    {
        return new self(
            'roles',
            'Peran portal pemasok belum dapat diberikan: data induk pemasok beserta '
            .'tautan pemasok untuk akun ini baru tersedia bersama modul pemasok.'
        );
    }

    /**
     * FSD-202 ends a person's access; it does not say an administrator may end
     * their own session and their own account from the screen that administers
     * accounts. Doing so is the same trap as revoking your own admin role: with
     * the account deactivated there is no route left that can reactivate it,
     * because the reactivate route is behind the access the account just lost.
     */
    public static function selfDeactivation(): self
    {
        return new self(
            'is_active',
            'Anda tidak dapat menonaktifkan akun Anda sendiri dari layar ini.'
        );
    }

    /**
     * FSD-208 states an announcement as a title, a body, an audience and a
     * window, and each of those has to be present for the record to mean
     * anything: a blank title is a banner nobody can identify, and an audience
     * is the whole of who sees it, so an empty one is not "nobody" but "unset".
     * These refusals belong to the service rather than the form request because
     * the rule is about the record, not about a keystroke — the same field could
     * arrive blank from a scheduler as from a browser.
     */
    public static function fieldBlank(string $attribute, string $label): self
    {
        return new self($attribute, $label.' wajib diisi.');
    }

    /**
     * FSD-208 enumerates three audiences (all staff, a set of roles, a set of
     * departments). Anything else is not a fourth kind of audience but a caller
     * that has lost the list, and storing it would put a record in the table
     * that no reader can interpret.
     */
    public static function audienceTypeUnknown(string $type): self
    {
        return new self(
            'audience',
            'Sasaran pengumuman tidak dikenal: "'.trim($type).'". Pilih seluruh '.
            'staff, daftar peran, atau daftar departemen.'
        );
    }

    /**
     * @param  string  $what  'roles' or 'departments', spelled for the screen
     */
    public static function audienceListEmpty(string $what): self
    {
        return new self(
            'audience',
            'Pengumuman dengan sasaran per '.$what.' harus menyebut minimal satu '.$what.'.'
        );
    }

    /**
     * The audience is stored as slugs and ids and resolved against the live role
     * and department lists (see the announcements migration), so an
     * unrecognised slug is not a display problem but a broken reference that
     * would silently narrow the audience later. Refusing at write time keeps the
     * stored document interpretable.
     *
     * @param  list<string>  $slugs
     */
    public static function audienceRolesUnknown(array $slugs): self
    {
        return new self(
            'audience',
            'Peran berikut tidak dikenal: '.implode(', ', $slugs).'.'
        );
    }

    /**
     * @param  list<int>  $ids
     */
    public static function audienceDepartmentsUnknown(array $ids): self
    {
        return new self(
            'audience',
            'Departemen berikut tidak dikenal: '.implode(', ', array_map(strval(...), $ids)).'.'
        );
    }

    /**
     * FSD-208's window is half-open: it goes up at publish_at and stops being
     * shown at expire_at. A finish before the start would be a window that never
     * opens, and the reader's query would answer "nothing is live" for reasons
     * nobody can see on the screen.
     */
    public static function windowInverted(): self
    {
        return new self(
            'expire_at',
            'Waktu selesai harus setelah waktu tayang.'
        );
    }

    /**
     * A window whose end has already gone by is a record that cannot be shown to
     * anybody, and FSD-208 offers no state for it: the row would sit in the list
     * looking published while the banner stays empty. The writer hears this as a
     * typo in the finish time, which is what it is.
     */
    public static function windowAlreadyPassed(): self
    {
        return new self(
            'expire_at',
            'Waktu selesai sudah berlalu, sehingga pengumuman ini tidak akan pernah tampil.'
        );
    }

    /**
     * Stopping an announcement sets its finish time to now, so doing it to one
     * that finished on its own would push that time forward and put the words
     * back on screen for everyone who never dismissed them. The operator means
     * the opposite of what the button would then do.
     */
    public static function windowAlreadyClosed(): self
    {
        return new self(
            'id',
            'Pengumuman ini sudah selesai tayang; menghentikannya lagi akan menayangkannya kembali.'
        );
    }

    /**
     * G-13 says a published record is stopped rather than deleted, because the
     * announcement has already reached people and deleting it would remove the
     * only copy of what they were told. This is the same argument FSD-202 makes
     * for accounts; here it is asserted on the window rather than a status flag.
     */
    public static function publishedRecordsAreNotDeleted(): self
    {
        return new self(
            'id',
            'Pengumuman yang sudah tayang tidak dapat dihapus. Hentikan tayang.'
        );
    }

    /**
     * G-13's "otherwise deactivate", said with the number that justifies it.
     *
     * FSD-401 makes a category with items under it undeletable, and FR-603 makes
     * that count live rather than remembered, so the sentence carries what the
     * same subquery the list column used answers at the moment of the delete.
     * The attribute is the row's id — the button that raised this was on that
     * row, and §2.5's history strip has to show the refusal under it.
     */
    public static function categoryStillReferenced(int $count): self
    {
        return new self('id', Msg::get('MSG-070', ['n' => $count]));
    }

    /**
     * G-12's locked half, in §2.6's own words.
     *
     * MSG-042 is the catalogue's sentence for editing a record that is approved
     * and closed, and its named action is the button the screen offers next
     * ("Buat versi baru"). The attribute is the row's id for the same reason
     * MSG-070's is: the control that raised the refusal sat on that row, and the
     * history strip has to show the refusal under it.
     */
    public static function documentIsLocked(): self
    {
        return new self('id', Msg::get('MSG-042'));
    }

    /**
     * G-12's other half, which the catalogue never wrote a sentence for.
     *
     * A draft is editable where it stands, so asking for a new version of one is
     * not a locked record to unblock but a caller that has confused the two
     * halves of the gate — and letting it through would fork an unapproved
     * document into a second version nobody submitted. §2.6 covers permissions,
     * concurrency and validation but not the state machine, so like V-101 and
     * V-102 this wording is the domain's own and is reported rather than given a
     * message id the document does not contain.
     */
    public static function draftIsStillEditable(): self
    {
        return new self(
            'id',
            'Draf masih dapat diubah langsung. Versi baru dibuat dari dokumen yang sudah diajukan.'
        );
    }
}
