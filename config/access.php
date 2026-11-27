<?php

declare(strict_types=1);

/*
 * Access rules that are configuration rather than code — TSD-PDS-003 §5.
 *
 * V-101 says the corporate domain is "enforced by Admin policy", and the signed
 * documents never name the screen or the table that holds that policy: FSD §5
 * gives F2 five screens (users, roles, matrix, announcements, audit) and none of
 * them is "allowed e-mail domains". Until the business settles that gap (raised
 * with the FSD owner), the list is an operator setting read from the environment,
 * which is where a value with no owning screen belongs. It is deliberately not a
 * migration, because a migration would make a deployment decision look like data.
 *
 * The list is a set of exact domains, not suffixes: 'ppm.test' accepts
 * someone@ppm.test and nothing deeper, so a lookalike such as
 * someone@ppm.test.attacker.net is refused rather than matched by substring.
 *
 * The default carries two domains, and the second one is not sloppiness. The
 * seeded bootstrap administrator holds admin@npd-ppm.local (BootstrapAdminSeeder
 * chose a non-routable TLD so the first-run credential could not postmail to a
 * real mailbox), and V-101 is enforced at the login screen as well as at account
 * creation. A deployment that listed only its public domain would lock out the
 * one account that exists before the directory does. An operator who has created
 * a real administrator address should drop the .local entry from the environment.
 */

return [

    'corporate_email_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => mb_strtolower(trim($domain)),
        explode(',', (string) env('PDS_CORPORATE_EMAIL_DOMAINS', 'npd-ppm.my.id,npd-ppm.local'))
    ), static fn (string $domain): bool => $domain !== '')),

    /*
    |--------------------------------------------------------------------------
    | Roles that may hold the supplier-scoped access
    |--------------------------------------------------------------------------
    | FSD-201 requires a supplier link whenever a supplier-scoped role is chosen,
    | and FSD-203 binds exactly one supplier to one account. The link cannot be
    | recorded before the supplier master exists (Phase 4), so the slug is listed
    | here as the one role the user screens treat differently. Adding a second
    | portal role is a data change, not a code change.
    */
    'supplier_scoped_roles' => ['supplier'],

    /*
    |--------------------------------------------------------------------------
    | Roles that may not be combined with another role
    |--------------------------------------------------------------------------
    | TSD-523: "admin plus any role referenced by an APPROVE chain step is
    | rejected". Chain steps are Phase 3 data, so nothing can be referenced yet
    | and the conservative reading is applied — admin stands alone. The list is
    | separate from the rule so that Phase 3 can narrow it to the approval roles
    | the chain table actually names without touching a service.
    */
    'exclusive_roles' => ['admin'],
];
