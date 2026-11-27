<?php

/*
 * Only the keys this phase can actually reach are given Indonesian wording;
 * anything else falls through to the framework's English bundle rather than
 * inventing a translation nobody has asked for yet. The full catalogue lands
 * with the shell in package 1.5 (OQ-03: the interface is Indonesian).
 */

return [
    'required' => 'Field :attribute wajib diisi.',
    'email' => 'Format :attribute tidak sesuai.',
    'string' => ':attribute harus berupa teks.',
    'numeric' => ':attribute harus berupa angka.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'boolean' => ':attribute harus ya atau tidak.',
    'min' => [
        'string' => ':attribute minimal :characters karakter.',
        'numeric' => ':attribute minimal :min.',
        'array' => ':attribute harus memiliki paling sedikit :min item.',
    ],
    'max' => [
        'string' => ':attribute maksimal :characters karakter.',
        'numeric' => ':attribute maksimal :max.',
    ],
    'confirmed' => 'Konfirmasi :attribute tidak sesuai.',
    'current_password' => 'Password saat ini tidak sesuai.',
    'unique' => ':attribute sudah digunakan.',
    'exists' => ':attribute yang dipilih tidak tersedia.',
    'in' => ':attribute yang dipilih tidak tersedia.',

    'password' => [
        'letters' => ':attribute harus mengandung huruf.',
        'mixed' => ':attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => ':attribute harus mengandung angka.',
        'symbols' => ':attribute harus mengandung simbol.',
        'uncompromised' => ':attribute pernah muncul dalam kebocoran data. Pilih yang lain.',
    ],

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'konfirmasi password',
        'current_password' => 'password saat ini',
        'name' => 'nama',
        'phone' => 'nomor telepon',
        'department_id' => 'departemen',
        'roles' => 'identitas',
    ],
];
