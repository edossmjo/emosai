<?php

/**
 * =====================================================================
 * EMOSai - Queries: Energy
 * Struktur:
 *   - SUMBER: LVMDP (LV800, LV400, LV4K)
 *   - KONSUMEN LINE: L1, L3, L4, L5, L10, L78*, L11* (L510 = summary)
 *   - KONSUMEN UTILITY: AF, UPS, AHU
 *   - LOSSES: Sumber - Konsumen
 *
 * * L78 = calculated (L510 - L5 - L10)
 * * L11 = belum konek (placeholder)
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

define('EMOSAI_WH_TO_KWH', 1000);

function wh_ke_kwh(float $wh): float
{
    return round($wh / EMOSAI_WH_TO_KWH, 2);
}
function kwh_ke_wh(float $kwh): float
{
    return $kwh * EMOSAI_WH_TO_KWH;
}

// =====================================================================
// DATA MENTAH
// =====================================================================

function get_energy_latest(string $line_kode, int $limit = 1): array
{
    $map = line_tabel_map();
    if (!isset($map[$line_kode])) return [];
    $tabel = $map[$line_kode];
    return db_fetch_all(
        "SELECT `id_pm`, `r_v3p`, `r_v1p`, `r_amp`, `kw`, `kwh`, `dt_create`
         FROM `{$tabel}` ORDER BY `dt_create` DESC LIMIT " . (int) $limit
    );
}

function get_energy_range(string $line_kode, string $dari, string $sampai, int $limit = 1000): array
{
    $map = line_tabel_map();
    if (!isset($map[$line_kode])) return [];
    $tabel = $map[$line_kode];
    return db_fetch_all(
        "SELECT `id_pm`, `r_v3p`, `r_v1p`, `r_amp`, `kw`, `kwh`, `dt_create`
         FROM `{$tabel}`
         WHERE `dt_create` BETWEEN ? AND ?
         ORDER BY `dt_create` ASC LIMIT " . (int) $limit,
        [$dari, $sampai]
    );
}

// =====================================================================
// HELPER: Ambil ringkasan 1 line dari tabel mentah
// =====================================================================

/**
 * Hitung ringkasan 1 line dari tabel mentah (Wh → kWh)
 */
function hitung_ringkasan_tabel(string $tabel, string $dari, string $sampai): array
{
    $dt_dari = $dari . ' 00:00:00';
    $dt_sampai = $sampai . ' 23:59:59';

    try {
        $awal = db_fetch_one(
            "SELECT `kwh`, `dt_create` FROM `{$tabel}`
             WHERE `dt_create` >= ? ORDER BY `dt_create` ASC LIMIT 1",
            [$dt_dari]
        );
        $akhir = db_fetch_one(
            "SELECT `kwh`, `kw`, `dt_create` FROM `{$tabel}`
             WHERE `dt_create` <= ? ORDER BY `dt_create` DESC LIMIT 1",
            [$dt_sampai]
        );

        $wh_awal = $awal ? (float) $awal['kwh'] : null;
        $wh_akhir = $akhir ? (float) $akhir['kwh'] : null;

        $delta_wh = null;
        if ($wh_awal !== null && $wh_akhir !== null && $wh_akhir > $wh_awal) {
            $delta_wh = $wh_akhir - $wh_awal;
        }

        return [
            'wh_awal'        => $wh_awal,
            'wh_akhir'       => $wh_akhir,
            'delta_wh'       => $delta_wh,
            'delta_kwh'      => $delta_wh !== null ? wh_ke_kwh($delta_wh) : null,
            'kw_terakhir'    => $akhir ? (float) $akhir['kw'] : null,
            'waktu_akhir'    => $akhir['dt_create'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log("[EMOSai ringkasan {$tabel}] " . $e->getMessage());
        return [
            'wh_awal' => null,
            'wh_akhir' => null,
            'delta_wh' => null,
            'delta_kwh' => null,
            'kw_terakhir' => null,
            'waktu_akhir' => null,
        ];
    }
}

// =====================================================================
// SUMBER: LVMDP (LV800, LV400, LV4K)
// =====================================================================

function get_lv_registers(): array
{
    return [
        'lv800' => ['label' => 'LV 800', 'warna' => 'primary', 'ikon' => 'bi-plug-fill'],
        'lv400' => ['label' => 'LV 400', 'warna' => 'success', 'ikon' => 'bi-plug-fill'],
        'lv4k'  => ['label' => 'LV 4K',  'warna' => 'info',    'ikon' => 'bi-plug-fill'],
    ];
}

/**
 * Ringkasan SUMBER (LVMDP) dari tabel lv800, lv400, lv4k
 */
function get_sumber_range(string $dari, string $sampai): array
{
    $registers = get_lv_registers();
    $hasil = [];

    foreach ($registers as $kode => $meta) {
        $r = hitung_ringkasan_tabel($kode, $dari, $sampai);
        $r['kode'] = $kode;
        $r['label'] = $meta['label'];
        $r['warna'] = $meta['warna'];
        $r['ikon'] = $meta['ikon'];
        $hasil[$kode] = $r;
    }

    return $hasil;
}

function get_sumber_total_kwh(string $dari, string $sampai): float
{
    $regs = get_sumber_range($dari, $sampai);
    $total = 0;
    foreach ($regs as $r) {
        if ($r['delta_kwh'] !== null) $total += $r['delta_kwh'];
    }
    return round($total, 2);
}

// =====================================================================
// KONSUMEN: LINE PRODUCTION
// =====================================================================

/**
 * Konfigurasi line konsumen
 * L78 = calculated, L11 = belum konek
 */
function get_line_konsumen_config(): array
{
    return [
        'l1'  => ['label' => 'L1',  'tipe' => 'real',       'warna' => 'primary'],
        'l3'  => ['label' => 'L3',  'tipe' => 'real',       'warna' => 'primary'],
        'l4'  => ['label' => 'L4',  'tipe' => 'real',       'warna' => 'primary'],
        'l5'  => ['label' => 'L5',  'tipe' => 'real',       'warna' => 'primary'],
        'l10' => ['label' => 'L10', 'tipe' => 'real',       'warna' => 'primary'],
        'l11' => ['label' => 'L11', 'tipe' => 'real',       'warna' => 'info'],  // ← BARU
        'l78' => ['label' => 'L78', 'tipe' => 'calculated', 'warna' => 'warning'],
    ];
}

/**
 * Ringkasan Line Production
 * - Ambil dari tabel mentah untuk l1, l3, l4, l5, l10, l11
 * - Hitung l78 = l510 - (l5 + l10)  [sementara, L11 belum konek]
 * - l11 = null (belum konek)
 */
/**
 * Ringkasan Line Production
 * Formula: L78 = L510 - (L5 + L10 + L11)
 */
function get_line_konsumen_range(string $dari, string $sampai): array
{
    $config = get_line_konsumen_config();
    $hasil = [];

    // 1. Ambil data Real meter (termasuk L11 yang sekarang sudah konek)
    $real_lines = ['l1', 'l3', 'l4', 'l5', 'l10', 'l11'];
    foreach ($real_lines as $kode) {
        $r = hitung_ringkasan_tabel($kode, $dari, $sampai);
        $r['kode'] = $kode;
        $r['label'] = $config[$kode]['label'] ?? strtoupper($kode);
        $r['tipe'] = 'real';
        $r['warna'] = $config[$kode]['warna'] ?? 'primary';
        $hasil[$kode] = $r;
    }

    // 2. Ambil L510 (sebagai patokan total)
    $l510 = hitung_ringkasan_tabel('l510', $dari, $sampai);
    $l510_kwh = $l510['delta_kwh'] ?? 0;

    // 3. Hitung L78 = L510 - L5 - L10 - L11
    $l78_kwh = null;
    $l78_wh = null;

    // Pastikan semua komponen (L510, L5, L10, L11) memiliki nilai
    if (
        $l510['delta_kwh'] !== null
        && isset($hasil['l5']['delta_kwh']) && $hasil['l5']['delta_kwh'] !== null
        && isset($hasil['l10']['delta_kwh']) && $hasil['l10']['delta_kwh'] !== null
        && isset($hasil['l11']['delta_kwh']) && $hasil['l11']['delta_kwh'] !== null
    ) {
        // Rumus: L78 = L510 - L5 - L10 - L11
        $l78_kwh = $l510_kwh - $hasil['l5']['delta_kwh'] - $hasil['l10']['delta_kwh'] - $hasil['l11']['delta_kwh'];

        // Jika hasil minus (karena anomali data), jadikan 0
        if ($l78_kwh < 0) $l78_kwh = 0;

        $l78_wh = kwh_ke_wh($l78_kwh);
    }

    // 4. Simpan hasil L78 ke array
    $hasil['l78'] = [
        'kode'        => 'l78',
        'label'       => 'L78',
        'tipe'        => 'calculated',
        'warna'       => $config['l78']['warna'] ?? 'warning',
        'wh_awal'     => null,
        'wh_akhir'    => null,
        'delta_wh'    => $l78_wh,
        'delta_kwh'   => $l78_kwh,
        'kw_terakhir' => null,
        'waktu_akhir' => null,
        'formula'     => 'L510 − L5 − L10 − L11', // Update teks formula
    ];

    // Simpan L510 info (untuk jaga-jaga jika dipanggil di view)
    $hasil['_l510_info'] = [
        'delta_kwh' => $l510['delta_kwh'],
        'delta_wh'  => $l510['delta_wh'],
    ];

    return $hasil;
}

/**
 * Total konsumen line
 * KARENA L11 SUDAH KONEK, MAKA L11 DIMASUKKAN KE DALAM TOTAL
 */
function get_line_konsumen_total_kwh(string $dari, string $sampai): float
{
    $data = get_line_konsumen_range($dari, $sampai);
    $total = 0;
    foreach ($data as $kode => $r) {
        if (strpos($kode, '_') === 0) continue; // skip _l510_info
        // HAPUS kondisi: if ($kode === 'l11') continue;
        // Agar L11 masuk hitungan total
        if ($r['delta_kwh'] !== null) $total += $r['delta_kwh'];
    }
    return round($total, 2);
}

/**
 * Total konsumen line (yang valid saja: exclude l11)
 */
// function get_line_konsumen_total_kwh(string $dari, string $sampai): float
// {
//     $data = get_line_konsumen_range($dari, $sampai);
//     $total = 0;
//     foreach ($data as $kode => $r) {
//         if (strpos($kode, '_') === 0) continue; // skip _l510_info
//         if ($kode === 'l11') continue; // belum konek
//         if ($r['delta_kwh'] !== null) $total += $r['delta_kwh'];
//     }
//     return round($total, 2);
// }

// =====================================================================
// KONSUMEN: UTILITY AUTO
// =====================================================================

function get_utility_registers(): array
{
    return [
        'AF'  => ['label' => 'Air Flow', 'warna' => 'primary', 'ikon' => 'bi-wind'],
        'UPS' => ['label' => 'UPS',      'warna' => 'success', 'ikon' => 'bi-battery-charging'],
        'AHU' => ['label' => 'AHU',      'warna' => 'info',    'ikon' => 'bi-fan'],
        // TAMBAHKAN INI:
        'CHILLER' => ['label' => 'Chiller', 'warna' => 'warning', 'ikon' => 'bi-snow'],
    ];
}

function get_utility_range(string $dari, string $sampai): array
{
    $registers = get_utility_registers();
    $hasil = [];
    $dt_dari = $dari . ' 00:00:00';
    $dt_sampai = $sampai . ' 23:59:59';

    foreach ($registers as $kode => $meta) {
        try {
            $awal = db_fetch_one(
                "SELECT `{$kode}` AS v FROM `utility` WHERE `dt_create` >= ? ORDER BY `dt_create` ASC LIMIT 1",
                [$dt_dari]
            );
            $akhir = db_fetch_one(
                "SELECT `{$kode}` AS v, `dt_create` FROM `utility` WHERE `dt_create` <= ? ORDER BY `dt_create` DESC LIMIT 1",
                [$dt_sampai]
            );
            $wh_awal = $awal ? (float) $awal['v'] : null;
            $wh_akhir = $akhir ? (float) $akhir['v'] : null;
            $delta_wh = null;
            if ($wh_awal !== null && $wh_akhir !== null && $wh_akhir > $wh_awal) {
                $delta_wh = $wh_akhir - $wh_awal;
            }
            $hasil[$kode] = [
                'kode' => $kode,
                'label' => $meta['label'],
                'warna' => $meta['warna'],
                'ikon' => $meta['ikon'],
                'wh_awal' => $wh_awal,
                'wh_akhir' => $wh_akhir,
                'delta_wh' => $delta_wh,
                'delta_kwh' => $delta_wh !== null ? wh_ke_kwh($delta_wh) : null,
                'waktu_akhir' => $akhir['dt_create'] ?? null,
            ];
        } catch (Throwable $e) {
            error_log("[EMOSai utility {$kode}] " . $e->getMessage());
            $hasil[$kode] = [
                'kode' => $kode,
                'label' => $meta['label'],
                'warna' => $meta['warna'],
                'ikon' => $meta['ikon'],
                'wh_awal' => null,
                'wh_akhir' => null,
                'delta_wh' => null,
                'delta_kwh' => null,
                'waktu_akhir' => null,
            ];
        }
    }
    return $hasil;
}

function get_utility_auto_total_kwh(string $dari, string $sampai): float
{
    $regs = get_utility_range($dari, $sampai);
    $total = 0;
    foreach ($regs as $r) {
        if ($r['delta_kwh'] !== null) $total += $r['delta_kwh'];
    }
    return round($total, 2);
}

// =====================================================================
// RINGKASAN & LOSSES
// =====================================================================

/**
 * Ringkasan lengkap: Sumber vs Konsumen + Losses
 */
function get_ringkasan_sistem(string $dari, string $sampai): array
{
    $total_sumber   = get_sumber_total_kwh($dari, $sampai);
    $total_line     = get_line_konsumen_total_kwh($dari, $sampai);
    $total_utility  = get_utility_auto_total_kwh($dari, $sampai);
    $total_konsumen = round($total_line + $total_utility, 2);

    $losses = round($total_sumber - $total_konsumen, 2);
    $losses_persen = $total_sumber > 0 ? round(($losses / $total_sumber) * 100, 2) : 0;

    return [
        'total_sumber'    => $total_sumber,
        'total_line'      => $total_line,
        'total_utility'   => $total_utility,
        'total_konsumen'  => $total_konsumen,
        'losses'          => $losses,
        'losses_persen'   => $losses_persen,
    ];
}

// =====================================================================
// FUNGSI LAMA (dipakai di dashboard)
// =====================================================================

function get_total_kwh_hari_ini(string $tanggal): float
{
    return get_line_konsumen_total_kwh($tanggal, $tanggal) + get_utility_auto_total_kwh($tanggal, $tanggal);
}

function get_ringkasan_per_line(string $tanggal): array
{
    return get_ringkasan_per_line_range($tanggal, $tanggal);
}

// function get_ringkasan_per_line_range(string $dari, string $sampai): array
// {
//     $hasil = [];

//     // Line konsumen
//     $line_data = get_line_konsumen_range($dari, $sampai);
//     foreach ($line_data as $kode => $r) {
//         if (strpos($kode, '_') === 0) continue;
//         $hasil[] = [
//             'kode'           => $r['kode'],
//             'nama'           => $r['label'],
//             'tipe'           => 'line',
//             'wh_awal'        => $r['wh_awal'],
//             'wh_akhir'       => $r['wh_akhir'],
//             'pemakaian_wh'   => $r['delta_wh'],
//             'kwh_awal'       => $r['wh_awal'] !== null ? wh_ke_kwh($r['wh_awal']) : null,
//             'kwh_akhir'      => $r['wh_akhir'] !== null ? wh_ke_kwh($r['wh_akhir']) : null,
//             'pemakaian'      => $r['delta_kwh'],
//             'kw_terakhir'    => $r['kw_terakhir'],
//             'waktu_terakhir' => $r['waktu_akhir'],
//         ];
//     }

//     return $hasil;
// }

function get_energy_per_jam(string $line_kode, string $tanggal): array
{
    $map = line_tabel_map();
    if (!isset($map[$line_kode])) return [];
    $tabel = $map[$line_kode];
    $dari   = $tanggal . ' 00:00:00';
    $sampai = $tanggal . ' 23:59:59';

    $rows = db_fetch_all(
        "SELECT `kw`, `kwh`, `dt_create` FROM `{$tabel}`
         WHERE `dt_create` BETWEEN ? AND ? ORDER BY `dt_create` ASC",
        [$dari, $sampai]
    );

    $per_jam = [];
    for ($h = 0; $h < 24; $h++) {
        $per_jam[$h] = ['jam' => $h, 'kwh' => 0.0, 'kwh_kumulatif' => 0.0, 'kw' => 0.0, 'n' => 0];
    }

    $prev_wh = null;
    foreach ($rows as $r) {
        $jam = (int) substr($r['dt_create'], 11, 2);
        $wh_now = (float) $r['kwh'];
        $kw_now = (float) $r['kw'];
        if ($prev_wh !== null) {
            $delta_wh = $wh_now - $prev_wh;
            if ($delta_wh > 0 && $delta_wh < 10000000) $per_jam[$jam]['kwh'] += wh_ke_kwh($delta_wh);
        }
        $per_jam[$jam]['kw'] += $kw_now;
        $per_jam[$jam]['n']++;
        $prev_wh = $wh_now;
    }

    $kumulatif = 0;
    foreach ($per_jam as &$p) {
        if ($p['n'] > 0) $p['kw'] = round($p['kw'] / $p['n'], 2);
        $p['kwh'] = round($p['kwh'], 2);
        $kumulatif += $p['kwh'];
        $p['kwh_kumulatif'] = round($kumulatif, 2);
        unset($p['n']);
    }
    return array_values($per_jam);
}

function get_total_kwh_n_hari(int $jumlah_hari = 7): array
{
    $hasil = [];
    for ($i = $jumlah_hari - 1; $i >= 0; $i--) {
        $tanggal = date('Y-m-d', strtotime("-{$i} days"));
        $total = get_line_konsumen_total_kwh($tanggal, $tanggal) + get_utility_auto_total_kwh($tanggal, $tanggal);
        $hasil[] = ['tanggal' => $tanggal, 'total' => round($total, 2)];
    }
    return $hasil;
}

function get_kontribusi_per_line(string $dari, ?string $sampai = null): array
{
    // Kalau sampai null → pakai dari (single day)
    $sampai = $sampai ?? $dari;

    // Ambil ringkasan range
    if ($dari === $sampai) {
        $ringkasan = get_ringkasan_per_line($dari);
    } else {
        $ringkasan = get_ringkasan_per_line_range($dari, $sampai);
    }

    $hasil = [];
    foreach ($ringkasan as $r) {
        if ($r['pemakaian'] !== null && $r['pemakaian'] > 0) {
            $hasil[] = [
                'kode' => $r['kode'],
                'nama' => $r['nama'],
                'pemakaian' => $r['pemakaian'],
            ];
        }
    }
    usort($hasil, fn($a, $b) => $b['pemakaian'] <=> $a['pemakaian']);
    return $hasil;
}

function get_energy_paginated(string $line_kode, ?string $tanggal, int $page = 1, int $per_page = 50): array
{
    $map = line_tabel_map();
    if (!isset($map[$line_kode])) {
        return ['rows' => [], 'total' => 0, 'total_page' => 0, 'page' => 1];
    }
    $tabel = $map[$line_kode];
    $page  = max(1, $page);
    $offset = ($page - 1) * $per_page;
    $where = '';
    $params = [];
    if ($tanggal) {
        $dari = $tanggal . ' 00:00:00';
        $sampai = $tanggal . ' 23:59:59';
        $where = "WHERE `dt_create` BETWEEN ? AND ?";
        $params = [$dari, $sampai];
    }
    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$tabel}` {$where}", $params);
    $rows = db_fetch_all(
        "SELECT `id_pm`, `r_v3p`, `r_v1p`, `r_amp`, `kw`, `kwh`, `dt_create`
         FROM `{$tabel}` {$where} ORDER BY `dt_create` DESC
         LIMIT " . (int) $per_page . " OFFSET " . (int) $offset,
        $params
    );
    return [
        'rows' => $rows,
        'total' => $total,
        'total_page' => (int) ceil($total / $per_page),
        'page' => $page,
        'per_page' => $per_page,
    ];
}

function get_energy_stats_harian(string $line_kode, string $tanggal): array
{
    $map = line_tabel_map();
    if (!isset($map[$line_kode])) {
        return ['min_kw' => null, 'max_kw' => null, 'avg_kw' => null, 'total_kwh' => 0, 'samples' => 0];
    }
    $tabel = $map[$line_kode];
    $dari = $tanggal . ' 00:00:00';
    $sampai = $tanggal . ' 23:59:59';
    $stats = db_fetch_one(
        "SELECT MIN(`kw`) AS min_kw, MAX(`kw`) AS max_kw, AVG(`kw`) AS avg_kw, COUNT(*) AS samples
         FROM `{$tabel}` WHERE `dt_create` BETWEEN ? AND ?",
        [$dari, $sampai]
    );
    $first = db_fetch_one(
        "SELECT `kwh` FROM `{$tabel}` WHERE `dt_create` >= ? ORDER BY `dt_create` ASC LIMIT 1",
        [$dari]
    );
    $last = db_fetch_one(
        "SELECT `kwh` FROM `{$tabel}` WHERE `dt_create` <= ? ORDER BY `dt_create` DESC LIMIT 1",
        [$sampai]
    );
    $total_kwh = 0;
    if ($first && $last && $first['kwh'] !== null && $last['kwh'] !== null) {
        $delta_wh = (float) $last['kwh'] - (float) $first['kwh'];
        if ($delta_wh > 0 && $delta_wh < 10000000000) $total_kwh = wh_ke_kwh($delta_wh);
    }
    return [
        'min_kw' => $stats ? (float) $stats['min_kw'] : null,
        'max_kw' => $stats ? (float) $stats['max_kw'] : null,
        'avg_kw' => $stats ? (float) $stats['avg_kw'] : null,
        'total_kwh' => round($total_kwh, 2),
        'samples' => $stats ? (int) $stats['samples'] : 0,
    ];
}
// =====================================================================
// AGREGAT PER RANGE (untuk Dashboard dengan filter)
// =====================================================================

/**
 * Ringkasan per line untuk RANGE tanggal
 */
function get_ringkasan_per_line_range(string $dari, string $sampai): array
{
    $lines = get_all_lines();
    $map = line_tabel_map();
    $hasil = [];

    $dt_dari = $dari . ' 00:00:00';
    $dt_sampai = $sampai . ' 23:59:59';

    foreach ($lines as $line) {
        $kode = $line['kode'];
        if (in_array($kode, ['kubikel', 'utility'], true)) continue;

        $row = [
            'kode' => $kode,
            'nama' => $line['nama'],
            'tipe' => $line['tipe'],
            'wh_awal' => null,
            'wh_akhir' => null,
            'pemakaian_wh' => null,
            'kwh_awal' => null,
            'kwh_akhir' => null,
            'pemakaian' => null,
            'kw_terakhir' => null,
            'waktu_terakhir' => null,
        ];

        if (!isset($map[$kode])) {
            $hasil[] = $row;
            continue;
        }

        $tabel = $map[$kode];
        try {
            $awal = db_fetch_one(
                "SELECT `kwh`, `dt_create` FROM `{$tabel}`
                 WHERE `dt_create` >= ? ORDER BY `dt_create` ASC LIMIT 1",
                [$dt_dari]
            );
            $akhir = db_fetch_one(
                "SELECT `kwh`, `kw`, `dt_create` FROM `{$tabel}`
                 WHERE `dt_create` <= ? ORDER BY `dt_create` DESC LIMIT 1",
                [$dt_sampai]
            );

            if ($awal) {
                $row['wh_awal'] = (float) $awal['kwh'];
                $row['kwh_awal'] = wh_ke_kwh((float) $awal['kwh']);
            }
            if ($akhir) {
                $row['wh_akhir'] = (float) $akhir['kwh'];
                $row['kwh_akhir'] = wh_ke_kwh((float) $akhir['kwh']);
                $row['kw_terakhir'] = (float) $akhir['kw'];
                $row['waktu_terakhir'] = $akhir['dt_create'];
            }
            if ($row['wh_awal'] !== null && $row['wh_akhir'] !== null) {
                $delta_wh = $row['wh_akhir'] - $row['wh_awal'];
                if ($delta_wh > 0 && $delta_wh < 10000000000) {
                    $row['pemakaian_wh'] = round($delta_wh, 2);
                    $row['pemakaian'] = wh_ke_kwh($delta_wh);
                }
            }
        } catch (Throwable $e) {
            error_log("[EMOSai range] {$tabel}: " . $e->getMessage());
        }

        $hasil[] = $row;
    }

    return $hasil;
}

/**
 * Total kWh semua line (production saja) untuk range
 */
function get_total_kwh_range(string $dari, string $sampai): float
{
    $ringkasan = get_ringkasan_per_line_range($dari, $sampai);
    $total = 0;
    foreach ($ringkasan as $r) {
        if ($r['pemakaian'] !== null && $r['pemakaian'] > 0) {
            $total += $r['pemakaian'];
        }
    }
    return round($total, 2);
}

/**
 * Data per hari untuk range (grafik harian kalau range > 1 hari)
 * Return: [ ['tanggal', 'total_kwh'], ... ]
 */
function get_total_kwh_harian_range(string $dari, string $sampai, int $maks_hari = 90): array
{
    $hasil = [];
    $ts_dari = strtotime($dari);
    $ts_sampai = strtotime($sampai);
    $total_hari = (int) floor(($ts_sampai - $ts_dari) / 86400) + 1;

    if ($total_hari > $maks_hari) return []; // batasi biar nggak berat

    for ($i = 0; $i < $total_hari; $i++) {
        $tanggal = date('Y-m-d', $ts_dari + $i * 86400);
        $total = get_total_kwh_hari_ini($tanggal);
        $hasil[] = ['tanggal' => $tanggal, 'total_kwh' => round($total, 2)];
    }
    return $hasil;
}
// =====================================================================
// PEMISAHAN LINE vs LV (Khusus Dashboard)
// =====================================================================

/**
 * Ambil kontribusi khusus LV (LV4K, LV800, LV400)
 */
function get_kontribusi_lv(string $dari, string $sampai): array
{
    $ringkasan = get_ringkasan_per_line_range($dari, $sampai);
    $lv_codes = ['lv4k', 'lv800', 'lv400'];
    $hasil = [];

    foreach ($ringkasan as $r) {
        if (in_array($r['kode'], $lv_codes) && $r['pemakaian'] !== null && $r['pemakaian'] > 0) {
            $hasil[] = [
                'kode' => $r['kode'],
                'nama' => $r['nama'],
                'pemakaian' => $r['pemakaian']
            ];
        }
    }
    usort($hasil, fn($a, $b) => $b['pemakaian'] <=> $a['pemakaian']);
    return $hasil;
}

/**
 * Ambil kontribusi khusus Line (selain LV dan Utility)
 */
function get_kontribusi_line(string $dari, string $sampai): array
{
    $ringkasan = get_ringkasan_per_line_range($dari, $sampai);
    $lv_codes = ['lv4k', 'lv800', 'lv400'];
    $hasil = [];

    foreach ($ringkasan as $r) {
        if (!in_array($r['kode'], $lv_codes) && $r['pemakaian'] !== null && $r['pemakaian'] > 0) {
            $hasil[] = [
                'kode' => $r['kode'],
                'nama' => $r['nama'],
                'pemakaian' => $r['pemakaian']
            ];
        }
    }
    usort($hasil, fn($a, $b) => $b['pemakaian'] <=> $a['pemakaian']);
    return $hasil;
}

/**
 * Filter ringkasan hanya LV saja
 */
function get_ringkasan_khusus_lv(string $dari, string $sampai): array
{
    $ringkasan = get_ringkasan_per_line_range($dari, $sampai);
    $lv_codes = ['lv4k', 'lv800', 'lv400'];
    return array_values(array_filter($ringkasan, fn($r) => in_array($r['kode'], $lv_codes)));
}

/**
 * Filter ringkasan hanya Line saja (exclude LV & Utility)
 */
function get_ringkasan_khusus_line(string $dari, string $sampai): array
{
    $ringkasan = get_ringkasan_per_line_range($dari, $sampai);
    $lv_codes = ['lv4k', 'lv800', 'lv400'];
    return array_values(array_filter($ringkasan, fn($r) => !in_array($r['kode'], $lv_codes)));
}
