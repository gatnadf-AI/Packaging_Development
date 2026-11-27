<?php

/**
 * One-off generator: transcribes the FSD-PDS-002 §6 permission matrix out of the
 * signed FSD HTML into database/seeders/data/permission-matrix.php, so the seed
 * data cannot drift from the approved document. Not part of the runtime app.
 */

declare(strict_types=1);

$src = 'project documentation/FSD/FSD-PDS-002.html';
$dst = 'database/seeders/data/permission-matrix.php';

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML(file_get_contents($src));
$xpath = new DOMXPath($dom);

$roleLabels = [];
$rows = [];

foreach ($xpath->query('//table') as $table) {
    $heads = [];
    foreach ($xpath->query('.//thead/tr[1]/th|.//tr[1]/th', $table) as $th) {
        $heads[] = trim(preg_replace('/\s+/', ' ', $th->textContent));
    }
    if (! in_array('Mkt', $heads, true)) {
        continue;
    }
    $roleLabels = array_slice($heads, 1);
    foreach ($xpath->query('.//tbody/tr', $table) as $tr) {
        $cells = [];
        foreach ($xpath->query('.//td', $tr) as $td) {
            $cells[] = trim($td->textContent);
        }
        if (count($cells) === count($heads)) {
            $rows[] = $cells;
        }
    }
    break;
}

// Role label -> stable slug. Slugs are referenced by chain steps (§4.1) and by
// the matrix x route test, so they are frozen here even if the display label
// is later reworded.
$roleSlug = [
    'Mkt' => 'mkt',
    'R&D S' => 'rnd_staff',
    'R&D M' => 'rnd_manager',
    'PD S' => 'pd_staff',
    'PD M' => 'pd_manager',
    'QC Spv' => 'qc_supervisor',
    'QC Mgr' => 'qc_manager',
    'SCM S' => 'scm_staff',
    'SCM Mgr' => 'scm_manager',
    'QA Spv' => 'qa_supervisor',
    'QA Mgr' => 'qa_manager',
    'BOD' => 'bod',
];

$roleName = [
    'mkt' => 'Marketing',
    'rnd_staff' => 'R&D Staff',
    'rnd_manager' => 'R&D Manager',
    'pd_staff' => 'Packaging Development Staff',
    'pd_manager' => 'Packaging Development Manager',
    'qc_supervisor' => 'QC Supervisor',
    'qc_manager' => 'QC Manager',
    'scm_staff' => 'SCM Staff',
    'scm_manager' => 'SCM Manager',
    'qa_supervisor' => 'QA Supervisor',
    'qa_manager' => 'QA Manager',
    'bod' => 'Board of Directors',
];

// Feature label -> stable key + group. Keys mirror the FSD row text; the label
// keeps the Indonesian wording the users recognise from the source sheet.
$featureMeta = [
    'Project NPD — buat / ubah' => ['project.create', 'Project'],
    'Evaluasi konsep (NPD-01)' => ['project.concept', 'Project'],
    'Kebutuhan kemasan / assignment' => ['project.assignment', 'Project'],
    'Jadwal & tahapan' => ['project.schedule', 'Project'],
    'Gambar teknis' => ['drawing.manage', 'Drawing'],
    'Sampel / dummy review' => ['sample.review', 'Project'],
    'Master kategori & item' => ['master.category', 'Master data'],
    'Spesifikasi bahan pengemas (SBK)' => ['specification.manage', 'Specification'],
    'Impor spesifikasi' => ['specification.import', 'Specification'],
    'Inspeksi T0 / T1 / T2' => ['inspection.manage', 'QC'],
    'Uji aplikasi & transport' => ['testing.manage', 'QC'],
    'Standar warna' => ['colour.standard', 'QC'],
    'Master supplier' => ['supplier.master', 'Supplier'],
    'Penilaian supplier' => ['supplier.evaluation', 'Supplier'],
    'Kutipan — bandingkan' => ['quotation.compare', 'Supplier'],
    'Kutipan — terima / tolak' => ['quotation.decide', 'Supplier'],
    'Tinjauan desain marketing (S-04)' => ['design.review', 'Project'],
    'Sirkulasi artwork / colour range' => ['artwork.circulate', 'QC'],
    'Form Approval Bahan Kemas — buat' => ['fab.create', 'Approval'],
    'FAB — periksa (checker)' => ['fab.check', 'Approval'],
    'FAB — setujui' => ['fab.approve', 'Approval'],
    'Standar kemasan' => ['standard.manage', 'QC'],
    'Master produk' => ['master.product', 'Master data'],
    'Proofprint' => ['proofprint.manage', 'QC'],
    'Scale up' => ['scaleup.manage', 'Project'],
    'Arsip project' => ['project.archive', 'Project'],
    'Laporan & ekspor' => ['report.export', 'Reporting'],
    'Pengaturan akses / pengguna' => ['admin.access', 'Administration'],
    'Catatan audit' => ['audit.view', 'Administration'],
];

$features = [];
$sort = 0;
foreach ($rows as $cells) {
    $label = $cells[0];
    if (! isset($featureMeta[$label])) {
        fwrite(STDERR, "UNMAPPED FEATURE: {$label}\n");
        exit(1);
    }
    [$key, $group] = $featureMeta[$label];
    $grants = [];
    foreach ($roleLabels as $i => $rl) {
        $slug = $roleSlug[$rl] ?? null;
        if ($slug === null) {
            fwrite(STDERR, "UNMAPPED ROLE: {$rl}\n");
            exit(1);
        }
        $value = $cells[$i + 1];
        $level = match ($value) {
            'F' => 'FULL',
            'R' => 'READ',
            '—', '-', '' => 'NONE',
            default => 'INVALID',
        };
        if ($level === 'INVALID') {
            fwrite(STDERR, "Bad cell '{$value}' for {$label} / {$rl}\n");
            exit(1);
        }
        $grants[$slug] = $level;
    }
    $features[] = [
        'key' => $key,
        'label' => $label,
        'group' => $group,
        'sort' => ++$sort,
        'grants' => $grants,
    ];
}

// System identities (FSD §6.1) are not grid columns; they are appended here so
// the seed remains the single source of the effective matrix.
$admin = [
    // Full on administration and reference lists, read-only on business
    // records for support, and never an approver anywhere (segregation).
    'admin.access' => 'FULL',
    'audit.view' => 'FULL',
    'master.category' => 'FULL',
    'master.product' => 'FULL',
    'supplier.master' => 'FULL',
    'report.export' => 'READ',
];
$businessReadonly = [];
foreach ($features as $f) {
    if (! isset($admin[$f['key']])) {
        $businessReadonly[$f['key']] = 'READ';
    }
}
// Rights that must stay NONE for Admin: acting on business decisions.
foreach (['project.create', 'project.concept', 'project.assignment', 'fab.create', 'fab.check', 'fab.approve',
    'quotation.decide', 'quotation.compare', 'inspection.manage', 'testing.manage', 'specification.import',
    'artwork.circulate', 'proofprint.manage', 'scaleup.manage', 'standard.manage', 'design.review',
    'sample.review', 'supplier.evaluation'] as $blocked) {
    $businessReadonly[$blocked] = 'NONE';
}
$adminGrants = $admin + $businessReadonly;

// Supplier portal: own records only, no comparison of others (FSD-601, G-14).
$supplierGrants = [
    'project.create' => 'NONE',
    'project.concept' => 'NONE',
    'project.assignment' => 'NONE',
    'project.schedule' => 'NONE',
    'project.archive' => 'NONE',
    'drawing.manage' => 'READ',
    'sample.review' => 'NONE',
    'master.category' => 'NONE',
    'specification.manage' => 'READ',
    'specification.import' => 'NONE',
    'inspection.manage' => 'NONE',
    'testing.manage' => 'NONE',
    'colour.standard' => 'READ',
    'supplier.master' => 'NONE',
    'supplier.evaluation' => 'READ',
    'quotation.compare' => 'READ',
    'quotation.decide' => 'NONE',
    'design.review' => 'NONE',
    'artwork.circulate' => 'READ',
    'fab.create' => 'NONE',
    'fab.check' => 'NONE',
    'fab.approve' => 'NONE',
    'standard.manage' => 'NONE',
    'master.product' => 'NONE',
    'proofprint.manage' => 'READ',
    'scaleup.manage' => 'NONE',
    'report.export' => 'NONE',
    'admin.access' => 'NONE',
    'audit.view' => 'NONE',
];

$roles = [];
foreach ($roleLabels as $rl) {
    $roles[] = ['slug' => $roleSlug[$rl], 'name' => $roleName[$roleSlug[$rl]], 'label' => $rl, 'system' => false];
}
$roles[] = ['slug' => 'admin', 'name' => 'Administrator', 'label' => 'Admin', 'system' => true];
$roles[] = ['slug' => 'supplier', 'name' => 'Supplier Portal', 'label' => 'Supplier', 'system' => true];

// Per-role grant tables keyed by slug, for the seeder.
$byRole = [];
foreach ($roles as $r) {
    $slug = $r['slug'];
    $grants = [];
    foreach ($features as $f) {
        $grants[$f['key']] = match ($slug) {
            'admin' => $adminGrants[$f['key']] ?? 'NONE',
            'supplier' => $supplierGrants[$f['key']] ?? 'NONE',
            default => $f['grants'][$slug],
        };
    }
    $byRole[$slug] = $grants;
}

$export = [
    'source' => 'FSD-PDS-002 v0.2 §6 (transcribed from the approved FSD, do not hand-edit)',
    'roles' => $roles,
    'features' => array_map(static fn (array $f) => [
        'key' => $f['key'],
        'label' => $f['label'],
        'group' => $f['group'],
        'sort' => $f['sort'],
    ], $features),
    'grants' => $byRole,
];

@mkdir(dirname($dst), 0775, true);
$php = "<?php\n\n// GENERATED from FSD-PDS-002 §6 by tools/generate-permission-matrix.php on "
    . date('Y-m-d')
    . ".\n// 12 business roles + 2 system identities x " . count($features) . " features.\n"
    . "// Regenerate instead of editing by hand.\n\nreturn " . var_export($export, true) . ";\n";
$php = str_replace('stdClass::__set_state', '???', $php);
file_put_contents($dst, $php);

fwrite(STDOUT, sprintf("roles=%d features=%d grants=%d -> %s (%d bytes)\n", count($roles), count($features), count($byRole), $dst, strlen($php)));
