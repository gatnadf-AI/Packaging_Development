<?php

/*
 * TSD-1104 extends the MSG-001 discipline to the password flows: a reset
 * request answers the same way whether or not the address is a user, so
 * `neutral` is what the controllers return in both branches. `user` exists here
 * only so that no code path can print it — it names the account rather than the
 * outcome, which is exactly the leak being closed.
 */

return [
    'neutral' => 'Jika alamat email tersebut terdaftar pada sistem, tautan pengaturan password telah dikirim.',
    'sent' => 'Tautan pengaturan password telah dikirim.',
    'reset' => 'Password Anda sudah diatur ulang.',
    'token' => 'Tautan pengaturan password ini tidak berlaku atau sudah kedaluwarsa.',
    'user' => 'Jika alamat email tersebut terdaftar pada sistem, tautan pengaturan password telah dikirim.',
];
