<?php

/**
 * =====================================================================
 * EMOSai - Queries: Utility (AF, UPS, AHU, Chiller)
 * Batch Utility + Fix satuan + Fix edit config
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

// =====================================================================
// KONFIGURASI DEVICE
// =====================================================================

function get_utility_devices(bool $aktif_only = true): array
{
    $sql = "SELECT * FROM `utility_config`";
    if ($aktif_only) $sql .= " WHERE `aktif` = 1";
    $sql .= " ORDER BY `urutan`, `device`";
    return db_fetch_all($sql);
}

function get_utility_device(string $device): ?array
{
    return db_fetch_one("SELECT * FROM `utility_config` WHERE `device` = ? LIMIT 1", [$device]);
}

function utility_device_valid(string $device): bool
{
    return in_array($device, ['AF', 'UPS', 'AHU', 'CHILLER'], true);
}

/**
 * Ambil config by ID
 */
function get_utility_config_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `utility_config` WHERE `id` = ? LIMIT 1", [$id]);
}

/**
 * Simpan / update utility config
 * Fix: Validasi duplikat saat edit
 */
function simpan_utility_config(array $data, ?int $id = null): array
{
    $device       = strtoupper(trim($data['device'] ?? ''));
    $nama_tampil  = trim($data['nama_tampil'] ?? '');
    $deskripsi    = trim($data['deskripsi'] ?? '');
    $lokasi       = trim($data['lokasi'] ?? '');
    $warna        = $data['warna'] ?? 'primary';
    $ikon         = trim($data['ikon'] ?? '');
    $threshold_max = $data['threshold_max'] ?? null;
    $urutan       = (int) ($data['urutan'] ?? 0);
    $aktif        = (int) ($data['aktif'] ?? 1);

    $errors = [];

    if ($device === '') $errors[] = 'Kode device wajib diisi.';
    if (!in_array($device, ['AF', 'UPS', 'AHU', 'CHILLER'], true)) $errors[] = 'Kode device tidak valid.';
    if ($nama_tampil === '') $errors[] = 'Nama tampil wajib diisi.';

    $warna_valid = ['primary', 'success', 'info', 'warning', 'danger', 'purple'];
    if (!in_array($warna, $warna_valid, true)) $warna = 'primary';

    if ($threshold_max !== null && $threshold_max !== '' && !is_numeric($threshold_max)) {
        $errors[] = 'Threshold harus berupa angka.';
    }

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    try {
        if ($id) {
            // Proses UPDATE
            db_execute(
                "UPDATE `utility_config` SET
                    `nama_tampil` = ?, `deskripsi` = ?, `lokasi` = ?,
                    `threshold_max` = ?, `warna` = ?, `ikon` = ?,
                    `urutan` = ?, `aktif` = ?
                 WHERE `id` = ?",
                [
                    $nama_tampil,
                    $deskripsi ?: null,
                    $lokasi ?: null,
                    ($threshold_max !== null && $threshold_max !== '') ? (float) $threshold_max : null,
                    $warna,
                    $ikon ?: null,
                    $urutan,
                    $aktif,
                    $id
                ]
            );
            log_activity('edit_utility_config', 'utility_config', $id, "Edit config {$device}");
            return ['success' => true, 'message' => "Config {$device} berhasil diupdate.", 'id' => $id];
        } else {
            // Proses INSERT (Tambah Baru)
            $existing = db_fetch_one("SELECT `id` FROM `utility_config` WHERE `device` = ?", [$device]);
            if ($existing) {
                return ['success' => false, 'message' => "Device {$device} sudah ada.", 'id' => null];
            }
            $new_id = db_insert(
                "INSERT INTO `utility_config`
                    (`device`, `nama_tampil`, `deskripsi`, `lokasi`,
                     `threshold_max`, `warna`, `ikon`, `urutan`, `aktif`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $device,
                    $nama_tampil,
                    $deskripsi ?: null,
                    $lokasi ?: null,
                    ($threshold_max !== null && $threshold_max !== '') ? (float) $threshold_max : null,
                    $warna,
                    $ikon ?: null,
                    $urutan,
                    $aktif
                ]
            );
            log_activity('tambah_utility_config', 'utility_config', $new_id, "Tambah config {$device}");
            return ['success' => true, 'message' => "Config {$device} berhasil ditambahkan.", 'id' => $new_id];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

function toggle_utility_config(int $id): array
{
    $cfg = get_utility_config_by_id($id);
    if (!$cfg) return ['success' => false, 'message' => 'Config tidak ditemukan.'];

    $baru = $cfg['aktif'] ? 0 : 1;
    try {
        db_execute("UPDATE `utility_config` SET `aktif` = ? WHERE `id` = ?", [$baru, $id]);
        $verb = $baru ? 'diaktifkan' : 'dinonaktifkan';
        log_activity('toggle_utility_config', 'utility_config', $id, "Config {$cfg['device']} {$verb}");
        return ['success' => true, 'message' => "Config {$cfg['device']} berhasil {$verb}."];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal: ' . $e->getMessage()];
    }
}

function utility_warna_valid(): array
{
    return [
        'primary' => 'Biru',
        'success' => 'Hijau',
        'info'    => 'Cyan',
        'warning' => 'Kuning',
        'danger'  => 'Merah',
        'purple'  => 'Ungu',
    ];
}

// =====================================================================
// DATA AUTO (Wh → kWh di tampilan)
// =====================================================================

/**
 * Ringkasan utility per device, rentang tanggal
 */
function get_utility_stats_range(string $device, string $dari, string $sampai): array
{
    if (!utility_device_valid($device)) {
        return [
            'wh_awal' => null,
            'wh_akhir' => null,
            'delta_wh' => null,
            'delta_kwh' => null,
            'samples' => 0,
            'waktu_akhir' => null,
        ];
    }

    $kolom = $device;
    $dt_dari   = $dari . ' 00:00:00';
    $dt_sampai = $sampai . ' 23:59:59';

    try {
        $first = db_fetch_one(
            "SELECT `{$kolom}` AS v FROM `utility`
             WHERE `dt_create` >= ? ORDER BY `dt_create` ASC LIMIT 1",
            [$dt_dari]
        );
        $last = db_fetch_one(
            "SELECT `{$kolom}` AS v, `dt_create` FROM `utility`
             WHERE `dt_create` <= ? ORDER BY `dt_create` DESC LIMIT 1",
            [$dt_sampai]
        );
        $stats = db_fetch_one(
            "SELECT COUNT(*) AS samples FROM `utility`
             WHERE `dt_create` BETWEEN ? AND ?",
            [$dt_dari, $dt_sampai]
        );

        $wh_awal  = $first ? (float) $first['v'] : null;
        $wh_akhir = $last ? (float) $last['v'] : null;
        $delta_wh = null;

        if ($wh_awal !== null && $wh_akhir !== null) {
            $delta_wh = $wh_akhir - $wh_awal;
        }

        return [
            'wh_awal'      => $wh_awal,
            'wh_akhir'     => $wh_akhir,
            'delta_wh'     => $delta_wh,
            'delta_kwh'    => $delta_wh !== null ? wh_ke_kwh($delta_wh) : null,
            'samples'      => $stats ? (int) $stats['samples'] : 0,
            'waktu_akhir'  => $last['dt_create'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log("[EMOSai util stats] {$device}: " . $e->getMessage());
        return [
            'wh_awal' => null,
            'wh_akhir' => null,
            'delta_wh' => null,
            'delta_kwh' => null,
            'samples' => 0,
            'waktu_akhir' => null,
        ];
    }
}

/**
 * Backward-compat: statistik harian (1 hari)
 */
function get_utility_stats_harian(string $device, string $tanggal): array
{
    return get_utility_stats_range($device, $tanggal, $tanggal);
}

/**
 * Delta kWh per jam untuk 1 device, 1 hari
 */
function get_utility_per_jam(string $device, string $tanggal): array
{
    if (!utility_device_valid($device)) return [];

    $kolom = $device;
    $dari   = $tanggal . ' 00:00:00';
    $sampai = $tanggal . ' 23:59:59';

    $rows = db_fetch_all(
        "SELECT `{$kolom}` AS v, `dt_create`
         FROM `utility`
         WHERE `dt_create` BETWEEN ? AND ?
         ORDER BY `dt_create` ASC",
        [$dari, $sampai]
    );

    $per_jam = [];
    for ($h = 0; $h < 24; $h++) {
        $per_jam[$h] = ['jam' => $h, 'kwh' => 0.0, 'kwh_kumulatif' => 0.0];
    }

    $prev_wh = null;
    foreach ($rows as $r) {
        $jam = (int) substr($r['dt_create'], 11, 2);
        $wh_now = (float) $r['v'];

        if ($prev_wh !== null) {
            $delta_wh = $wh_now - $prev_wh;
            if ($delta_wh > 0 && $delta_wh < 100000000) {
                $per_jam[$jam]['kwh'] += wh_ke_kwh($delta_wh);
            }
        }
        $prev_wh = $wh_now;
    }

    $kumulatif = 0;
    foreach ($per_jam as &$p) {
        $p['kwh'] = round($p['kwh'], 2);
        $kumulatif += $p['kwh'];
        $p['kwh_kumulatif'] = round($kumulatif, 2);
    }
    return array_values($per_jam);
}

/**
 * Ringkasan bulan ini per device
 */
function get_utility_bulan_ini(string $device): array
{
    $dari = date('Y-m-01');
    $sampai = date('Y-m-t');
    $stats = get_utility_stats_range($device, $dari, $sampai);

    $hari = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT DATE(`dt_create`)) FROM `utility`
         WHERE `dt_create` BETWEEN ? AND ?",
        [$dari . ' 00:00:00', $sampai . ' 23:59:59']
    );

    return [
        'total_kwh'    => $stats['delta_kwh'],
        'total_wh'     => $stats['delta_wh'],
        'hari_terdata' => $hari,
        'avg_kwh'      => $hari > 0 && $stats['delta_kwh'] !== null ? round($stats['delta_kwh'] / $hari, 2) : 0,
        'dari'         => $dari,
        'sampai'       => $sampai,
    ];
}

/**
 * Data N hari terakhir untuk grafik
 */
function get_utility_recent(string $device, int $hari = 30): array
{
    if (!utility_device_valid($device)) return [];

    $hasil = [];
    for ($i = $hari - 1; $i >= 0; $i--) {
        $tanggal = date('Y-m-d', strtotime("-{$i} days"));
        $stats = get_utility_stats_range($device, $tanggal, $tanggal);
        $hasil[] = [
            'tanggal'   => $tanggal,
            'delta_wh'  => $stats['delta_wh'],
            'delta_kwh' => $stats['delta_kwh'],
        ];
    }
    return $hasil;
}

/**
 * Ambil data harian dalam rentang — untuk tabel hasil pencarian & chart tren
 */
function get_utility_harian_range(string $device, string $dari, string $sampai): array
{
    if (!utility_device_valid($device)) return [];

    $kolom = $device;
    $hasil = [];

    $ts_dari   = strtotime($dari);
    $ts_sampai = strtotime($sampai);
    $total = (int) floor(($ts_sampai - $ts_dari) / 86400) + 1;

    for ($i = 0; $i < $total; $i++) {
        $tanggal = date('Y-m-d', $ts_dari + $i * 86400);
        $dt_dari = $tanggal . ' 00:00:00';
        $dt_sampai = $tanggal . ' 23:59:59';

        try {
            $first = db_fetch_one(
                "SELECT `{$kolom}` AS v, `dt_create` FROM `utility`
                 WHERE `dt_create` >= ? ORDER BY `dt_create` ASC LIMIT 1",
                [$dt_dari]
            );
            $last = db_fetch_one(
                "SELECT `{$kolom}` AS v, `dt_create` FROM `utility`
                 WHERE `dt_create` <= ? ORDER BY `dt_create` DESC LIMIT 1",
                [$dt_sampai]
            );

            $wh_awal  = $first ? (float) $first['v'] : null;
            $wh_akhir = $last ? (float) $last['v'] : null;
            $delta_wh = null;

            if ($wh_awal !== null && $wh_akhir !== null) {
                $delta_wh = $wh_akhir - $wh_awal;
            }

            $hasil[] = [
                'tanggal'   => $tanggal,
                'wh_awal'   => $wh_awal,
                'wh_akhir'  => $wh_akhir,
                'delta_wh'  => $delta_wh,
                'delta_kwh' => $delta_wh !== null ? wh_ke_kwh($delta_wh) : null,
            ];
        } catch (Throwable $e) {
            $hasil[] = [
                'tanggal' => $tanggal,
                'wh_awal' => null,
                'wh_akhir' => null,
                'delta_wh' => null,
                'delta_kwh' => null,
            ];
        }
    }

    return $hasil;
}

/**
 * Ambil data manual input per hari (delta kWh dari input manual)
 */
function get_utility_manual_harian_range(string $device, string $dari, string $sampai): array
{
    if (!utility_device_valid($device)) return [];

    $hasil = [];
    $ts_dari   = strtotime($dari);
    $ts_sampai = strtotime($sampai);
    $total = (int) floor(($ts_sampai - $ts_dari) / 86400) + 1;

    for ($i = 0; $i < $total; $i++) {
        $tanggal = date('Y-m-d', $ts_dari + $i * 86400);

        $first = db_fetch_one(
            "SELECT `kwh` FROM `manual_inputs_utility`
             WHERE `device` = ? AND `tanggal` = ?
             ORDER BY `jam` ASC LIMIT 1",
            [$device, $tanggal]
        );
        $last = db_fetch_one(
            "SELECT `kwh` FROM `manual_inputs_utility`
             WHERE `device` = ? AND `tanggal` = ?
             ORDER BY `jam` DESC LIMIT 1",
            [$device, $tanggal]
        );

        $wh_awal  = $first ? (float) $first['kwh'] : null;
        $wh_akhir = $last ? (float) $last['kwh'] : null;
        $delta_wh = null;

        if ($wh_awal !== null && $wh_akhir !== null && $wh_akhir > $wh_awal) {
            $delta_wh = $wh_akhir - $wh_awal;
        }

        $hasil[] = [
            'tanggal'   => $tanggal,
            'wh_awal'   => $wh_awal,
            'wh_akhir'  => $wh_akhir,
            'delta_wh'  => $delta_wh,
            'delta_kwh' => $delta_wh !== null ? wh_ke_kwh($delta_wh) : null,
        ];
    }
    return $hasil;
}

/**
 * Nilai terkini (Wh)
 */
function get_utility_terkini(string $device): ?array
{
    if (!utility_device_valid($device)) return null;
    return db_fetch_one(
        "SELECT `{$device}` AS nilai, `dt_create`
         FROM `utility` ORDER BY `dt_create` DESC LIMIT 1"
    );
}

/**
 * Total pemakaian utility hari ini (kWh) — gabungan semua device
 */
function get_utility_total_hari_ini(string $tanggal): float
{
    $devices = get_utility_devices();
    $total = 0;
    foreach ($devices as $d) {
        $stats = get_utility_stats_range($d['device'], $tanggal, $tanggal);
        if ($stats['delta_kwh'] !== null) {
            $total += $stats['delta_kwh'];
        }
    }
    return round($total, 2);
}

// =====================================================================
// MANUAL INPUT & CROSS-CHECK
// =====================================================================

function get_manual_utility(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `manual_inputs_utility` WHERE `id` = ?", [$id]);
}

function get_manual_utility_harian(string $tanggal, ?string $device = null): array
{
    if ($device) {
        return db_fetch_all(
            "SELECT m.*, u.`name` AS nama_user
             FROM `manual_inputs_utility` m
             LEFT JOIN `users` u ON u.`id` = m.`created_by`
             WHERE m.`tanggal` = ? AND m.`device` = ?
             ORDER BY m.`jam` ASC",
            [$tanggal, $device]
        );
    }
    return db_fetch_all(
        "SELECT m.*, u.`name` AS nama_user
         FROM `manual_inputs_utility` m
         LEFT JOIN `users` u ON u.`id` = m.`created_by`
         WHERE m.`tanggal` = ?
         ORDER BY m.`jam` ASC, m.`device` ASC",
        [$tanggal]
    );
}

function get_manual_utility_paginated(array $filter = [], int $page = 1, int $per_page = 30): array
{
    $where = [];
    $params = [];

    if (!empty($filter['dari'])) {
        $where[] = "m.`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "m.`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }
    if (!empty($filter['device'])) {
        $where[] = "m.`device` = ?";
        $params[] = $filter['device'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `manual_inputs_utility` m {$where_sql}", $params);
    $rows = db_fetch_all(
        "SELECT m.*, u.`name` AS nama_user
         FROM `manual_inputs_utility` m
         LEFT JOIN `users` u ON u.`id` = m.`created_by`
         {$where_sql}
         ORDER BY m.`tanggal` DESC, m.`jam` DESC
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

function simpan_manual_utility(array $data): array
{
    $errors = [];
    if (empty($data['tanggal']))        $errors[] = 'Tanggal wajib.';
    if (empty($data['jam']))            $errors[] = 'Jam wajib.';
    if (empty($data['device']))         $errors[] = 'Device wajib.';
    if (!isset($data['kwh']) || $data['kwh'] === '') $errors[] = 'kWh wajib.';
    if (empty($data['nama_penginput'])) $errors[] = 'Nama penginput wajib.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    if (!utility_device_valid($data['device'])) {
        return ['success' => false, 'message' => 'Device tidak valid.', 'id' => null];
    }

    $existing = db_fetch_one(
        "SELECT `id` FROM `manual_inputs_utility`
         WHERE `tanggal` = ? AND `jam` = ? AND `device` = ? LIMIT 1",
        [$data['tanggal'], $data['jam'], $data['device']]
    );
    $is_update = $existing !== null;

    try {
        db_execute(
            "INSERT INTO `manual_inputs_utility`
             (`tanggal`, `jam`, `device`, `kwh`, `kw`, `nama_penginput`, `catatan`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `kwh`            = VALUES(`kwh`),
                `kw`             = VALUES(`kw`),
                `nama_penginput` = VALUES(`nama_penginput`),
                `catatan`        = VALUES(`catatan`),
                `updated_at`     = CURRENT_TIMESTAMP",
            [
                $data['tanggal'],
                $data['jam'],
                $data['device'],
                (float) $data['kwh'],
                isset($data['kw']) && $data['kw'] !== '' ? (float) $data['kw'] : null,
                $data['nama_penginput'],
                $data['catatan'] ?? null,
                $data['created_by'] ?? null
            ]
        );

        $row = db_fetch_one(
            "SELECT `id` FROM `manual_inputs_utility`
             WHERE `tanggal` = ? AND `jam` = ? AND `device` = ? LIMIT 1",
            [$data['tanggal'], $data['jam'], $data['device']]
        );
        $id = $row ? (int) $row['id'] : null;

        log_activity(
            $is_update ? 'edit_manual_util' : 'input_manual_util',
            'manual_inputs_utility',
            $id,
            "Manual utility {$data['device']} tgl {$data['tanggal']} jam {$data['jam']}"
        );

        hitung_crosscheck_utility($id);

        $verb = $is_update ? 'diupdate' : 'disimpan';
        return [
            'success' => true,
            'message' => "Data manual {$data['device']} berhasil {$verb}.",
            'id' => $id,
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

function hapus_manual_utility(int $id): array
{
    try {
        db_execute("DELETE FROM `crosscheck_logs_utility` WHERE `id_manual_input` = ?", [$id]);
        db_execute("DELETE FROM `manual_inputs_utility` WHERE `id` = ?", [$id]);
        log_activity('hapus_manual_util', 'manual_inputs_utility', $id, "Hapus manual utility #{$id}");
        return ['success' => true, 'message' => 'Data manual utility dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

function hitung_crosscheck_utility(int $manual_id): void
{
    $manual = get_manual_utility($manual_id);
    if (!$manual) return;

    $device = $manual['device'];
    if (!utility_device_valid($device)) return;

    $tanggal = $manual['tanggal'];
    $jam     = $manual['jam'];
    $dt_manual = $tanggal . ' ' . $jam;
    $ts = strtotime($dt_manual);

    $dari   = date('Y-m-d H:i:s', $ts - 1800);
    $sampai = date('Y-m-d H:i:s', $ts + 1800);

    try {
        $alat = db_fetch_one(
            "SELECT `{$device}` AS v FROM `utility`
             WHERE `dt_create` BETWEEN ? AND ?
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, `dt_create`, ?)) ASC LIMIT 1",
            [$dari, $sampai, $dt_manual]
        );

        $wh_auto = $alat ? (float) $alat['v'] : null;
        $wh_manual = (float) $manual['kwh'];
        $selisih = null;
        $selisih_persen = null;
        $status = 'pending';

        if ($wh_auto !== null) {
            $selisih = round($wh_auto - $wh_manual, 2);
            $selisih_persen = hitung_selisih_persen($wh_auto, $wh_manual);
            $status = status_crosscheck($selisih_persen);
        }

        db_execute(
            "DELETE FROM `crosscheck_logs_utility`
             WHERE `tanggal` = ? AND `device` = ? AND `id_manual_input` = ?",
            [$tanggal, $device, $manual_id]
        );

        db_insert(
            "INSERT INTO `crosscheck_logs_utility`
             (`tanggal`, `device`, `id_manual_input`, `nilai_auto_kwh`, `nilai_manual_kwh`,
              `selisih`, `selisih_persen`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$tanggal, $device, $manual_id, $wh_auto, $wh_manual, $selisih, $selisih_persen, $status]
        );
    } catch (Throwable $e) {
        error_log('[EMOSai cc_utility] ' . $e->getMessage());
    }
}

function get_crosscheck_utility_ringkasan(string $tanggal): array
{
    $rows = db_fetch_all(
        "SELECT `status`, COUNT(*) AS n FROM `crosscheck_logs_utility`
         WHERE `tanggal` = ? GROUP BY `status`",
        [$tanggal]
    );
    $hasil = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'pending' => 0, 'total' => 0];
    foreach ($rows as $r) {
        $hasil[$r['status']] = (int) $r['n'];
        $hasil['total'] += (int) $r['n'];
    }
    return $hasil;
}

function get_crosscheck_utility_harian(string $tanggal, ?string $device = null): array
{
    if ($device) {
        return db_fetch_all(
            "SELECT c.*, m.`nama_penginput` FROM `crosscheck_logs_utility` c
             LEFT JOIN `manual_inputs_utility` m ON m.`id` = c.`id_manual_input`
             WHERE c.`tanggal` = ? AND c.`device` = ?
             ORDER BY c.`id` ASC",
            [$tanggal, $device]
        );
    }
    return db_fetch_all(
        "SELECT c.*, m.`nama_penginput` FROM `crosscheck_logs_utility` c
         LEFT JOIN `manual_inputs_utility` m ON m.`id` = c.`id_manual_input`
         WHERE c.`tanggal` = ?
         ORDER BY c.`device`, c.`id` ASC",
        [$tanggal]
    );
}
// =====================================================================
// DETEKSI ANOMALI UTILITY
// =====================================================================

/**
 * Deteksi apakah data utility 1 device di 1 hari "tidak lengkap"
 */
function cek_kualitas_data_utility(string $device, string $tanggal): array
{
    if (!utility_device_valid($device)) {
        return ['lengkap' => false, 'alasan' => 'Device tidak valid', 'kode' => 'invalid'];
    }

    $kolom = $device;
    $dt_dari = $tanggal . ' 00:00:00';
    $dt_sampai = $tanggal . ' 23:59:59';

    try {
        // Ambil sample & delta
        $stats = db_fetch_one(
            "SELECT
                COUNT(*) AS samples,
                MIN(`{$kolom}`) AS v_min,
                MAX(`{$kolom}`) AS v_max
             FROM `utility`
             WHERE `dt_create` BETWEEN ? AND ?",
            [$dt_dari, $dt_sampai]
        );

        $samples = (int) ($stats['samples'] ?? 0);
        $delta = ($stats['v_min'] !== null && $stats['v_max'] !== null)
            ? (float) $stats['v_max'] - (float) $stats['v_min']
            : 0;

        // Rule 1: Sample < 72 (setengah hari)
        if ($samples < 72) {
            return [
                'lengkap' => false,
                'alasan'  => "Sample tidak lengkap ({$samples}/144)",
                'kode'    => 'sample_kurang',
                'samples' => $samples,
                'delta'   => $delta,
            ];
        }

        // Rule 2: Delta = 0
        if ($delta == 0) {
            return [
                'lengkap' => false,
                'alasan'  => 'Tidak ada pemakaian tercatat',
                'kode'    => 'kosong',
                'samples' => $samples,
                'delta'   => 0,
            ];
        }

        // Rule 3: Delta terlalu kecil vs rata-rata 7 hari sebelumnya
        $rata7 = db_fetch_one(
            "SELECT AVG(delta_harian) AS avg_delta FROM (
                SELECT
                    DATE(dt_create) AS tgl,
                    MAX(`{$kolom}`) - MIN(`{$kolom}`) AS delta_harian
                FROM `utility`
                WHERE `dt_create` >= DATE_SUB(?, INTERVAL 7 DAY)
                  AND `dt_create` < ?
                GROUP BY DATE(dt_create)
                HAVING delta_harian > 0
            ) AS sub",
            [$dt_dari, $dt_dari]
        );

        $avg_delta = (float) ($rata7['avg_delta'] ?? 0);

        if ($avg_delta > 0 && $delta < ($avg_delta * 0.01)) {
            return [
                'lengkap' => false,
                'alasan'  => 'Delta jauh di bawah rata-rata (kemungkinan stuck)',
                'kode'    => 'kecil',
                'samples' => $samples,
                'delta'   => $delta,
                'avg'     => $avg_delta,
            ];
        }

        // Semua cek lolos
        return [
            'lengkap' => true,
            'alasan'  => '',
            'kode'    => 'ok',
            'samples' => $samples,
            'delta'   => $delta,
        ];
    } catch (Throwable $e) {
        error_log("[EMOSai cek_kualitas] {$device}: " . $e->getMessage());
        return ['lengkap' => false, 'alasan' => 'Gagal cek data', 'kode' => 'error'];
    }
}

/**
 * Ambil daftar tanggal "tidak lengkap" dalam rentang
 */
function get_utility_hari_tidak_lengkap(string $device, string $dari, string $sampai): array
{
    if (!utility_device_valid($device)) return [];

    $hasil = [];
    $ts_dari = strtotime($dari);
    $ts_sampai = strtotime($sampai);
    $total = (int) floor(($ts_sampai - $ts_dari) / 86400) + 1;

    for ($i = 0; $i < $total; $i++) {
        $tanggal = date('Y-m-d', $ts_dari + $i * 86400);
        $cek = cek_kualitas_data_utility($device, $tanggal);

        if (!$cek['lengkap'] && $cek['kode'] !== 'kosong') {
            $hasil[$tanggal] = $cek['alasan'];
        }
    }
    return $hasil;
}
