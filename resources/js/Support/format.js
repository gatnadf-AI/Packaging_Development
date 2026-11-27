/**
 * TSD §2.3, stage 8 — the server sends raw values and one formatter displays
 * them. Three screens had written the same Indonesian date out by hand; a
 * timestamp that changes shape between the session list and the account detail
 * is the kind of thing that reads as two different clocks.
 */
export function moment(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
