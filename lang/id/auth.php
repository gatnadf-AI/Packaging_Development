<?php

/*
 * The wording is the FSD message catalogue, not a translation of Laravel's
 * defaults. MSG-001 in particular must not say which half was wrong, which is
 * why `failed` is one sentence covering both cases and why the inactive-account
 * refusal at the login screen reuses it instead of explaining itself.
 */

return [
    'failed' => 'Email atau password tidak sesuai.',
    'throttle' => 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam :seconds detik.',
    'locked' => 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam :minutes menit.',
    'account_inactive' => 'Akun Anda sudah tidak aktif. Hubungi administrator.',
];
