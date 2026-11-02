<?php

/**
 * =====================================================================
 * EMOSai - Queries: PLN
 * Batch 4.3+ (multi-tarif sesuai invoice PLN)
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

// =====================================================================
// PLN METER — CRUD Harian
// =====================================================================

function get_pln_meter_harian(string $tanggal): ?array
{
    return db_fetch_one("SELECT * FROM `pln_meter` WHERE `tanggal` = ? LIMIT 1", [$tanggal]);
}

function get_pln_meter_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `pln_meter` WHERE `id` = ? LIMIT 1", [$id]);
}

function get_pln_meter_range(string $dari, string $sampai): array
{
    return db_fetch_all(
        "SELECT * FROM `pln_meter` WHERE `tanggal` BETWEEN ? AND ? ORDER BY `tanggal` ASC",
        [$dari, $sampai]
    );
}

function get_pln_meter_paginated(array $filter = [], int $page = 1, int $per_page = 30): array
{
    $where = [];
    $params = [];
    if (!empty($filter['dari'])) {
        $where[] = "`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;
    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `pln_meter` {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT p.*, u.`name` AS nama_user
         FROM `pln_meter` p
         LEFT JOIN `users` u ON u.`id` = p.`created_by`
         {$where_sql}
         ORDER BY p.`tanggal` DESC
         LIMIT " . (int) $per_page . " OFFSET " . (int) $offset,
        $params
    );

    return [
        'rows'       => $rows,
        'total'      => $total,
        'total_page' => (int) ceil($total / $per_page),
        'page'       => $page,
    ];
}

/**
 * Simpan / Update data meter harian
 * Kalau tanggal sudah ada → otomatis UPDATE (bukan error)
 * Pakai ON DUPLICATE KEY UPDATE
 */
function simpan_pln_meter(array $data): array
{
    $errors = [];
    if (empty($data['tanggal']))        $errors[] = 'Tanggal wajib.';
    if (empty($data['nama_penginput'])) $errors[] = 'Nama penginput wajib.';

    // Minimal harus isi salah satu: stand_lwbp / stand_wbp / meter_angka
    $has_stand = (isset($data['stand_lwbp']) && $data['stand_lwbp'] !== '')
        || (isset($data['stand_wbp']) && $data['stand_wbp'] !== '')
        || (isset($data['meter_angka']) && $data['meter_angka'] !== '');
    if (!$has_stand) $errors[] = 'Minimal isi Stand LWBP atau WBP.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    // Cek apakah tanggal sudah ada (untuk pesan feedback)
    $existing = get_pln_meter_harian($data['tanggal']);
    $is_update = $existing !== null;

    // Ambil faktor pengali dari setting
    $faktor_lwbp = (float) get_pln_setting('faktor_lwbp');
    $faktor_wbp  = (float) get_pln_setting('faktor_wbp');

    // Default faktor kVArh dan meter_angka disamakan dengan LWBP jika tidak ada setting khusus
    $faktor_kvarh = $faktor_lwbp;
    $faktor_angka = $faktor_lwbp;

    try {
        // ON DUPLICATE KEY UPDATE: kalau unique key (tanggal) sudah ada,
        // otomatis update row yang existing
        db_execute(
            "INSERT INTO `pln_meter`
             (`tanggal`, `stand_lwbp`, `stand_wbp`, `stand_kvarh`, `meter_angka`,
              `nama_penginput`, `catatan`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `stand_lwbp`     = VALUES(`stand_lwbp`),
                `stand_wbp`      = VALUES(`stand_wbp`),
                `stand_kvarh`    = VALUES(`stand_kvarh`),
                `meter_angka`    = VALUES(`meter_angka`),
                `nama_penginput` = VALUES(`nama_penginput`),
                `catatan`        = VALUES(`catatan`),
                `updated_at`     = CURRENT_TIMESTAMP",
            [
                $data['tanggal'],
                // Kalikan LWBP dengan faktor pengali jika diisi
                isset($data['stand_lwbp']) && $data['stand_lwbp'] !== ''
                    ? (float) $data['stand_lwbp'] * $faktor_lwbp
                    : null,

                // Kalikan WBP dengan faktor pengali jika diisi
                isset($data['stand_wbp']) && $data['stand_wbp'] !== ''
                    ? (float) $data['stand_wbp'] * $faktor_wbp
                    : null,

                // Kalikan kVArh dengan faktor pengali jika diisi
                isset($data['stand_kvarh']) && $data['stand_kvarh'] !== ''
                    ? (float) $data['stand_kvarh'] * $faktor_kvarh
                    : null,

                // Kalikan Meter Angka (Gabungan) dengan faktor pengali jika diisi
                isset($data['meter_angka']) && $data['meter_angka'] !== ''
                    ? (float) $data['meter_angka'] * $faktor_angka
                    : null,

                $data['nama_penginput'],
                $data['catatan'] ?? null,
                $data['created_by'] ?? null
            ]
        );

        // Ambil ID (kalau baru insert atau existing)
        $row = get_pln_meter_harian($data['tanggal']);
        $id = $row ? (int) $row['id'] : null;

        $aksi = $is_update ? 'edit_pln' : 'input_pln';
        $verb = $is_update ? 'diupdate' : 'disimpan';
        log_activity($aksi, 'pln_meter', $id, "Data PLN {$data['tanggal']} {$verb}");

        return [
            'success' => true,
            'message' => "Data PLN tanggal {$data['tanggal']} berhasil {$verb}" . ($is_update ? ' (menimpa data lama).' : '.'),
            'id'      => $id,
            'is_update' => $is_update
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

function update_pln_meter(int $id, array $data): array
{
    $existing = get_pln_meter_by_id($id);
    if (!$existing) return ['success' => false, 'message' => 'Data tidak ditemukan.'];

    try {
        db_execute(
            "UPDATE `pln_meter` SET
                `tanggal` = ?, `stand_lwbp` = ?, `stand_wbp` = ?, `stand_kvarh` = ?,
                `meter_angka` = ?, `nama_penginput` = ?, `catatan` = ?
             WHERE `id` = ?",
            [
                $data['tanggal'],
                isset($data['stand_lwbp']) && $data['stand_lwbp'] !== '' ? (float) $data['stand_lwbp'] : null,
                isset($data['stand_wbp']) && $data['stand_wbp'] !== '' ? (float) $data['stand_wbp'] : null,
                isset($data['stand_kvarh']) && $data['stand_kvarh'] !== '' ? (float) $data['stand_kvarh'] : null,
                isset($data['meter_angka']) && $data['meter_angka'] !== '' ? (float) $data['meter_angka'] : null,
                $data['nama_penginput'],
                $data['catatan'] ?? null,
                $id
            ]
        );
        log_activity('edit_pln', 'pln_meter', $id, "Edit PLN #{$id}");
        return ['success' => true, 'message' => 'Data PLN berhasil diupdate.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal update: ' . $e->getMessage()];
    }
}

function hapus_pln_meter(int $id): array
{
    try {
        db_execute("DELETE FROM `pln_meter` WHERE `id` = ?", [$id]);
        log_activity('hapus_pln', 'pln_meter', $id, "Hapus PLN #{$id}");
        return ['success' => true, 'message' => 'Data PLN berhasil dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

// =====================================================================
// AGREGASI TAGIHAN BULANAN (sesuai invoice PLN)
// =====================================================================

/**
 * Hitung tagihan bulanan sesuai formula invoice PLN
 *
 * Formula:
 *   kWh LWBP = stand_lwbp(akhir bulan) - stand_lwbp(awal bulan)
 *   kWh WBP  = stand_wbp(akhir) - stand_wbp(awal)
 *   kVArh    = stand_kvarh(akhir) - stand_kvarh(awal)
 *
 *   TTL = (kWh LWBP × tarif_lwbp) + (kWh WBP × tarif_wbp) + (kVArh × tarif_kvarh)
 *   PPN = TTL × persen_ppn%
 *   PBJT = TTL × persen_pbjt%
 *   PPJ = TTL × persen_ppj%
 *   Total = TTL + PPN + PBJT + PPJ + biaya_beban + biaya_lain
 *
 * @param string $periode  Format 'YYYY-MM'
 * @return array
 */
function get_pln_tagihan_bulanan(string $periode): array
{
    $dari   = $periode . '-01';
    $sampai = date('Y-m-t', strtotime($dari));

    // Ambil meter pertama & terakhir bulan itu
    $first = db_fetch_one(
        "SELECT `tanggal`, `stand_lwbp`, `stand_wbp`, `stand_kvarh`, `meter_angka`
         FROM `pln_meter`
         WHERE `tanggal` BETWEEN ? AND ?
         ORDER BY `tanggal` ASC LIMIT 1",
        [$dari, $sampai]
    );
    $last = db_fetch_one(
        "SELECT `tanggal`, `stand_lwbp`, `stand_wbp`, `stand_kvarh`, `meter_angka`
         FROM `pln_meter`
         WHERE `tanggal` BETWEEN ? AND ?
         ORDER BY `tanggal` DESC LIMIT 1",
        [$dari, $sampai]
    );

    // Ambil tarif
    $tarif = db_fetch_one("SELECT * FROM `pln_tarif` WHERE `periode_bulan` = ? LIMIT 1", [$periode]);

    // Default return
    $result = [
        'periode'         => $periode,
        'tanggal_awal'    => $first['tanggal'] ?? null,
        'tanggal_akhir'   => $last['tanggal'] ?? null,

        // Stand
        'stand_lwbp_awal'  => null,
        'stand_lwbp_akhir'  => null,
        'stand_wbp_awal'   => null,
        'stand_wbp_akhir'   => null,
        'stand_kvarh_awal' => null,
        'stand_kvarh_akhir' => null,

        // Pemakaian kWh
        'kwh_lwbp'  => null,
        'kwh_wbp'   => null,
        'kvarh'     => null,
        'total_kwh' => null, // LWBP + WBP (untuk dashboard KPI)

        // Tarif
        'tarif_lwbp'  => $tarif['tarif_lwbp']  ?? null,
        'tarif_wbp'   => $tarif['tarif_wbp']   ?? null,
        'tarif_kvarh' => $tarif['tarif_kvarh'] ?? null,
        'nama_tarif'  => $tarif['nama_tarif']  ?? null,

        // Perhitungan
        'subtotal_lwbp' => null,
        'subtotal_wbp'  => null,
        'subtotal_kvarh' => null,
        'ttl'           => null,   // Total Tarif Listrik
        'kompensasi'    => 0,
        'ttl_minus_kompensasi' => null,

        // Komponen biaya
        'persen_ppn'   => $tarif['persen_ppn']  ?? 12,
        'persen_pbjt'  => $tarif['persen_pbjt'] ?? 3,
        'persen_ppj'   => $tarif['persen_ppj']  ?? 0,
        'nilai_ppn'    => null,
        'nilai_pbjt'   => null,
        'nilai_ppj'    => null,
        'biaya_beban'  => $tarif['biaya_beban'] ?? 0,
        'biaya_lain'   => $tarif['biaya_lain']  ?? 0,

        // Total
        'total_tagihan' => null,
        'ada_data'      => $first !== null && $last !== null && $first['tanggal'] !== $last['tanggal'],
    ];

    if (!$result['ada_data']) return $result;

    // ============================================================
    // Hitung pemakaian kWh
    // ============================================================
    $lwbp_awal  = $first['stand_lwbp']  !== null ? (float) $first['stand_lwbp']  : null;
    $lwbp_akhir = $last['stand_lwbp']   !== null ? (float) $last['stand_lwbp']   : null;
    $wbp_awal   = $first['stand_wbp']   !== null ? (float) $first['stand_wbp']   : null;
    $wbp_akhir  = $last['stand_wbp']    !== null ? (float) $last['stand_wbp']    : null;
    $kvarh_awal = $first['stand_kvarh'] !== null ? (float) $first['stand_kvarh'] : null;
    $kvarh_akhir = $last['stand_kvarh']  !== null ? (float) $last['stand_kvarh']  : null;

    $result['stand_lwbp_awal']  = $lwbp_awal;
    $result['stand_lwbp_akhir'] = $lwbp_akhir;
    $result['stand_wbp_awal']   = $wbp_awal;
    $result['stand_wbp_akhir']  = $wbp_akhir;
    $result['stand_kvarh_awal'] = $kvarh_awal;
    $result['stand_kvarh_akhir'] = $kvarh_akhir;

    $kwh_lwbp = ($lwbp_awal !== null && $lwbp_akhir !== null) ? $lwbp_akhir - $lwbp_awal : null;
    $kwh_wbp  = ($wbp_awal  !== null && $wbp_akhir  !== null) ? $wbp_akhir  - $wbp_awal  : null;
    $kvarh    = ($kvarh_awal !== null && $kvarh_akhir !== null) ? $kvarh_akhir - $kvarh_awal : null;

    $result['kwh_lwbp']  = $kwh_lwbp;
    $result['kwh_wbp']   = $kwh_wbp;
    $result['kvarh']     = $kvarh;

    if ($kwh_lwbp !== null && $kwh_wbp !== null) {
        $result['total_kwh'] = $kwh_lwbp + $kwh_wbp;
    }

    // ============================================================
    // Hitung subtotal per komponen
    // ============================================================
    $t_lwbp  = $result['tarif_lwbp'];
    $t_wbp   = $result['tarif_wbp'];
    $t_kvarh = $result['tarif_kvarh'];

    $sub_lwbp  = ($kwh_lwbp !== null && $t_lwbp !== null)  ? $kwh_lwbp * $t_lwbp  : 0;
    $sub_wbp   = ($kwh_wbp  !== null && $t_wbp  !== null)  ? $kwh_wbp  * $t_wbp   : 0;
    $sub_kvarh = ($kvarh    !== null && $t_kvarh !== null) ? $kvarh    * $t_kvarh : 0;

    $result['subtotal_lwbp']  = round($sub_lwbp, 2);
    $result['subtotal_wbp']   = round($sub_wbp, 2);
    $result['subtotal_kvarh'] = round($sub_kvarh, 2);

    // ============================================================
    // TTL & Komponen biaya
    // ============================================================
    $ttl = $sub_lwbp + $sub_wbp + $sub_kvarh;
    $result['ttl'] = round($ttl, 2);
    $result['ttl_minus_kompensasi'] = round($ttl - $result['kompensasi'], 2);

    $ppn  = $result['ttl_minus_kompensasi'] * ($result['persen_ppn'] / 100);
    $pbjt = $result['ttl_minus_kompensasi'] * ($result['persen_pbjt'] / 100);
    $ppj  = $result['ttl_minus_kompensasi'] * ($result['persen_ppj'] / 100);

    $result['nilai_ppn']  = round($ppn, 2);
    $result['nilai_pbjt'] = round($pbjt, 2);
    $result['nilai_ppj']  = round($ppj, 2);

    $total = $result['ttl_minus_kompensasi'] + $ppn + $pbjt + $ppj
        + $result['biaya_beban'] + $result['biaya_lain'];
    $result['total_tagihan'] = round($total, 2);

    return $result;
}

function get_pln_ringkasan_bulan_ini(): array
{
    return get_pln_tagihan_bulanan(date('Y-m'));
}

/**
 * Daftar semua periode + tagihannya
 */
function get_pln_periode_list(int $limit = 24): array
{
    $periodes = db_fetch_all(
        "SELECT DISTINCT DATE_FORMAT(`tanggal`, '%Y-%m') AS periode
         FROM `pln_meter`
         ORDER BY `periode` DESC
         LIMIT " . (int) $limit
    );

    $hasil = [];
    foreach ($periodes as $p) {
        $hasil[] = get_pln_tagihan_bulanan($p['periode']);
    }
    return $hasil;
}

// =====================================================================
// TARIF — CRUD
// =====================================================================

function get_pln_tarif(string $periode): ?array
{
    return db_fetch_one("SELECT * FROM `pln_tarif` WHERE `periode_bulan` = ?", [$periode]);
}

function get_pln_tarif_list(int $limit = 24): array
{
    return db_fetch_all(
        "SELECT * FROM `pln_tarif` ORDER BY `periode_bulan` DESC LIMIT " . (int) $limit
    );
}

function simpan_pln_tarif(array $data): array
{
    if (empty($data['periode_bulan'])) return ['success' => false, 'message' => 'Periode wajib.'];

    try {
        db_execute(
            "INSERT INTO `pln_tarif`
             (`periode_bulan`, `tarif_lwbp`, `tarif_wbp`, `tarif_kvarh`,
              `persen_ppn`, `persen_pbjt`, `persen_ppj`,
              `biaya_beban`, `biaya_lain`, `nama_tarif`, `catatan`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `tarif_lwbp`  = VALUES(`tarif_lwbp`),
                `tarif_wbp`   = VALUES(`tarif_wbp`),
                `tarif_kvarh` = VALUES(`tarif_kvarh`),
                `persen_ppn`  = VALUES(`persen_ppn`),
                `persen_pbjt` = VALUES(`persen_pbjt`),
                `persen_ppj`  = VALUES(`persen_ppj`),
                `biaya_beban` = VALUES(`biaya_beban`),
                `biaya_lain`  = VALUES(`biaya_lain`),
                `nama_tarif`  = VALUES(`nama_tarif`),
                `catatan`     = VALUES(`catatan`)",
            [
                $data['periode_bulan'],
                $data['tarif_lwbp']  !== '' ? (float) $data['tarif_lwbp']  : null,
                $data['tarif_wbp']   !== '' ? (float) $data['tarif_wbp']   : null,
                $data['tarif_kvarh'] !== '' ? (float) $data['tarif_kvarh'] : null,
                (float) ($data['persen_ppn']  ?? 12),
                (float) ($data['persen_pbjt'] ?? 3),
                (float) ($data['persen_ppj']  ?? 0),
                (float) ($data['biaya_beban'] ?? 0),
                (float) ($data['biaya_lain']  ?? 0),
                $data['nama_tarif'] ?? null,
                $data['catatan'] ?? null
            ]
        );
        log_activity('input_tarif_pln', 'pln_tarif', null, "Set tarif {$data['periode_bulan']}");
        return ['success' => true, 'message' => 'Tarif PLN berhasil disimpan.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal: ' . $e->getMessage()];
    }
}

function hapus_pln_tarif(int $id): array
{
    try {
        db_execute("DELETE FROM `pln_tarif` WHERE `id` = ?", [$id]);
        return ['success' => true, 'message' => 'Tarif dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

// Arsip existing
function get_input_pln_arsip(int $limit = 12): array
{
    return db_fetch_all("SELECT * FROM `input_pln` ORDER BY `start_period` DESC LIMIT " . (int) $limit);
}
/**
 * Ambil setting khusus PLN
 */
function get_pln_setting(string $nama): string
{
    $row = db_fetch_one("SELECT `nilai` FROM `pln_setting` WHERE `nama_setting` = ? LIMIT 1", [$nama]);
    return $row ? $row['nilai'] : '1'; // Default 1 kalau tidak ada setting
}
