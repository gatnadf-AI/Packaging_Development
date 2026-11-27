<?php

declare(strict_types=1);

/*
 * FSD-PDS-002 §2.6 message catalogue, Indonesian.
 *
 * The wording of every row below is the approved text, copied rather than
 * rewritten: TSD §2.3 asks the FormRequest layer to return MSG codes "rather
 * than prose, so wording lives in one language file", and that only holds if
 * this file is the one place a sentence exists. A reviewer comparing a screen
 * with §2.6 should find the same characters in both.
 *
 * Placeholders keep the document's own brace form ({field}, {n}) rather than
 * Laravel's colon form, because §2.6 is what the client signed. App\Support\Msg
 * substitutes them, so nothing is allowed to print a raw brace.
 */

return [
    'MSG-001' => 'Email atau password tidak sesuai.',
    'MSG-004' => 'Sesi Anda akan berakhir dalam 5 menit. Perpanjang?',
    'MSG-005' => 'Waktu sesi habis. Silakan masuk kembali.',
    'MSG-010' => 'Anda tidak memiliki akses ke bagian ini.',
    'MSG-011' => 'Dokumen ini bukan milik Anda.',
    'MSG-020' => 'Data ini baru saja diubah orang lain. Muat ulang untuk melihat versi terbaru sebelum menyimpan.',
    'MSG-030' => 'Field bertanda * wajib diisi.',
    'MSG-031' => 'Format tidak sesuai: {field} {rule}.',
    'MSG-040' => 'Anda tidak bisa menyetujui dokumen yang Anda buat sendiri.',
    'MSG-041' => 'Alasan penolakan wajib diisi.',
    'MSG-042' => 'Dokumen sudah disetujui dan terkunci. Buat versi baru untuk mengubah.',
    'MSG-050' => 'T{level} belum lulus. Uji {missing} harus selesai dan lulus lebih dulu.',
    'MSG-051' => 'Kutipan belum disetujui untuk kemasan ini. Persetujuan bahan kemas belum bisa dibuat.',
    'MSG-052' => 'Form approval bahan kemas belum disetujui. Standar belum bisa dibuat.',
    'MSG-053' => 'Ada tahap yang belum selesai di jalur ini. Scale up belum bisa dicatat.',
    'MSG-054' => 'Konsep belum disetujui BOD. Tahap kemasan belum bisa dimulai.',
    'MSG-060' => 'File melebihi 20 MB atau tipe tidak diterima.',
    'MSG-061' => 'Baris {n} tidak bisa diimpor: {alasan}. {k} dari {t} baris berhasil.',
    'MSG-070' => 'Kategori masih dipakai {n} kemasan. Nonaktifkan, jangan hapus.',
    'MSG-080' => 'Dokumen sedang disiapkan untuk cetak.',
];
