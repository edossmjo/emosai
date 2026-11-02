<?php

/**
 * =====================================================================
 * EMOSai - Queries: Gas (dengan konversi + kontrak min/max/surcharge)
 * Batch 4.5+ (Opsi C: akumulasi surcharge)
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

// =====================================================================
// KONVERSI & KONTRAK
// =====================================================================

function get_gas_konversi(?string $periode = null): array
{
    $periode = $periode ?? date('Y-m');

    $row = db_fetch_one(
        "SELECT * FROM `gas_konversi` WHERE `periode_bulan` = ? LIMIT 1",
        [$periode]
    );

    if (!$row) {
        $row = db_fetch_one(
            "SELECT * FROM `gas_konversi`
             WHERE `periode_bulan` <= ?
             ORDER BY `periode_bulan` DESC LIMIT 1",
            [$periode]
        );
    }

    if (!$row) {
        // Default lengkap
        return [
            'periode_bulan'         => $periode,
            'faktor_mmbtu'          => 0.036127,
            'heating_value'         => 1000.00,
            'kontrak_per_mmbtu'     => null,
            'min_bulanan_mmbtu'     => null,
            'max_bulanan_mmbtu'     => null,
            'min_harian_mmbtu'      => null,
            'max_harian_mmbtu'      => null,
            'tarif_surcharge_rupiah' => null,
            'tarif_surcharge_persen' => null,
            'catatan'               => 'Default (belum ada konfigurasi)',
            'is_default'            => true,
        ];
    }

    $row['is_default'] = false;
    return $row;
}

function m3_ke_mmbtu(float $m3, ?float $faktor = null): float
{
    if ($faktor === null) {
        $faktor = (float) get_gas_konversi()['faktor_mmbtu'];
    }
    return round($m3 * $faktor, 6);
}

function mmbtu_ke_m3(float $mmbtu, ?float $faktor = null): float
{
    if ($faktor === null) {
        $faktor = (float) get_gas_konversi()['faktor_mmbtu'];
    }
    if ($faktor == 0) return 0;
    return round($mmbtu / $faktor, 4);
}

/**
 * Hitung tarif surcharge efektif (Rp/MMBTU)
 * Prioritas: Rupiah → Persen → null
 */
function get_tarif_surcharge(?array $konversi = null): ?float
{
    $konversi = $konversi ?? get_gas_konversi();

    if (!empty($konversi['tarif_surcharge_rupiah'])) {
        return (float) $konversi['tarif_surcharge_rupiah'];
    }

    if (!empty($konversi['tarif_surcharge_persen']) && !empty($konversi['kontrak_per_mmbtu'])) {
        $persen = (float) $konversi['tarif_surcharge_persen'];
        return (float) $konversi['kontrak_per_mmbtu'] * (1 + $persen / 100);
    }

    return null;
}

function simpan_gas_konversi(array $data): array
{
    if (empty($data['periode_bulan'])) return ['success' => false, 'message' => 'Periode wajib.'];

    $faktor = isset($data['faktor_mmbtu']) && $data['faktor_mmbtu'] !== ''
        ? (float) $data['faktor_mmbtu']
        : 0.036127;

    if ($faktor <= 0) {
        return ['success' => false, 'message' => 'Faktor konversi harus lebih dari 0.'];
    }

    try {
        db_execute(
            "INSERT INTO `gas_konversi`
             (`periode_bulan`, `faktor_mmbtu`, `heating_value`,
              `kontrak_per_mmbtu`, `min_bulanan_mmbtu`, `max_bulanan_mmbtu`,
              `min_harian_mmbtu`, `max_harian_mmbtu`,
              `tarif_surcharge_rupiah`, `tarif_surcharge_persen`, `catatan`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `faktor_mmbtu`           = VALUES(`faktor_mmbtu`),
                `heating_value`          = VALUES(`heating_value`),
                `kontrak_per_mmbtu`      = VALUES(`kontrak_per_mmbtu`),
                `min_bulanan_mmbtu`      = VALUES(`min_bulanan_mmbtu`),
                `max_bulanan_mmbtu`      = VALUES(`max_bulanan_mmbtu`),
                `min_harian_mmbtu`       = VALUES(`min_harian_mmbtu`),
                `max_harian_mmbtu`       = VALUES(`max_harian_mmbtu`),
                `tarif_surcharge_rupiah` = VALUES(`tarif_surcharge_rupiah`),
                `tarif_surcharge_persen` = VALUES(`tarif_surcharge_persen`),
                `catatan`                = VALUES(`catatan`)",
            [
                $data['periode_bulan'],
                $faktor,
                $data['heating_value'] ?? null,
                $data['kontrak_per_mmbtu'] ?? null,
                $data['min_bulanan_mmbtu'] ?? null,
                $data['max_bulanan_mmbtu'] ?? null,
                $data['min_harian_mmbtu'] ?? null,
                $data['max_harian_mmbtu'] ?? null,
                $data['tarif_surcharge_rupiah'] ?? null,
                $data['tarif_surcharge_persen'] ?? null,
                $data['catatan'] ?? null
            ]
        );
        log_activity(
            'input_gas_konversi',
            'gas_konversi',
            null,
            "Set konversi+kontrak gas {$data['periode_bulan']}"
        );
        return ['success' => true, 'message' => 'Konfigurasi gas berhasil disimpan.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal: ' . $e->getMessage()];
    }
}

function get_gas_konversi_list(int $limit = 24): array
{
    return db_fetch_all(
        "SELECT * FROM `gas_konversi` ORDER BY `periode_bulan` DESC LIMIT " . (int) $limit
    );
}

// =====================================================================
// READ
// =====================================================================

function get_gas_harian(string $tanggal): array
{
    $row = db_fetch_one(
        "SELECT
            SUM(`pemakaian`) AS total_m3,
            COUNT(*) AS n,
            MAX(`satuan`) AS satuan
         FROM `gas_usage`
         WHERE `tanggal` = ?",
        [$tanggal]
    );

    $konversi = get_gas_konversi(substr($tanggal, 0, 7));
    $faktor = (float) $konversi['faktor_mmbtu'];

    if (!$row || !$row['n']) {
        return [
            'total' => 0.0,
            'total_m3' => 0.0,
            'total_mmbtu' => 0.0,
            'satuan' => 'm3',
            'ada_data' => false,
            'faktor' => $faktor,
            'status_harian' => 'no_data',
            'kelebihan_mmbtu' => 0.0,
        ];
    }

    $total_m3 = (float) $row['total_m3'];
    $total_mmbtu = m3_ke_mmbtu($total_m3, $faktor);

    // Cek status harian
    $max_harian = (float) ($konversi['max_harian_mmbtu'] ?? 0);
    $min_harian = (float) ($konversi['min_harian_mmbtu'] ?? 0);
    $kelebihan = 0.0;
    $status = 'normal';

    if ($max_harian > 0 && $total_mmbtu > $max_harian) {
        $kelebihan = $total_mmbtu - $max_harian;
        $status = 'over';
    } elseif ($min_harian > 0 && $total_mmbtu < $min_harian) {
        $status = 'under';
    }

    return [
        'total'           => $total_m3,
        'total_m3'        => $total_m3,
        'total_mmbtu'     => $total_mmbtu,
        'satuan'          => $row['satuan'] ?: 'm3',
        'ada_data'        => true,
        'faktor'          => $faktor,
        'status_harian'   => $status,
        'kelebihan_mmbtu' => $kelebihan,
    ];
}

function get_gas_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `gas_usage` WHERE `id` = ?", [$id]);
}

function get_gas_paginated(array $filter = [], int $page = 1, int $per_page = 30): array
{
    $where = [];
    $params = [];

    if (!empty($filter['dari'])) {
        $where[] = "g.`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "g.`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }
    if (!empty($filter['satuan'])) {
        $where[] = "g.`satuan` = ?";
        $params[] = $filter['satuan'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `gas_usage` g {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT g.*, u.`name` AS nama_user
         FROM `gas_usage` g
         LEFT JOIN `users` u ON u.`id` = g.`created_by`
         {$where_sql}
         ORDER BY g.`tanggal` DESC, g.`id` DESC
         LIMIT " . (int) $per_page . " OFFSET " . (int) $offset,
        $params
    );

    // Tambah konversi + status per row
    foreach ($rows as &$r) {
        $periode = substr($r['tanggal'], 0, 7);
        $konversi = get_gas_konversi($periode);
        $faktor = (float) $konversi['faktor_mmbtu'];
        $max_harian = (float) ($konversi['max_harian_mmbtu'] ?? 0);

        $mmbtu = $r['satuan'] === 'm3'
            ? m3_ke_mmbtu((float) $r['pemakaian'], $faktor)
            : null;

        $r['faktor_mmbtu'] = $faktor;
        $r['pemakaian_mmbtu'] = $mmbtu;

        // Status harian
        if ($mmbtu === null) {
            $r['status_harian'] = 'normal';
            $r['kelebihan_harian'] = 0;
        } elseif ($max_harian > 0 && $mmbtu > $max_harian) {
            $r['status_harian'] = 'over';
            $r['kelebihan_harian'] = $mmbtu - $max_harian;
        } else {
            $r['status_harian'] = 'normal';
            $r['kelebihan_harian'] = 0;
        }
    }

    return [
        'rows'       => $rows,
        'total'      => $total,
        'total_page' => (int) ceil($total / $per_page),
        'page'       => $page,
    ];
}

function get_gas_range(string $dari, string $sampai): array
{
    return db_fetch_all(
        "SELECT `tanggal`, SUM(`pemakaian`) AS total, MAX(`satuan`) AS satuan
         FROM `gas_usage`
         WHERE `tanggal` BETWEEN ? AND ?
         GROUP BY `tanggal`
         ORDER BY `tanggal` ASC",
        [$dari, $sampai]
    );
}

// =====================================================================
// TAGIHAN BULANAN (dengan Opsi C — akumulasi surcharge)
// =====================================================================

/**
 * Hitung tagihan bulanan gas sesuai aturan kontrak (Opsi C)
 *
 * Formula:
 *   1. Total bulanan = Σ pemakaian_mmbtu
 *   2. Sur-charge BULANAN (kalau total > max_bulanan):
 *        kelebihan_bulanan = total - max_bulanan
 *        surcharge_bulanan = kelebihan_bulanan × tarif_surcharge
 *   3. Sur-charge HARIAN (kumulatif, untuk tiap hari > max_harian):
 *        total_kelebihan_harian = Σ (pemakaian_hari - max_harian)
 *        surcharge_harian = total_kelebihan_harian × tarif_surcharge
 *   4. Total surcharge = surcharge_bulanan + surcharge_harian  (AKUMULASI)
 *   5. Tagihan dasar:
 *        - kalau total < min: min × harga (Take or Pay)
 *        - kalau total ≤ max: total × harga
 *        - kalau total > max: max × harga (kelebihan kena surcharge)
 *   6. Total tagihan = tagihan_dasar + total_surcharge
 */
/**
 * Hitung tagihan bulanan gas sesuai aturan kontrak (Opsi C)
 * + PROPORSIONAL: min/max dihitung berdasarkan hari terdata vs hari dalam bulan
 */
function get_gas_tagihan_bulanan(string $periode): array
{
    $dari   = $periode . '-01';
    $sampai = date('Y-m-t', strtotime($dari));
    $hari_dalam_bulan = (int) date('t', strtotime($dari));

    $konversi = get_gas_konversi($periode);
    $faktor = (float) $konversi['faktor_mmbtu'];
    $harga  = (float) ($konversi['kontrak_per_mmbtu'] ?? 0);
    $min_bulanan_asli = (float) ($konversi['min_bulanan_mmbtu'] ?? 0);
    $max_bulanan_asli = (float) ($konversi['max_bulanan_mmbtu'] ?? 0);
    $max_harian = (float) ($konversi['max_harian_mmbtu'] ?? 0);
    $tarif_surcharge = get_tarif_surcharge($konversi);

    // Ambil semua data harian bulan ini
    $rows = db_fetch_all(
        "SELECT `tanggal`, SUM(`pemakaian`) AS total_m3, MAX(`satuan`) AS satuan
         FROM `gas_usage`
         WHERE `tanggal` BETWEEN ? AND ?
         GROUP BY `tanggal`
         ORDER BY `tanggal` ASC",
        [$dari, $sampai]
    );

    // Hitung jumlah hari terdata
    $hari_terdata = count($rows);

    // Hitung rasio proporsional
    $rasio = $hari_dalam_bulan > 0 ? $hari_terdata / $hari_dalam_bulan : 0;

    // Min & max proporsional
    $min_bulanan = $min_bulanan_asli * $rasio;
    $max_bulanan = $max_bulanan_asli * $rasio;

    $result = [
        'periode'          => $periode,
        'tanggal_awal'     => $rows[0]['tanggal'] ?? null,
        'tanggal_akhir'    => end($rows)['tanggal'] ?? null,

        // Total
        'total_m3'         => 0.0,
        'total_mmbtu'      => 0.0,
        'jumlah_hari'      => $hari_terdata,
        'hari_dalam_bulan' => $hari_dalam_bulan,

        // Kontrak (asli + proporsional)
        'min_bulanan_asli' => $min_bulanan_asli,
        'max_bulanan_asli' => $max_bulanan_asli,
        'min_bulanan'      => round($min_bulanan, 4),   // proporsional
        'max_bulanan'      => round($max_bulanan, 4),   // proporsional
        'max_harian'       => $max_harian,
        'harga_kontrak'    => $harga,
        'tarif_surcharge'  => $tarif_surcharge,
        'rasio_proporsional' => round($rasio, 4),

        // Surcharge
        'kelebihan_bulanan' => 0.0,
        'surcharge_bulanan' => 0.0,
        'surcharge_harian'  => 0.0,
        'total_surcharge'   => 0.0,
        'hari_over'         => [],

        // Tagihan
        'tagihan_dasar'    => 0.0,
        'total_tagihan'    => 0.0,
        'status'           => 'no_data',
        'keterangan'       => '',
    ];

    if (empty($rows)) return $result;

    // Hitung total & kelebihan harian
    foreach ($rows as $r) {
        $m3 = (float) $r['total_m3'];
        $mmbtu = $r['satuan'] === 'm3' ? m3_ke_mmbtu($m3, $faktor) : 0.0;

        $result['total_m3'] += $m3;
        $result['total_mmbtu'] += $mmbtu;

        if ($max_harian > 0 && $mmbtu > $max_harian) {
            $kelebihan = $mmbtu - $max_harian;
            $result['hari_over'][] = [
                'tanggal'    => $r['tanggal'],
                'mmbtu'      => $mmbtu,
                'kelebihan'  => $kelebihan,
            ];
        }
    }

    $result['total_m3']    = round($result['total_m3'], 2);
    $result['total_mmbtu'] = round($result['total_mmbtu'], 4);

    // ============================================================
    // 1. Surcharge BULANAN (pakai max_bulanan proporsional)
    // ============================================================
    if ($max_bulanan > 0 && $result['total_mmbtu'] > $max_bulanan) {
        $result['kelebihan_bulanan'] = round($result['total_mmbtu'] - $max_bulanan, 4);
        if ($tarif_surcharge !== null) {
            $result['surcharge_bulanan'] = round($result['kelebihan_bulanan'] * $tarif_surcharge, 2);
        }
    }

    // ============================================================
    // 2. Surcharge HARIAN (kumulatif)
    // ============================================================
    $total_kelebihan_harian = 0.0;
    foreach ($result['hari_over'] as $h) {
        $total_kelebihan_harian += $h['kelebihan'];
    }
    if ($tarif_surcharge !== null && $total_kelebihan_harian > 0) {
        $result['surcharge_harian'] = round($total_kelebihan_harian * $tarif_surcharge, 2);
    }
    $result['total_kelebihan_harian'] = round($total_kelebihan_harian, 4);

    // ============================================================
    // 3. Total Surcharge (AKUMULASI)
    // ============================================================
    $result['total_surcharge'] = round(
        $result['surcharge_bulanan'] + $result['surcharge_harian'],
        2
    );

    // ============================================================
    // 4. Tagihan Dasar (pakai min/max PROPORSIONAL)
    // ============================================================
    if ($harga > 0) {
        if ($min_bulanan > 0 && $result['total_mmbtu'] < $min_bulanan) {
            // Take or Pay proporsional
            $result['tagihan_dasar'] = round($min_bulanan * $harga, 2);
            $result['status'] = 'take_or_pay';
            $result['keterangan'] = sprintf(
                'Pemakaian di bawah minimum proporsional (%.0f dari %.0f hari). Bayar min proporsional: %.2f MMBTU.',
                $hari_terdata,
                $hari_dalam_bulan,
                $min_bulanan
            );
        } elseif ($max_bulanan > 0 && $result['total_mmbtu'] > $max_bulanan) {
            // Over — bayar max proporsional + surcharge
            $result['tagihan_dasar'] = round($max_bulanan * $harga, 2);
            $result['status'] = 'overage';
            $result['keterangan'] = sprintf(
                'Pemakaian melebihi max proporsional (%.2f MMBTU). Bayar max + surcharge.',
                $max_bulanan
            );
        } else {
            // Normal
            $result['tagihan_dasar'] = round($result['total_mmbtu'] * $harga, 2);
            $result['status'] = 'normal';
            $result['keterangan'] = 'Pemakaian dalam range kontrak proporsional.';
        }
    }

    // ============================================================
    // 5. Total Tagihan
    // ============================================================
    $result['total_tagihan'] = round($result['tagihan_dasar'] + $result['total_surcharge'], 2);

    return $result;
}

// =====================================================================
// RINGKASAN
// =====================================================================

function get_gas_bulan_ini(): array
{
    $periode = date('Y-m');
    $tagihan = get_gas_tagihan_bulanan($periode);

    return [
        'total'              => $tagihan['total_m3'],
        'total_m3'           => $tagihan['total_m3'],
        'total_mmbtu'        => $tagihan['total_mmbtu'],
        'jumlah_hari'        => $tagihan['jumlah_hari'],
        'faktor_mmbtu'       => (float) get_gas_konversi($periode)['faktor_mmbtu'],
        'kontrak_per_mmbtu'  => $tagihan['harga_kontrak'],
        'tagihan'            => $tagihan,
    ];
}

function get_gas_tahun_ini(): array
{
    $tahun = date('Y');
    $row = db_fetch_one(
        "SELECT SUM(`pemakaian`) AS total, COUNT(DISTINCT `tanggal`) AS jumlah_hari
         FROM `gas_usage`
         WHERE YEAR(`tanggal`) = ?",
        [$tahun]
    );

    $total_m3 = (float) ($row['total'] ?? 0);
    $faktor = (float) get_gas_konversi()['faktor_mmbtu'];

    return [
        'total'         => $total_m3,
        'total_m3'      => $total_m3,
        'total_mmbtu'   => m3_ke_mmbtu($total_m3, $faktor),
        'jumlah_hari'   => (int) ($row['jumlah_hari'] ?? 0),
        'tahun'         => $tahun,
        'faktor_mmbtu'  => $faktor,
    ];
}

function get_gas_recent(int $limit_hari = 30): array
{
    $rows = db_fetch_all(
        "SELECT `tanggal`, SUM(`pemakaian`) AS total, MAX(`satuan`) AS satuan
         FROM `gas_usage`
         WHERE `tanggal` >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY `tanggal`
         ORDER BY `tanggal` ASC",
        [$limit_hari]
    );

    foreach ($rows as &$r) {
        $periode = substr($r['tanggal'], 0, 7);
        $konversi = get_gas_konversi($periode);
        $faktor = (float) $konversi['faktor_mmbtu'];
        $max_harian = (float) ($konversi['max_harian_mmbtu'] ?? 0);
        $mmbtu = m3_ke_mmbtu((float) $r['total'], $faktor);

        $r['total_mmbtu'] = $mmbtu;
        $r['status'] = ($max_harian > 0 && $mmbtu > $max_harian) ? 'over' : 'normal';
    }

    return $rows;
}

// =====================================================================
// CRUD
// =====================================================================

function simpan_gas(array $data): array
{
    if (empty($data['tanggal'])) {
        return ['success' => false, 'message' => 'Tanggal wajib.', 'id' => null];
    }
    if (!isset($data['pemakaian']) || $data['pemakaian'] === '') {
        return ['success' => false, 'message' => 'Pemakaian wajib.', 'id' => null];
    }

    $existing = db_fetch_one("SELECT `id` FROM `gas_usage` WHERE `tanggal` = ? LIMIT 1", [$data['tanggal']]);
    $is_update = $existing !== null;

    try {
        db_execute(
            "INSERT INTO `gas_usage`
             (`tanggal`, `pemakaian`, `satuan`, `catatan`, `created_by`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `pemakaian`  = VALUES(`pemakaian`),
                `satuan`     = VALUES(`satuan`),
                `catatan`    = VALUES(`catatan`),
                `updated_at` = CURRENT_TIMESTAMP",
            [
                $data['tanggal'],
                (float) $data['pemakaian'],
                $data['satuan'] ?? 'm3',
                $data['catatan'] ?? null,
                $data['created_by'] ?? null
            ]
        );

        $row = db_fetch_one("SELECT `id` FROM `gas_usage` WHERE `tanggal` = ? LIMIT 1", [$data['tanggal']]);
        $id = $row ? (int) $row['id'] : null;

        $verb = $is_update ? 'diupdate' : 'disimpan';
        log_activity(
            $is_update ? 'edit_gas' : 'input_gas',
            'gas_usage',
            $id,
            "Gas tanggal {$data['tanggal']} {$verb}"
        );

        return [
            'success' => true,
            'message' => "Data gas tanggal {$data['tanggal']} berhasil {$verb}" . ($is_update ? ' (menimpa data lama).' : '.'),
            'id' => $id,
            'is_update' => $is_update
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

function update_gas(int $id, array $data): array
{
    try {
        db_execute(
            "UPDATE `gas_usage` SET
                `tanggal` = ?, `pemakaian` = ?, `satuan` = ?, `catatan` = ?
             WHERE `id` = ?",
            [
                $data['tanggal'],
                (float) $data['pemakaian'],
                $data['satuan'] ?? 'm3',
                $data['catatan'] ?? null,
                $id
            ]
        );
        log_activity('edit_gas', 'gas_usage', $id, "Edit gas #{$id}");
        return ['success' => true, 'message' => 'Data gas berhasil diupdate.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal update: ' . $e->getMessage()];
    }
}

function hapus_gas(int $id): array
{
    try {
        db_execute("DELETE FROM `gas_usage` WHERE `id` = ?", [$id]);
        log_activity('hapus_gas', 'gas_usage', $id, "Hapus gas #{$id}");
        return ['success' => true, 'message' => 'Data gas berhasil dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}
// =====================================================================
// PEMBAYARAN GAS REAL (Batch 4.5++)
// =====================================================================

/**
 * Ambil pembayaran 1 periode
 */
function get_gas_pembayaran(string $periode): ?array
{
    return db_fetch_one(
        "SELECT * FROM `gas_pembayaran` WHERE `periode_bulan` = ? LIMIT 1",
        [$periode]
    );
}

/**
 * Ambil pembayaran by ID
 */
function get_gas_pembayaran_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `gas_pembayaran` WHERE `id` = ? LIMIT 1", [$id]);
}

/**
 * List pembayaran (untuk halaman riwayat)
 */
function get_gas_pembayaran_list(int $limit = 24): array
{
    return db_fetch_all(
        "SELECT * FROM `gas_pembayaran` ORDER BY `periode_bulan` DESC LIMIT " . (int) $limit
    );
}

/**
 * Simpan / update pembayaran gas
 * Auto-hitung selisih vs tagihan sistem
 */
function simpan_gas_pembayaran(array $data): array
{
    $errors = [];
    if (empty($data['periode_bulan']))      $errors[] = 'Periode wajib.';
    if (empty($data['tanggal_faktur']))     $errors[] = 'Tanggal faktur wajib.';
    if (!isset($data['tagihan_aktual']) || $data['tagihan_aktual'] === '')
        $errors[] = 'Tagihan aktual wajib.';
    if (empty($data['nama_penginput']))     $errors[] = 'Nama penginput wajib.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    // Hitung tagihan sistem saat ini (snapshot)
    $tagihan_sistem = get_gas_tagihan_bulanan($data['periode_bulan']);
    $nilai_sistem = $tagihan_sistem['total_tagihan'] ?? null;
    $nilai_aktual = (float) $data['tagihan_aktual'];

    $selisih = null;
    $selisih_persen = null;
    $status = 'belum_dibanding';

    if ($nilai_sistem !== null && $nilai_sistem > 0) {
        $selisih = round($nilai_aktual - $nilai_sistem, 2);
        $selisih_persen = round(($selisih / $nilai_sistem) * 100, 4);

        $abs = abs($selisih_persen);
        if ($abs <= 2)         $status = 'wajar';
        elseif ($abs <= 10)    $status = 'perlu_cek';
        else                   $status = 'anomali';
    }

    $existing = get_gas_pembayaran($data['periode_bulan']);
    $is_update = $existing !== null;

    try {
        db_execute(
            "INSERT INTO `gas_pembayaran`
             (`periode_bulan`, `tanggal_faktur`, `nomor_faktur`, `supplier`,
              `tagihan_sistem`, `tagihan_aktual`, `selisih`, `selisih_persen`, `status`,
              `catatan`, `file_faktur`, `nama_penginput`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `tanggal_faktur`  = VALUES(`tanggal_faktur`),
                `nomor_faktur`    = VALUES(`nomor_faktur`),
                `supplier`        = VALUES(`supplier`),
                `tagihan_sistem`  = VALUES(`tagihan_sistem`),
                `tagihan_aktual`  = VALUES(`tagihan_aktual`),
                `selisih`         = VALUES(`selisih`),
                `selisih_persen`  = VALUES(`selisih_persen`),
                `status`          = VALUES(`status`),
                `catatan`         = VALUES(`catatan`),
                `file_faktur`     = VALUES(`file_faktur`),
                `nama_penginput`  = VALUES(`nama_penginput`),
                `updated_at`      = CURRENT_TIMESTAMP",
            [
                $data['periode_bulan'],
                $data['tanggal_faktur'],
                $data['nomor_faktur'] ?? null,
                $data['supplier'] ?? null,
                $nilai_sistem,
                $nilai_aktual,
                $selisih,
                $selisih_persen,
                $status,
                $data['catatan'] ?? null,
                $data['file_faktur'] ?? null,
                $data['nama_penginput'],
                $data['created_by'] ?? null
            ]
        );

        $row = get_gas_pembayaran($data['periode_bulan']);
        $id = $row ? (int) $row['id'] : null;

        $verb = $is_update ? 'diupdate' : 'disimpan';
        log_activity(
            $is_update ? 'edit_gas_bayar' : 'input_gas_bayar',
            'gas_pembayaran',
            $id,
            "Pembayaran gas {$data['periode_bulan']} {$verb}"
        );

        return [
            'success' => true,
            'message' => "Pembayaran gas {$data['periode_bulan']} berhasil {$verb}." .
                ($selisih !== null ? " Selisih: " . rupiah($selisih) . " ({$selisih_persen}%)." : ''),
            'id' => $id,
            'is_update' => $is_update
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

/**
 * Hapus pembayaran
 */
function hapus_gas_pembayaran(int $id): array
{
    try {
        db_execute("DELETE FROM `gas_pembayaran` WHERE `id` = ?", [$id]);
        log_activity('hapus_gas_bayar', 'gas_pembayaran', $id, "Hapus pembayaran gas #{$id}");
        return ['success' => true, 'message' => 'Pembayaran gas berhasil dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

/**
 * Label & warna status
 */
function gas_bayar_status_label(string $status): string
{
    return match ($status) {
        'wajar'           => 'Wajar',
        'perlu_cek'       => 'Perlu Cek',
        'anomali'         => 'Anomali',
        'belum_dibanding' => 'Belum Dibanding',
        default           => '—'
    };
}

function gas_bayar_status_warna(string $status): string
{
    return match ($status) {
        'wajar'           => 'success',
        'perlu_cek'       => 'warning',
        'anomali'         => 'danger',
        'belum_dibanding' => 'secondary',
        default           => 'secondary'
    };
}
// =====================================================================
// GRAFIK BULANAN (12 BULAN)
// =====================================================================

/**
 * Total pemakaian gas per bulan — 12 bulan terakhir
 * Return: [ ['periode' => '2026-10', 'total_m3' => x, 'total_mmbtu' => y, 'jumlah_hari' => n], ... ]
 */
function get_gas_per_bulan(int $jumlah_bulan = 12): array
{
    $hasil = [];

    // Ambil data dari DB — group by bulan
    $rows = db_fetch_all(
        "SELECT
            DATE_FORMAT(`tanggal`, '%Y-%m') AS periode,
            COUNT(DISTINCT `tanggal`) AS jumlah_hari,
            SUM(`pemakaian`) AS total_m3,
            MAX(`satuan`) AS satuan
         FROM `gas_usage`
         WHERE `tanggal` >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
         GROUP BY DATE_FORMAT(`tanggal`, '%Y-%m')
         ORDER BY periode ASC",
        [$jumlah_bulan]
    );

    // Map hasil ke array
    $data_map = [];
    foreach ($rows as $r) {
        $data_map[$r['periode']] = $r;
    }

    // Loop 12 bulan terakhir — pastikan semua bulan muncul (termasuk yang kosong)
    for ($i = $jumlah_bulan - 1; $i >= 0; $i--) {
        $periode = date('Y-m', strtotime("-{$i} months"));
        $row = $data_map[$periode] ?? null;

        if ($row) {
            $total_m3 = (float) $row['total_m3'];
            $konversi = get_gas_konversi($periode);
            $faktor = (float) $konversi['faktor_mmbtu'];
            $total_mmbtu = m3_ke_mmbtu($total_m3, $faktor);

            $hasil[] = [
                'periode'      => $periode,
                'label'        => date('M Y', strtotime($periode . '-01')),
                'label_singkat' => date('M', strtotime($periode . '-01')),
                'total_m3'     => round($total_m3, 2),
                'total_mmbtu'  => round($total_mmbtu, 4),
                'jumlah_hari'  => (int) $row['jumlah_hari'],
                'ada_data'     => true,
            ];
        } else {
            $hasil[] = [
                'periode'      => $periode,
                'label'        => date('M Y', strtotime($periode . '-01')),
                'label_singkat' => date('M', strtotime($periode . '-01')),
                'total_m3'     => 0,
                'total_mmbtu'  => 0,
                'jumlah_hari'  => 0,
                'ada_data'     => false,
            ];
        }
    }

    return $hasil;
}

/**
 * Statistik ringkas 12 bulan
 */
function get_gas_statistik_bulanan(int $jumlah_bulan = 12): array
{
    $data = get_gas_per_bulan($jumlah_bulan);

    $ada_data = array_filter($data, fn($d) => $d['ada_data']);

    if (empty($ada_data)) {
        return [
            'total_mmbtu'   => 0,
            'avg_mmbtu'     => 0,
            'max_mmbtu'     => 0,
            'max_bulan'     => '—',
            'min_mmbtu'     => 0,
            'min_bulan'     => '—',
            'bulan_terdata' => 0,
        ];
    }

    $totals = array_column($ada_data, 'total_mmbtu');
    $max_val = max($totals);
    $min_val = min($totals);

    $max_item = null;
    $min_item = null;
    foreach ($ada_data as $d) {
        if ($d['total_mmbtu'] === $max_val && $max_item === null) $max_item = $d;
        if ($d['total_mmbtu'] === $min_val && $min_item === null) $min_item = $d;
    }

    return [
        'total_mmbtu'   => round(array_sum($totals), 2),
        'avg_mmbtu'     => round(array_sum($totals) / count($ada_data), 2),
        'max_mmbtu'     => round($max_val, 2),
        'max_bulan'     => $max_item['label'] ?? '—',
        'min_mmbtu'     => round($min_val, 2),
        'min_bulan'     => $min_item['label'] ?? '—',
        'bulan_terdata' => count($ada_data),
    ];
}
