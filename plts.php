<?php

/**
 * =====================================================================
 * EMOSai - Queries: PLTS (Tahap 1 & 2)
 * Batch 4.4
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

// =====================================================================
// READ
// =====================================================================

/**
 * Ambil data PLTS 1 hari (semua tahap)
 */
function get_plts_harian(string $tanggal): array
{
    return db_fetch_all(
        "SELECT * FROM `plts_usage` WHERE `tanggal` = ? ORDER BY `tahap`",
        [$tanggal]
    );
}

/**
 * Ambil total kWh PLTS hari ini (T1 + T2)
 */
function get_plts_total_harian(string $tanggal): array
{
    $rows = get_plts_harian($tanggal);
    $hasil = ['tahap1' => 0.0, 'tahap2' => 0.0, 'total' => 0.0];

    foreach ($rows as $r) {
        $tahap = (int) $r['tahap'];
        $kwh   = (float) $r['jumlah_kwh'];
        if ($tahap === 1) $hasil['tahap1'] += $kwh;
        if ($tahap === 2) $hasil['tahap2'] += $kwh;
        $hasil['total'] += $kwh;
    }

    return $hasil;
}

/**
 * Ambil data PLTS dalam rentang tanggal (untuk grafik)
 */
function get_plts_range(string $dari, string $sampai, ?int $tahap = null): array
{
    $sql = "SELECT * FROM `plts_usage` WHERE `tanggal` BETWEEN ? AND ?";
    $params = [$dari, $sampai];

    if ($tahap !== null) {
        $sql .= " AND `tahap` = ?";
        $params[] = $tahap;
    }

    $sql .= " ORDER BY `tanggal` ASC, `tahap` ASC";
    return db_fetch_all($sql, $params);
}

/**
 * Ambil data PLTS terbaru per hari (digabung T1 & T2)
 * Return: [tanggal => ['tahap1' => x, 'tahap2' => y, 'total' => z], ...]
 */
function get_plts_recent(int $limit_hari = 30): array
{
    $rows = db_fetch_all(
        "SELECT `tanggal`,
                SUM(CASE WHEN `tahap` = 1 THEN `jumlah_kwh` ELSE 0 END) AS tahap1,
                SUM(CASE WHEN `tahap` = 2 THEN `jumlah_kwh` ELSE 0 END) AS tahap2,
                SUM(`jumlah_kwh`) AS total,
                MAX(`cuaca`) AS cuaca
         FROM `plts_usage`
         GROUP BY `tanggal`
         ORDER BY `tanggal` DESC
         LIMIT " . (int) $limit_hari
    );

    // Sort ascending untuk grafik
    return array_reverse($rows);
}

/**
 * Ringkasan PLTS bulan ini
 */
function get_plts_ringkasan_bulan_ini(): array
{
    $dari = date('Y-m-01');
    $sampai = date('Y-m-t');

    $row = db_fetch_one(
        "SELECT
            SUM(CASE WHEN `tahap` = 1 THEN `jumlah_kwh` ELSE 0 END) AS tahap1,
            SUM(CASE WHEN `tahap` = 2 THEN `jumlah_kwh` ELSE 0 END) AS tahap2,
            SUM(`jumlah_kwh`) AS total,
            COUNT(DISTINCT `tanggal`) AS jumlah_hari,
            AVG(`jumlah_kwh`) AS avg_harian
         FROM `plts_usage`
         WHERE `tanggal` BETWEEN ? AND ?",
        [$dari, $sampai]
    );

    return [
        'tahap1'       => (float) ($row['tahap1'] ?? 0),
        'tahap2'       => (float) ($row['tahap2'] ?? 0),
        'total'        => (float) ($row['total'] ?? 0),
        'jumlah_hari'  => (int) ($row['jumlah_hari'] ?? 0),
        'avg_harian'   => (float) ($row['avg_harian'] ?? 0),
        'dari'         => $dari,
        'sampai'       => $sampai,
    ];
}

/**
 * Ringkasan PLTS tahun ini
 */
function get_plts_ringkasan_tahun_ini(): array
{
    $tahun = date('Y');
    $row = db_fetch_one(
        "SELECT
            SUM(CASE WHEN `tahap` = 1 THEN `jumlah_kwh` ELSE 0 END) AS tahap1,
            SUM(CASE WHEN `tahap` = 2 THEN `jumlah_kwh` ELSE 0 END) AS tahap2,
            SUM(`jumlah_kwh`) AS total
         FROM `plts_usage`
         WHERE YEAR(`tanggal`) = ?",
        [$tahun]
    );

    return [
        'tahap1' => (float) ($row['tahap1'] ?? 0),
        'tahap2' => (float) ($row['tahap2'] ?? 0),
        'total'  => (float) ($row['total'] ?? 0),
        'tahun'  => $tahun,
    ];
}

/**
 * Ringkasan cuaca (untuk analisis produksi vs cuaca)
 */
function get_plts_cuaca_stats(string $dari, string $sampai): array
{
    return db_fetch_all(
        "SELECT
            `cuaca`,
            COUNT(*) AS jumlah,
            SUM(`jumlah_kwh`) AS total_kwh,
            AVG(`jumlah_kwh`) AS avg_kwh
         FROM `plts_usage`
         WHERE `tanggal` BETWEEN ? AND ?
           AND `cuaca` IS NOT NULL AND `cuaca` != ''
         GROUP BY `cuaca`
         ORDER BY avg_kwh DESC",
        [$dari, $sampai]
    );
}

/**
 * Pagination PLTS
 */
function get_plts_paginated(array $filter = [], int $page = 1, int $per_page = 30): array
{
    $where = [];
    $params = [];

    if (!empty($filter['dari'])) {
        $where[] = "p.`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "p.`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }
    if (!empty($filter['tahap'])) {
        $where[] = "p.`tahap` = ?";
        $params[] = (int) $filter['tahap'];
    }
    if (!empty($filter['cuaca'])) {
        $where[] = "p.`cuaca` = ?";
        $params[] = $filter['cuaca'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `plts_usage` p {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT p.*, u.`name` AS nama_user
         FROM `plts_usage` p
         LEFT JOIN `users` u ON u.`id` = p.`created_by`
         {$where_sql}
         ORDER BY p.`tanggal` DESC, p.`tahap` ASC
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
 * Ambil 1 PLTS by ID
 */
function get_plts_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `plts_usage` WHERE `id` = ?", [$id]);
}

// =====================================================================
// CREATE / UPDATE / DELETE
// =====================================================================

/**
 * Simpan data PLTS baru (atau update kalau tanggal+tahap sudah ada)
 */
function simpan_plts(array $data): array
{
    $errors = [];
    if (empty($data['tanggal']))     $errors[] = 'Tanggal wajib.';
    if (empty($data['tahap']))       $errors[] = 'Tahap wajib.';
    if (!isset($data['jumlah_kwh']) || $data['jumlah_kwh'] === '') $errors[] = 'Jumlah kWh wajib.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    $tahap = (int) $data['tahap'];
    if (!in_array($tahap, [1, 2], true)) {
        return ['success' => false, 'message' => 'Tahap harus 1 atau 2.', 'id' => null];
    }

    // Cek existing
    $existing = db_fetch_one(
        "SELECT `id` FROM `plts_usage` WHERE `tanggal` = ? AND `tahap` = ? LIMIT 1",
        [$data['tanggal'], $tahap]
    );
    $is_update = $existing !== null;

    try {
        db_execute(
            "INSERT INTO `plts_usage`
             (`tanggal`, `tahap`, `jumlah_kwh`, `kapasitas_kwp`, `cuaca`, `catatan`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `jumlah_kwh`    = VALUES(`jumlah_kwh`),
                `kapasitas_kwp` = VALUES(`kapasitas_kwp`),
                `cuaca`         = VALUES(`cuaca`),
                `catatan`       = VALUES(`catatan`),
                `updated_at`    = CURRENT_TIMESTAMP",
            [
                $data['tanggal'],
                $tahap,
                (float) $data['jumlah_kwh'],
                isset($data['kapasitas_kwp']) && $data['kapasitas_kwp'] !== '' ? (float) $data['kapasitas_kwp'] : null,
                $data['cuaca'] ?? null,
                $data['catatan'] ?? null,
                $data['created_by'] ?? null
            ]
        );

        $row = db_fetch_one(
            "SELECT `id` FROM `plts_usage` WHERE `tanggal` = ? AND `tahap` = ? LIMIT 1",
            [$data['tanggal'], $tahap]
        );
        $id = $row ? (int) $row['id'] : null;

        $aksi = $is_update ? 'edit_plts' : 'input_plts';
        $verb = $is_update ? 'diupdate' : 'disimpan';
        log_activity($aksi, 'plts_usage', $id, "PLTS Tahap {$tahap} tanggal {$data['tanggal']} {$verb}");

        return [
            'success' => true,
            'message' => "Data PLTS Tahap {$tahap} tanggal {$data['tanggal']} berhasil {$verb}" . ($is_update ? ' (menimpa data lama).' : '.'),
            'id'      => $id,
            'is_update' => $is_update
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

function update_plts(int $id, array $data): array
{
    $existing = get_plts_by_id($id);
    if (!$existing) return ['success' => false, 'message' => 'Data tidak ditemukan.'];

    try {
        db_execute(
            "UPDATE `plts_usage` SET
                `tanggal` = ?, `tahap` = ?, `jumlah_kwh` = ?,
                `kapasitas_kwp` = ?, `cuaca` = ?, `catatan` = ?
             WHERE `id` = ?",
            [
                $data['tanggal'],
                (int) $data['tahap'],
                (float) $data['jumlah_kwh'],
                isset($data['kapasitas_kwp']) && $data['kapasitas_kwp'] !== '' ? (float) $data['kapasitas_kwp'] : null,
                $data['cuaca'] ?? null,
                $data['catatan'] ?? null,
                $id
            ]
        );
        log_activity('edit_plts', 'plts_usage', $id, "Edit PLTS #{$id}");
        return ['success' => true, 'message' => 'Data PLTS berhasil diupdate.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal update: ' . $e->getMessage()];
    }
}

function hapus_plts(int $id): array
{
    try {
        db_execute("DELETE FROM `plts_usage` WHERE `id` = ?", [$id]);
        log_activity('hapus_plts', 'plts_usage', $id, "Hapus PLTS #{$id}");
        return ['success' => true, 'message' => 'Data PLTS berhasil dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}
