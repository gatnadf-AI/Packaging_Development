// FSD-901 — the dashboard as a set of cards, one of which is announcements.
//
// Two rules are stated in that requirement and both are decisions rather than
// layout: a card the person has no business with is absent rather than greyed,
// and the announcements card is one of the named cards. The first is not this
// module's to make — ActiveAnnouncements has already dropped what is not
// addressed to this account and what this account already acknowledged, and
// TSD-704 keeps that answer on the server. What is decided here is the second,
// the one a hand-written page would get wrong in forty-one ways: an empty list
// must not leave a heading over an empty box, and a long one must not push the
// rest of the dasbor below the fold while pretending to be the whole story.
//
// Order is asserted rather than produced. The server's ordering is the one the
// banner uses, and a card that re-sorted the same rows would give one person two
// answers to "what is the newest notice".
import test from 'node:test';
import assert from 'node:assert/strict';

import { announcementCard } from '../../resources/js/Support/dashboardCards.js';

const notice = (id) => ({ id, title: `Pengumuman ${id}`, body: `Isi ${id}`, at: '2026-11-24T10:00:00+07:00' });

test('an account with nothing addressed to it gets no announcements card at all', () => {
    assert.equal(announcementCard([]), null);
});

test('a missing prop is the same absence, not a card that would crash the page', () => {
    assert.equal(announcementCard(undefined), null);
    assert.equal(announcementCard(null), null);
});

test('the card keeps the order it was handed', () => {
    const card = announcementCard([notice(3), notice(1), notice(2)]);

    assert.deepEqual(
        card.items.map((item) => item.id),
        [3, 1, 2],
    );
});

test('the card shows the newest few and says how many it left out', () => {
    const many = [11, 10, 9, 8, 7, 6, 5, 4].map(notice);
    const card = announcementCard(many, 5);

    assert.deepEqual(
        card.items.map((item) => item.id),
        [11, 10, 9, 8, 7],
    );
    assert.equal(card.hidden, 3);
});

test('a list no longer than the card shows all of it and hides nothing', () => {
    const card = announcementCard([notice(2), notice(1)], 5);

    assert.equal(card.items.length, 2);
    assert.equal(card.hidden, 0);
});
