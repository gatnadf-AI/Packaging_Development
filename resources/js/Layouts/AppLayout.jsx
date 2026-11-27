import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import IdleWarning from '@/Components/IdleWarning';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The internal shell (TSD-702's AppLayout).
 *
 * The menu is not written here. HandleInertiaRequests hands down the groups this
 * account's grid opens, computed from the same permission set the route gate
 * consults (FSD-101, FSD-614), so an entry in the sidebar and a URL that answers
 * are one fact: there is no list of links in this file to forget to update when
 * a cell moves, and no `features.includes(...)` test that could start disagreeing
 * with the middleware about what READ means. TSD-704 allows a component to hide,
 * and that is the whole of what it does here — never to decide.
 *
 * Two renderings, one source: the desktop sidebar and the mobile panel both draw
 * the same tree through renderEntries(). A third menu would be the start of the
 * duplicated-menu failure TSD §7.1 names, and the reason the prototype's menus
 * drifted apart was that each one was typed again.
 *
 * Dashboard sits outside the tree because it is not granted: every authenticated
 * account has it, so there is no cell to consult. User Account, Sesi aktif and
 * Keluar stay in the account menu for the same reason. The menu's own wording is
 * the owner's, not the FSD's: the signed document names its screens in
 * Indonesian, and the labels below the shell come from Navigation's catalogue.
 */
export default function AppLayout({ header, children }) {
    const { user } = usePage().props.auth;
    const groups = usePage().props.nav ?? [];
    const announcements = usePage().props.announcements ?? [];
    const flash = usePage().props.flash ?? {};
    const warnMinutes = usePage().props.session?.warn_minutes ?? 25;
    const idleMinutes = usePage().props.session?.idle_minutes ?? 30;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    const home = {
        label: 'Dashboard',
        href: route('dashboard'),
        pattern: 'dashboard',
        screen: 'SCR-9.1',
    };

    const renderEntries = (responsive) => {
        const Entry = responsive ? ResponsiveNavLink : NavLink;
        const container = responsive ? 'space-y-1 pb-3 pt-2' : 'space-y-2';

        return (
            <div className={container}>
                <Entry
                    href={home.href}
                    active={route().current(home.pattern)}
                >
                    {home.label}
                </Entry>

                {groups.map((group) => (
                    <div key={group.group}>
                        <div
                            className={
                                responsive
                                    ? 'px-4 pt-4 text-xs font-semibold uppercase tracking-wider text-gray-400'
                                    : 'px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400'
                            }
                        >
                            {group.label}
                        </div>

                        {group.items.map((item) => (
                            <Entry
                                key={item.screen}
                                href={item.href}
                                active={route().current(item.pattern)}
                            >
                                {item.label}
                            </Entry>
                        ))}
                    </div>
                ))}
            </div>
        );
    };

    return (
        <div className="min-h-screen bg-gray-100 lg:flex lg:min-h-screen">
            <aside className="hidden w-64 shrink-0 flex-col border-r border-gray-200 bg-white lg:flex">
                <div className="flex h-16 items-center border-b border-gray-100 px-4">
                    <Link href={home.href}>
                        <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                    </Link>
                </div>

                <nav className="flex-1 overflow-y-auto px-3 py-4">
                    {renderEntries(false)}
                </nav>

                <div className="border-t border-gray-100 px-4 py-4">
                    <div className="truncate text-base font-medium text-gray-800">
                        {user.name}
                    </div>
                    <div className="truncate text-sm text-gray-500">
                        {user.email}
                    </div>
                </div>
            </aside>

            <div className="min-w-0 flex-1">
                <nav className="border-b border-gray-100 bg-white">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div className="flex items-center lg:hidden">
                            <Link href={home.href}>
                                <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                            </Link>
                        </div>

                        <div className="flex items-center">
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            User Account
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('profile.sessions.index')}
                                        >
                                            Sesi aktif
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Keluar
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>

                            <div className="-me-2 flex items-center lg:hidden">
                                <button
                                    onClick={() =>
                                        setShowingNavigationDropdown(
                                            (previousState) =>
                                                !previousState,
                                        )
                                    }
                                    className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                                >
                                    <svg
                                        className="h-6 w-6"
                                        stroke="currentColor"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            className={
                                                !showingNavigationDropdown
                                                    ? 'inline-flex'
                                                    : 'hidden'
                                            }
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth="2"
                                            d="M4 6h16M4 12h16M4 18h16"
                                        />
                                        <path
                                            className={
                                                showingNavigationDropdown
                                                    ? 'inline-flex'
                                                    : 'hidden'
                                            }
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth="2"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        className={
                            (showingNavigationDropdown ? 'block' : 'hidden') +
                            ' lg:hidden'
                        }
                    >
                        {renderEntries(true)}

                        <div className="border-t border-gray-200 pb-1 pt-4">
                            <div className="px-4">
                                <div className="text-base font-medium text-gray-800">
                                    {user.name}
                                </div>
                                <div className="text-sm font-medium text-gray-500">
                                    {user.email}
                                </div>
                            </div>

                            <div className="mt-3 space-y-1">
                                <ResponsiveNavLink href={route('profile.edit')}>
                                    User Account
                                </ResponsiveNavLink>
                                <ResponsiveNavLink
                                    method="post"
                                    href={route('logout')}
                                    as="button"
                                >
                                    Keluar
                                </ResponsiveNavLink>
                            </div>
                        </div>
                    </div>
                </nav>

                {flash.status && (
                    <div className="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                        <div className="rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                            {flash.status}
                        </div>
                    </div>
                )}

                {flash.error && (
                    <div className="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                        <div className="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                            {flash.error}
                        </div>
                    </div>
                )}

                {announcements.length > 0 && (
                    <div className="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                        <div className="space-y-2">
                            {announcements.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-start justify-between gap-4 rounded border border-amber-200 bg-amber-50 px-4 py-3"
                                >
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium text-amber-900">
                                            {item.title}
                                        </div>
                                        <div className="mt-1 whitespace-pre-line text-sm text-amber-800">
                                            {item.body}
                                        </div>
                                        {/* The instant the notice said it would go
                            up, not the instant it was read: a banner that arrives
                            on a shared screen has to be checkable against the
                            window the administrator typed. */}
                                        <div className="mt-1 text-xs text-amber-700">
                                            Tayang sejak{' '}
                                            {new Date(item.at).toLocaleString('id-ID', {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            })}
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                route('announcements.dismiss',
                                                    item.id),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    preserveState: false,
                                                },
                                            )
                                        }
                                        className="shrink-0 rounded border border-amber-300 bg-white px-2 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100"
                                    >
                                        Mengerti
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {header && (
                    <header className="bg-white shadow">
                        <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                            {header}
                        </div>
                    </header>
                )}

                <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </main>
            </div>

            <IdleWarning
                warnMinutes={warnMinutes}
                idleMinutes={idleMinutes}
            />
        </div>
    );
}
