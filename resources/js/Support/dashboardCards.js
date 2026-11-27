/**
 * FSD-901 — the announcements card of the dasbor.
 *
 * The dashboard is described as a set of role cards rather than one layout, and
 * announcements is one of the cards it names. Which cards a person is offered is
 * not decided in the browser: ActiveAnnouncements has already removed what is not
 * addressed to this account and what this account already acknowledged, and
 * TSD-704 keeps that answer on the server so a URL cannot conjure a card. This
 * module takes the surviving list and answers the two questions a page would
 * otherwise each answer their own way.
 *
 * The first is whether to render anything. An empty list yields null rather than
 * a heading over an empty box — the same reason Navigation::for() drops a group
 * whose every entry was refused: a frame that says nothing is read as a fault,
 * and on a page whose whole purpose is "here is what concerns you" it is the more
 * misleading for looking deliberate.
 *
 * The second is how much of a long list to show. The banner in the shell is the
 * place an announcement has to be met, so the card is a reading list rather than
 * a second delivery mechanism; it shows the newest few and reports the rest as a
 * count. Truncating silently would make the dasbor disagree with the banner about
 * how many notices are live, which is the disagreement the requirement's single
 * source exists to prevent.
 */

const SHOW_MAX = 5;

export function announcementCard(announcements, max = SHOW_MAX) {
    const items = Array.isArray(announcements) ? announcements : [];

    if (items.length === 0) {
        return null;
    }

    return {
        label: 'Pengumuman',
        items: items.slice(0, max),
        hidden: Math.max(items.length - max, 0),
    };
}
