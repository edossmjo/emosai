<?php

/**
 * =====================================================================
 * EMOSai - Queries: Manual Input & Cross-Check
 * Batch 4 — Tambah CRUD
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

// =====================================================================
// READ
// =====================================================================

/**
 * Ambil input manual 1 hari untuk 1 line
 */
function get_manual_by_hari(string $tanggal, string $line_kode): array
{
    return db_fetch_all(
        "SELECT * FROM `manual_inputs`
         WHERE `tanggal` = ? AND `line_kode` = ?
         ORDER BY `jam` ASC",
        [$tanggal, $line_kode]
    );
}

/**
 * Ambil semua input manual 1 hari (semua line) — untuk list
 */
function get_manual_harian(string $tanggal): array
{
    return db_fetch_all(
        "SELECT m.*, u.`name` AS nama_user
         FROM `manual_inputs` m
         LEFT JOIN `users` u ON u.`id` = m.`created_by`
         WHERE m.`tanggal` = ?
         ORDER BY m.`jam` ASC, m.`line_kode` ASC",
        [$tanggal]
    );
}

/**
 * Ambil input manual dengan pagination + filter
 *
 * @param array $filter  ['tanggal' => '', 'line_kode' => '', 'dari' => '', 'sampai' => '']
 * @param int   $page
 * @param int   $per_page
 * @return array
 */
function get_manual_paginated(array $filter = [], int $page = 1, int $per_page = 50): array
{
    $where = [];
    $params = [];

    if (!empty($filter['tanggal'])) {
        $where[] = "m.`tanggal` = ?";
        $params[] = $filter['tanggal'];
    }
    if (!empty($filter['dari'])) {
        $where[] = "m.`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "m.`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }
    if (!empty($filter['line_kode'])) {
        $where[] = "m.`line_kode` = ?";
        $params[] = $filter['line_kode'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $page = max(1, $page);
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `manual_inputs` m {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT m.*, u.`name` AS nama_user
         FROM `manual_inputs` m
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
        'per_page'   => $per_page,
    ];
}

/**
 * Ambil 1 input manual by ID
 */
function get_manual(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `manual_inputs` WHERE `id` = ? LIMIT 1", [$id]);
}

// =====================================================================
// CREATE
// =====================================================================

/**
 * Simpan input manual listrik baru
 * Return: ['success' => bool, 'message' => string, 'id' => int|null]
 */
function simpan_manual(array $data): array
{
    // Validasi
    $errors = [];
    if (empty($data['tanggal']))      $errors[] = 'Tanggal wajib diisi.';
    if (empty($data['jam']))          $errors[] = 'Jam wajib diisi.';
    if (empty($data['line_kode']))    $errors[] = 'Line wajib dipilih.';
    if (!isset($data['kwh']) || $data['kwh'] === '') $errors[] = 'kWh wajib diisi.';
    if (empty($data['nama_penginput'])) $errors[] = 'Nama penginput wajib diisi.';

    if ($errors) {
        return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];
    }

    // Cek duplikat (line + tanggal + jam sama)
    $existing = db_fetch_one(
        "SELECT `id` FROM `manual_inputs`
         WHERE `tanggal` = ? AND `jam` = ? AND `line_kode` = ? LIMIT 1",
        [$data['tanggal'], $data['jam'], $data['line_kode']]
    );
    if ($existing) {
        return [
            'success' => false,
            'message' => 'Data untuk line, tanggal, dan jam ini sudah ada. Edit yang lama atau ganti jam.',
            'id'      => null
        ];
    }

    try {
        $id = db_insert(
            "INSERT INTO `manual_inputs`
             (`tanggal`, `jam`, `line_kode`, `r_v3p`, `r_v1p`, `r_amp`, `kw`, `kwh`,
              `nama_penginput`, `catatan`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['tanggal'],
                $data['jam'],
                $data['line_kode'],
                $data['r_v3p'] ?? null,
                $data['r_v1p'] ?? null,
                $data['r_amp'] ?? null,
                $data['kw'] ?? null,
                $data['kwh'],
                $data['nama_penginput'],
                $data['catatan'] ?? null,
                $data['created_by'] ?? null,
            ]
        );

        log_activity(
            'input_manual',
            'manual_inputs',
            $id,
            "Input manual {$data['line_kode']} tanggal {$data['tanggal']} jam {$data['jam']}"
        );

        // Auto-hitung cross-check
        hitung_crosscheck_manual($id);

        return ['success' => true, 'message' => 'Data manual berhasil disimpan.', 'id' => $id];
    } catch (Throwable $e) {
        error_log('[EMOSai simpan_manual] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage(), 'id' => null];
    }
}

// =====================================================================
// UPDATE
// =====================================================================

/**
 * Update input manual
 */
function update_manual(int $id, array $data): array
{
    $existing = get_manual($id);
    if (!$existing) {
        return ['success' => false, 'message' => 'Data tidak ditemukan.'];
    }

    if (empty($data['tanggal']) || empty($data['jam']) || empty($data['line_kode'])) {
        return ['success' => false, 'message' => 'Tanggal, jam, dan line wajib diisi.'];
    }

    try {
        db_execute(
            "UPDATE `manual_inputs` SET
                `tanggal` = ?, `jam` = ?, `line_kode` = ?,
                `r_v3p` = ?, `r_v1p` = ?, `r_amp` = ?, `kw` = ?, `kwh` = ?,
                `nama_penginput` = ?, `catatan` = ?
             WHERE `id` = ?",
            [
                $data['tanggal'],
                $data['jam'],
                $data['line_kode'],
                $data['r_v3p'] ?? null,
                $data['r_v1p'] ?? null,
                $data['r_amp'] ?? null,
                $data['kw'] ?? null,
                $data['kwh'],
                $data['nama_penginput'],
                $data['catatan'] ?? null,
                $id
            ]
        );

        log_activity('edit_manual', 'manual_inputs', $id, "Edit data manual #{$id}");

        // Re-hitung cross-check
        hitung_crosscheck_manual($id);

        return ['success' => true, 'message' => 'Data manual berhasil diupdate.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal update: ' . $e->getMessage()];
    }
}

// =====================================================================
// DELETE
// =====================================================================

/**
 * Hapus input manual
 */
function hapus_manual(int $id): array
{
    $existing = get_manual($id);
    if (!$existing) {
        return ['success' => false, 'message' => 'Data tidak ditemukan.'];
    }

    try {
        // Hapus juga cross-check terkait
        db_execute("DELETE FROM `crosscheck_logs` WHERE `id_manual_input` = ?", [$id]);
        db_execute("DELETE FROM `manual_inputs` WHERE `id` = ?", [$id]);

        log_activity('hapus_manual', 'manual_inputs', $id, "Hapus data manual #{$id}");
        return ['success' => true, 'message' => 'Data manual berhasil dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

// =====================================================================
// CROSS-CHECK OTOMATIS
// =====================================================================

/**
 * Hitung cross-check untuk 1 input manual
 * Bandingkan kwh manual vs kwh alat (interpolasi 1 jam sebelum & sesudah)
 */
function hitung_crosscheck_manual(int $manual_id): void
{
    $manual = get_manual($manual_id);
    if (!$manual) return;

    $line = $manual['line_kode'];
    $map  = line_tabel_map();
    if (!isset($map[$line])) return;

    $tabel = $map[$line];
    $tanggal = $manual['tanggal'];
    $jam     = $manual['jam'];

    // Rentang cari: ±30 menit dari jam manual
    $dt_manual = $tanggal . ' ' . $jam;
    $ts = strtotime($dt_manual);
    $dari   = date('Y-m-d H:i:s', $ts - 1800);
    $sampai = date('Y-m-d H:i:s', $ts + 1800);

    try {
        // Ambil sample alat terdekat (dalam ±30 menit)
        $alat = db_fetch_one(
            "SELECT `kwh` FROM `{$tabel}`
             WHERE `dt_create` BETWEEN ? AND ?
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, `dt_create`, ?)) ASC
             LIMIT 1",
            [$dari, $sampai, $dt_manual]
        );

        $nilai_auto = $alat ? (float) $alat['kwh'] : null;
        $nilai_manual = (float) $manual['kwh'];
        $selisih = null;
        $selisih_persen = null;
        $status = 'pending';

        if ($nilai_auto !== null) {
            $selisih = round($nilai_auto - $nilai_manual, 2);
            $selisih_persen = hitung_selisih_persen($nilai_auto, $nilai_manual);
            $status = status_crosscheck($selisih_persen);
        }

        // Cari slot_id yang cocok (berdasarkan jam)
        $slot = db_fetch_one(
            "SELECT `id` FROM `crosscheck_slots`
             WHERE TIME_FORMAT(`jam`, '%H:%i') = TIME_FORMAT(?, '%H:%i') LIMIT 1",
            [$jam]
        );
        $slot_id = $slot ? (int) $slot['id'] : null;

        // Hapus cross-check lama (kalau ada)
        db_execute(
            "DELETE FROM `crosscheck_logs`
             WHERE `tanggal` = ? AND `line_kode` = ? AND `id_manual_input` = ?",
            [$tanggal, $line, $manual_id]
        );

        // Insert cross-check baru
        db_insert(
            "INSERT INTO `crosscheck_logs`
             (`tanggal`, `line_kode`, `slot_id`, `nilai_auto_kwh`, `nilai_manual_kwh`,
              `selisih`, `selisih_persen`, `status`, `id_manual_input`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $tanggal,
                $line,
                $slot_id,
                $nilai_auto,
                $nilai_manual,
                $selisih,
                $selisih_persen,
                $status,
                $manual_id
            ]
        );
    } catch (Throwable $e) {
        error_log('[EMOSai hitung_crosscheck] ' . $e->getMessage());
    }
}

// =====================================================================
// SLOT & RINGKASAN
// =====================================================================

/**
 * Ambil slot aktif, filter berdasarkan tipe
 *
 * @param string|null $tipe  'harian', 'bulanan', atau null (semua)
 */
function get_slots_aktif(?string $tipe = null): array
{
    if ($tipe) {
        return db_fetch_all(
            "SELECT * FROM `crosscheck_slots`
             WHERE `aktif` = 1 AND `tipe` = ?
             ORDER BY `urutan`, `jam`",
            [$tipe]
        );
    }
    return db_fetch_all(
        "SELECT * FROM `crosscheck_slots` WHERE `aktif` = 1 ORDER BY `urutan`, `jam`"
    );
}

/**
 * Ambil slot yang aktif untuk TANGGAL tertentu
 * Return slot harian + slot bulanan (kalau tanggal cocok)
 */
function get_slots_untuk_tanggal(string $tanggal): array
{
    $tgl_num = (int) date('j', strtotime($tanggal));
    $tgl_akhir = (int) date('t', strtotime($tanggal)); // jumlah hari di bulan itu

    // Ambil semua slot aktif
    $all = db_fetch_all(
        "SELECT * FROM `crosscheck_slots` WHERE `aktif` = 1 ORDER BY `urutan`, `jam`"
    );

    $hasil = [];
    foreach ($all as $slot) {
        $tipe = $slot['tipe'] ?? 'harian';

        if ($tipe === 'harian') {
            $hasil[] = $slot;
            continue;
        }

        // Bulanan: cek apakah tanggal cocok
        $hari_ke = $slot['hari_ke'] ?? '';

        if ($hari_ke === 'akhir_bulan') {
            // Cek apakah tanggal ini adalah hari terakhir bulan
            if ($tgl_num === $tgl_akhir) {
                $hasil[] = $slot;
            }
        } elseif (ctype_digit($hari_ke)) {
            // Tanggal spesifik
            if ($tgl_num === (int) $hari_ke) {
                $hasil[] = $slot;
            }
        }
    }

    return $hasil;
}

/**
 * Ambil semua slot (termasuk nonaktif) — untuk admin
 */
function get_slots_semua(): array
{
    return db_fetch_all(
        "SELECT * FROM `crosscheck_slots` ORDER BY `tipe` ASC, `urutan` ASC, `jam` ASC"
    );
}

/**
 * Ringkasan cross-check hari ini
 */
function get_crosscheck_ringkasan(string $tanggal): array
{
    $rows = db_fetch_all(
        "SELECT `status`, COUNT(*) AS n
         FROM `crosscheck_logs`
         WHERE `tanggal` = ?
         GROUP BY `status`",
        [$tanggal]
    );

    $hasil = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'pending' => 0, 'total' => 0];
    foreach ($rows as $r) {
        $hasil[$r['status']] = (int) $r['n'];
        $hasil['total'] += (int) $r['n'];
    }
    return $hasil;
}

/**
 * Ambil log cross-check 1 hari
 */
function get_crosscheck_harian(string $tanggal): array
{
    return db_fetch_all(
        "SELECT c.*, s.`label` AS slot_label, s.`jam` AS slot_jam
         FROM `crosscheck_logs` c
         LEFT JOIN `crosscheck_slots` s ON s.`id` = c.`slot_id`
         WHERE c.`tanggal` = ?
         ORDER BY s.`urutan`, c.`line_kode`",
        [$tanggal]
    );
}

/**
 * Ambil cross-check dengan pagination
 */
function get_crosscheck_paginated(array $filter = [], int $page = 1, int $per_page = 50): array
{
    $where = [];
    $params = [];

    if (!empty($filter['tanggal'])) {
        $where[] = "c.`tanggal` = ?";
        $params[] = $filter['tanggal'];
    }
    if (!empty($filter['status'])) {
        $where[] = "c.`status` = ?";
        $params[] = $filter['status'];
    }
    if (!empty($filter['line_kode'])) {
        $where[] = "c.`line_kode` = ?";
        $params[] = $filter['line_kode'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `crosscheck_logs` c {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT c.*, s.`label` AS slot_label, s.`jam` AS slot_jam
         FROM `crosscheck_logs` c
         LEFT JOIN `crosscheck_slots` s ON s.`id` = c.`slot_id`
         {$where_sql}
         ORDER BY c.`tanggal` DESC, s.`urutan` ASC, c.`line_kode` ASC
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

// =====================================================================
// CRUD SLOT CROSS-CHECK (Batch 6.2)
// =====================================================================

/**
 * Ambil slot by ID
 */
function get_slot_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `crosscheck_slots` WHERE `id` = ? LIMIT 1", [$id]);
}

/**
 * Cek apakah jam sudah ada (untuk validasi unique)
 */
function slot_jam_sudah_ada(string $jam, ?int $kecuali_id = null): bool
{
    if ($kecuali_id) {
        $row = db_fetch_one(
            "SELECT `id` FROM `crosscheck_slots` WHERE `jam` = ? AND `id` != ? LIMIT 1",
            [$jam, $kecuali_id]
        );
    } else {
        $row = db_fetch_one("SELECT `id` FROM `crosscheck_slots` WHERE `jam` = ? LIMIT 1", [$jam]);
    }
    return $row !== null;
}

/**
 * Simpan / update slot
 * Handle tipe harian & bulanan
 */
function simpan_slot(array $data, ?int $id = null): array
{
    $jam   = trim($data['jam'] ?? '');
    $label = trim($data['label'] ?? '');
    $tipe  = $data['tipe'] ?? 'harian';
    $hari_ke = trim($data['hari_ke'] ?? '');

    // Normalisasi jam
    if ($jam === '24:00' || $jam === '24:00:00') $jam = '23:59:59';
    if (preg_match('/^\d{2}:\d{2}$/', $jam)) $jam .= ':00';

    $errors = [];
    if ($jam === '') $errors[] = 'Jam wajib diisi.';
    if ($label === '') $errors[] = 'Label wajib diisi.';
    if (!in_array($tipe, ['harian', 'bulanan'], true)) $errors[] = 'Tipe tidak valid.';

    // Validasi tipe bulanan
    if ($tipe === 'bulanan') {
        if ($hari_ke === '') {
            $errors[] = 'Hari ke wajib untuk slot bulanan.';
        } elseif ($hari_ke !== 'akhir_bulan' && !ctype_digit($hari_ke)) {
            $errors[] = 'Hari ke tidak valid.';
        }
    } else {
        $hari_ke = null; // harian tidak pakai hari_ke
    }

    if ($jam !== '' && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $jam)) {
        $errors[] = 'Format jam tidak valid (HH:MM).';
    }

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    // Cek duplikat: kombinas tipe+hari_ke+jam harus unik
    if ($id) {
        $dup = db_fetch_one(
            "SELECT `id` FROM `crosscheck_slots`
             WHERE `jam` = ? AND `tipe` = ? AND IFNULL(`hari_ke`, '') = IFNULL(?, '')
             AND `id` != ? LIMIT 1",
            [$jam, $tipe, $hari_ke, $id]
        );
    } else {
        $dup = db_fetch_one(
            "SELECT `id` FROM `crosscheck_slots`
             WHERE `jam` = ? AND `tipe` = ? AND IFNULL(`hari_ke`, '') = IFNULL(?, '')
             LIMIT 1",
            [$jam, $tipe, $hari_ke]
        );
    }

    if ($dup) {
        return ['success' => false, 'message' => "Slot {$label} ({$jam}) sudah ada.", 'id' => null];
    }

    try {
        if ($id) {
            db_execute(
                "UPDATE `crosscheck_slots` SET
                    `jam` = ?, `label` = ?, `tipe` = ?, `hari_ke` = ?,
                    `aktif` = ?, `urutan` = ?
                 WHERE `id` = ?",
                [
                    $jam,
                    $label,
                    $tipe,
                    $hari_ke,
                    (int) ($data['aktif'] ?? 1),
                    (int) ($data['urutan'] ?? 0),
                    $id
                ]
            );
            log_activity('edit_slot', 'crosscheck_slots', $id, "Edit slot {$label} {$jam}");
            return ['success' => true, 'message' => "Slot {$label} berhasil diupdate.", 'id' => $id];
        } else {
            $new_id = db_insert(
                "INSERT INTO `crosscheck_slots`
                    (`jam`, `label`, `tipe`, `hari_ke`, `aktif`, `urutan`)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $jam,
                    $label,
                    $tipe,
                    $hari_ke,
                    (int) ($data['aktif'] ?? 1),
                    (int) ($data['urutan'] ?? 0)
                ]
            );
            log_activity('tambah_slot', 'crosscheck_slots', $new_id, "Tambah slot {$label} {$jam}");
            return ['success' => true, 'message' => "Slot {$label} berhasil ditambahkan.", 'id' => $new_id];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

/**
 * Hapus slot
 * Cek dulu apakah ada crosscheck_logs yang pakai
 */
function hapus_slot(int $id): array
{
    $slot = get_slot_by_id($id);
    if (!$slot) return ['success' => false, 'message' => 'Slot tidak ditemukan.'];

    // Cek relasi
    $n_cc = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `crosscheck_logs` WHERE `slot_id` = ?",
        [$id]
    );
    if ($n_cc > 0) {
        return [
            'success' => false,
            'message' => "Tidak bisa hapus: ada {$n_cc} data cross-check yang pakai slot ini. Nonaktifkan saja.",
        ];
    }

    try {
        db_execute("DELETE FROM `crosscheck_slots` WHERE `id` = ?", [$id]);
        log_activity('hapus_slot', 'crosscheck_slots', $id, "Hapus slot {$slot['label']} {$slot['jam']}");
        return ['success' => true, 'message' => "Slot {$slot['label']} berhasil dihapus."];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}
// =====================================================================
// CROSS-CHECK TERPUSAT (Batch 5.1)
// =====================================================================

/**
 * Ambil cross-check logs (listrik) dengan filter + pagination
 */
function get_crosscheck_list_paginated(array $filter = [], int $page = 1, int $per_page = 30): array
{
    $where = [];
    $params = [];

    if (!empty($filter['tanggal'])) {
        $where[] = "c.`tanggal` = ?";
        $params[] = $filter['tanggal'];
    }
    if (!empty($filter['dari'])) {
        $where[] = "c.`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "c.`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }
    if (!empty($filter['line_kode'])) {
        $where[] = "c.`line_kode` = ?";
        $params[] = $filter['line_kode'];
    }
    if (!empty($filter['status'])) {
        $where[] = "c.`status` = ?";
        $params[] = $filter['status'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `crosscheck_logs` c {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT c.*, s.`label` AS slot_label, s.`jam` AS slot_jam,
                m.`jam` AS manual_jam, m.`nama_penginput`, m.`catatan` AS manual_catatan
         FROM `crosscheck_logs` c
         LEFT JOIN `crosscheck_slots` s ON s.`id` = c.`slot_id`
         LEFT JOIN `manual_inputs` m ON m.`id` = c.`id_manual_input`
         {$where_sql}
         ORDER BY c.`tanggal` DESC, c.`id` DESC
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
 * Ambil cross-check logs (utility) dengan filter + pagination
 */
function get_crosscheck_utility_paginated(array $filter = [], int $page = 1, int $per_page = 30): array
{
    $where = [];
    $params = [];

    if (!empty($filter['tanggal'])) {
        $where[] = "c.`tanggal` = ?";
        $params[] = $filter['tanggal'];
    }
    if (!empty($filter['dari'])) {
        $where[] = "c.`tanggal` >= ?";
        $params[] = $filter['dari'];
    }
    if (!empty($filter['sampai'])) {
        $where[] = "c.`tanggal` <= ?";
        $params[] = $filter['sampai'];
    }
    if (!empty($filter['device'])) {
        $where[] = "c.`device` = ?";
        $params[] = $filter['device'];
    }
    if (!empty($filter['status'])) {
        $where[] = "c.`status` = ?";
        $params[] = $filter['status'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $per_page;

    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `crosscheck_logs_utility` c {$where_sql}", $params);

    $rows = db_fetch_all(
        "SELECT c.*, m.`nama_penginput`, m.`jam` AS manual_jam, m.`catatan` AS manual_catatan
         FROM `crosscheck_logs_utility` c
         LEFT JOIN `manual_inputs_utility` m ON m.`id` = c.`id_manual_input`
         {$where_sql}
         ORDER BY c.`tanggal` DESC, c.`id` DESC
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
 * Statistik cross-check (listrik + utility) — periode tertentu
 */
function get_crosscheck_stats(string $dari, string $sampai, ?string $line_kode = null): array
{
    // Listrik
    $where_line = $line_kode ? "AND `line_kode` = ?" : "";
    $params_line = $line_kode ? [$dari, $sampai, $line_kode] : [$dari, $sampai];

    $listrik = db_fetch_one(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN `status` = 'ok' THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN `status` = 'warning' THEN 1 ELSE 0 END) AS warning,
            SUM(CASE WHEN `status` = 'critical' THEN 1 ELSE 0 END) AS critical,
            SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) AS pending
         FROM `crosscheck_logs`
         WHERE `tanggal` BETWEEN ? AND ? {$where_line}",
        $params_line
    );

    // Utility
    $utility = db_fetch_one(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN `status` = 'ok' THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN `status` = 'warning' THEN 1 ELSE 0 END) AS warning,
            SUM(CASE WHEN `status` = 'critical' THEN 1 ELSE 0 END) AS critical,
            SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) AS pending
         FROM `crosscheck_logs_utility`
         WHERE `tanggal` BETWEEN ? AND ?",
        [$dari, $sampai]
    );

    return [
        'listrik' => [
            'total'    => (int) ($listrik['total'] ?? 0),
            'ok'       => (int) ($listrik['ok'] ?? 0),
            'warning'  => (int) ($listrik['warning'] ?? 0),
            'critical' => (int) ($listrik['critical'] ?? 0),
            'pending'  => (int) ($listrik['pending'] ?? 0),
        ],
        'utility' => [
            'total'    => (int) ($utility['total'] ?? 0),
            'ok'       => (int) ($utility['ok'] ?? 0),
            'warning'  => (int) ($utility['warning'] ?? 0),
            'critical' => (int) ($utility['critical'] ?? 0),
            'pending'  => (int) ($utility['pending'] ?? 0),
        ],
    ];
}

/**
 * Data tren cross-check per hari (listrik)
 */
function get_crosscheck_trend_harian(string $dari, string $sampai): array
{
    $rows = db_fetch_all(
        "SELECT `tanggal`,
                COUNT(*) AS total,
                SUM(CASE WHEN `status` = 'ok' THEN 1 ELSE 0 END) AS ok,
                SUM(CASE WHEN `status` = 'warning' THEN 1 ELSE 0 END) AS warning,
                SUM(CASE WHEN `status` = 'critical' THEN 1 ELSE 0 END) AS critical,
                AVG(ABS(`selisih_persen`)) AS avg_selisih_persen
         FROM `crosscheck_logs`
         WHERE `tanggal` BETWEEN ? AND ?
         GROUP BY `tanggal`
         ORDER BY `tanggal` ASC",
        [$dari, $sampai]
    );
    return $rows;
}

/**
 * Detail cross-check by ID (listrik)
 */
function get_crosscheck_detail(int $id): ?array
{
    return db_fetch_one(
        "SELECT c.*, s.`label` AS slot_label, s.`jam` AS slot_jam,
                m.`r_v3p`, m.`r_v1p`, m.`r_amp`, m.`kw` AS manual_kw,
                m.`kwh` AS manual_kwh, m.`nama_penginput`, m.`catatan` AS manual_catatan,
                m.`created_at` AS manual_created
         FROM `crosscheck_logs` c
         LEFT JOIN `crosscheck_slots` s ON s.`id` = c.`slot_id`
         LEFT JOIN `manual_inputs` m ON m.`id` = c.`id_manual_input`
         WHERE c.`id` = ? LIMIT 1",
        [$id]
    );
}

/**
 * Detail cross-check utility by ID
 */
function get_crosscheck_utility_detail(int $id): ?array
{
    return db_fetch_one(
        "SELECT c.*, m.`nama_penginput`, m.`jam` AS manual_jam,
                m.`kw` AS manual_kw, m.`catatan` AS manual_catatan,
                m.`created_at` AS manual_created
         FROM `crosscheck_logs_utility` c
         LEFT JOIN `manual_inputs_utility` m ON m.`id` = c.`id_manual_input`
         WHERE c.`id` = ? LIMIT 1",
        [$id]
    );
}
// =====================================================================
// ALERTS TERPUSAT (Batch 5.3)
// =====================================================================

/**
 * Ambil semua alert terbaru dari berbagai sumber
 * @param int $limit_hari Berapa hari ke belakang yang mau dicek
 */
function get_semua_alerts(int $limit_hari = 7): array
{
    $alerts = [];
    $dari = date('Y-m-d', strtotime("-{$limit_hari} days"));
    $sampai = today_wib();

    // 1. Cross-Check Listrik (Critical)
    $cc_listrik = db_fetch_all(
        "SELECT c.*, m.nama_penginput
         FROM `crosscheck_logs` c
         LEFT JOIN `manual_inputs` m ON m.id = c.id_manual_input
         WHERE c.status = 'critical' AND c.tanggal BETWEEN ? AND ?
         ORDER BY c.tanggal DESC, c.id DESC",
        [$dari, $sampai]
    );
    foreach ($cc_listrik as $c) {
        $alerts[] = [
            'tipe' => 'crosscheck_listrik',
            'tanggal' => $c['tanggal'],
            'judul' => "Critical Cross-Check: {$c['line_kode']}",
            'pesan' => "Selisih data auto vs manual terlalu jauh. Auto: {$c['nilai_auto_kwh']} Wh, Manual: {$c['nilai_manual_kwh']} Wh (Selisih: {$c['selisih_persen']}%)",
            'warna' => 'danger',
            'ikon' => 'bi-exclamation-octagon-fill',
            'link' => url('crosscheck.detail', ['id' => $c['id'], 'tipe' => 'listrik']),
            'petugas' => $c['nama_penginput'] ?? '-'
        ];
    }

    // 2. Cross-Check Utility (Critical)
    $cc_utility = db_fetch_all(
        "SELECT c.*, m.nama_penginput
         FROM `crosscheck_logs_utility` c
         LEFT JOIN `manual_inputs_utility` m ON m.id = c.id_manual_input
         WHERE c.status = 'critical' AND c.tanggal BETWEEN ? AND ?
         ORDER BY c.tanggal DESC, c.id DESC",
        [$dari, $sampai]
    );
    foreach ($cc_utility as $c) {
        $alerts[] = [
            'tipe' => 'crosscheck_utility',
            'tanggal' => $c['tanggal'],
            'judul' => "Critical Cross-Check Utility: {$c['device']}",
            'pesan' => "Selisih data auto vs manual terlalu jauh. Auto: {$c['nilai_auto_kwh']} Wh, Manual: {$c['nilai_manual_kwh']} Wh (Selisih: {$c['selisih_persen']}%)",
            'warna' => 'danger',
            'ikon' => 'bi-exclamation-octagon-fill',
            'link' => url('utility.list', ['ut_dari' => $c['tanggal']]),
            'petugas' => $c['nama_penginput'] ?? '-'
        ];
    }

    // 3. Gas Over Threshold (Harian)
    // Ambil data gas harian yang melebihi max_harian_mmbtu
    $gas_rows = db_fetch_all(
        "SELECT tanggal, SUM(pemakaian) as total_m3
         FROM `gas_usage`
         WHERE tanggal BETWEEN ? AND ?
         GROUP BY tanggal",
        [$dari, $sampai]
    );
    $faktor_gas = (float) get_gas_konversi()['faktor_mmbtu'];
    $max_harian_gas = (float) (get_gas_konversi()['max_harian_mmbtu'] ?? 0);

    if ($max_harian_gas > 0) {
        foreach ($gas_rows as $g) {
            $mmbtu = m3_ke_mmbtu((float) $g['total_m3'], $faktor_gas);
            if ($mmbtu > $max_harian_gas) {
                $alerts[] = [
                    'tipe' => 'gas_over',
                    'tanggal' => $g['tanggal'],
                    'judul' => "Over Threshold Gas",
                    'pesan' => "Pemakaian gas harian melebihi batas. Total: " . number_format($mmbtu, 2) . " MMBTU (Max: " . number_format($max_harian_gas, 2) . " MMBTU)",
                    'warna' => 'warning',
                    'ikon' => 'bi-fire',
                    'link' => url('gas.list', ['dari' => $g['tanggal'], 'sampai' => $g['tanggal']]),
                    'petugas' => '-'
                ];
            }
        }
    }

    // Sort by tanggal DESC
    usort($alerts, function ($a, $b) {
        return strcmp($b['tanggal'], $a['tanggal']);
    });

    return $alerts;
}
