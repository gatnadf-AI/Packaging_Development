<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Access\PermissionLevel;
use App\Domain\Access\PermissionSet;
use LogicException;

/**
 * FSD-101's menu, computed rather than compiled.
 *
 * "Menu renders only permitted entries; direct URL to a denied route yields
 * MSG-010" is one rule stated twice, and the way it breaks is a menu written by
 * hand: somebody changes a cell or moves a route to a different level, the grid
 * answers correctly, and the shell keeps offering a door that the same request
 * will refuse two hundred milliseconds later. So the tree is built from the
 * permission set the request was already resolved into (FSD-614's single source)
 * over the list below, and the front end receives an answer rather than a
 * vocabulary it would have to interpret — a client that filters the menu is a
 * second place that has to know what READ means.
 *
 * CATALOGUE is the closed list of screens that exist. It is not the feature
 * catalogue: FSD §6's 29 keys include steps that are not destinations
 * (`fab.check` is a decision, not a page), and a menu of them would be a list of
 * things this phase has no screen for. The rule for that case is already written
 * in FSD-901 for dashboard cards and it is applied here too — what has no screen
 * is absent, not greyed.
 *
 * Each entry names the level its route demands, which is not a menu decision but
 * the gate read from the other side: SCR-2.1 opens for a FULL holder because its
 * routes write, while SCR-2.3 and SCR-2.5 open for READ because looking at them
 * is the whole act. NavigationTest::testEveryMenuEntryIsGuardedByTheCellItClaims
 * reads that fact back off the route list, so the two statements of it cannot
 * drift.
 */
final class Navigation
{
    /**
     * @var list<array{group: string, group_label: string, label: string, screen: string, route: string, pattern: string, feature: string, level: string}>
     */
    public const CATALOGUE = [
        [
            'group' => 'Administration',
            'group_label' => 'User Management',
            'label' => 'User',
            'screen' => 'SCR-2.1',
            'route' => 'admin.users.index',
            'pattern' => 'admin.users.*',
            'feature' => 'admin.access',
            'level' => 'FULL',
        ],
        [
            'group' => 'Administration',
            'group_label' => 'User Management',
            'label' => 'Permission',
            'screen' => 'SCR-2.3',
            'route' => 'admin.matrix.index',
            'pattern' => 'admin.matrix.*',
            'feature' => 'admin.access',
            'level' => 'READ',
        ],
        [
            'group' => 'Administration',
            'group_label' => 'User Management',
            'label' => 'Announcement',
            'screen' => 'SCR-2.4',
            'route' => 'admin.announcements.index',
            'pattern' => 'admin.announcements.*',
            'feature' => 'admin.access',
            'level' => 'READ',
        ],
        [
            'group' => 'Administration',
            'group_label' => 'User Management',
            'label' => 'Audit Log',
            'screen' => 'SCR-2.5',
            'route' => 'admin.audit.index',
            'pattern' => 'admin.audit.*',
            'feature' => 'admin.access',
            'level' => 'READ',
        ],
        // F4's two reference lists, in the FSD's own area order after F2. One
        // cell covers both because §6 has one key for the vocabulary a
        // specification is described with — see the note in
        // PackagingCategoryService::FEATURE. Both open at READ: the grid gives
        // most identities READ here because the assignment and specification
        // pickers are filled from these lists, so looking is the common act and
        // editing belongs to the three identities that own the master.
        [
            'group' => 'Master data',
            'group_label' => 'Master Data',
            'label' => 'Kategori Bahan Kemas',
            'screen' => 'SCR-4.1',
            'route' => 'master.categories.index',
            'pattern' => 'master.categories.*',
            'feature' => 'master.category',
            'level' => 'READ',
        ],
        [
            'group' => 'Master data',
            'group_label' => 'Master Data',
            'label' => 'Item Bahan Kemas',
            'screen' => 'SCR-4.2',
            'route' => 'master.items.index',
            'pattern' => 'master.items.*',
            'feature' => 'master.category',
            'level' => 'READ',
        ],
        // FSD §6 gives the product master its own row rather than folding it in
        // with the packaging vocabulary: Mkt holds it F, and the grid's own order
        // puts it after the two packaging lists inside F4.
        [
            'group' => 'Master data',
            'group_label' => 'Master Data',
            'label' => 'Master Product',
            'screen' => 'SCR-4.5',
            'route' => 'master.products.index',
            'pattern' => 'master.products.*',
            'feature' => 'master.product',
            'level' => 'READ',
        ],
        // SCR-4.3 in the same F4 block rather than a group of its own: §5's area
        // heading is "Master data and packaging specification", and the owner's
        // words for that area's header is the one already above. The gate is the
        // READ half even though the screen writes, because FSD-405 makes the list
        // a place nine more identities look at — a draft they cannot edit is
        // still a draft they are entitled to see — and the shell's own rule is
        // that a door is offered when the URL behind it answers.
        [
            'group' => 'Master data',
            'group_label' => 'Master Data',
            'label' => 'Spesifikasi Bahan Pengemas',
            'screen' => 'SCR-4.3',
            'route' => 'spec.index',
            'pattern' => 'spec.*',
            'feature' => 'specification.manage',
            'level' => 'READ',
        ],
    ];

    /**
     * The groups one account is offered, in catalogue order.
     *
     * A group whose every entry was refused is left out entirely: a heading over
     * an empty box tells the person nothing and is the first thing a screenshot
     * of a mis-granted account shows.
     *
     * @return list<array{group: string, label: string, items: list<array{label: string, screen: string, pattern: string, href: string}>}>
     */
    public static function for(PermissionSet $set): array
    {
        $groups = [];

        foreach (self::CATALOGUE as $entry) {
            if (! self::opens($set, $entry['feature'], $entry['level'])) {
                continue;
            }

            $groups[$entry['group']] ??= ['group' => $entry['group'], 'label' => $entry['group_label'], 'items' => []];

            $groups[$entry['group']]['items'][] = [
                'label' => $entry['label'],
                'screen' => $entry['screen'],
                'pattern' => $entry['pattern'],
                'href' => route($entry['route']),
            ];
        }

        return array_values($groups);
    }

    /**
     * The same comparison EnsureFeatureAccess makes when it decides whether a
     * URL answers or redirects: a FULL holder passes a READ gate (TSD-502), and
     * nothing passes a FULL gate they cannot act through.
     */
    private static function opens(PermissionSet $set, string $feature, string $level): bool
    {
        return match (PermissionLevel::from($level)) {
            PermissionLevel::Full => $set->canAct($feature),
            PermissionLevel::Read => $set->canView($feature),
            PermissionLevel::None => throw new LogicException(
                "Navigation::CATALOGUE gating {$feature} with NONE is a configuration error."
            ),
        };
    }
}
