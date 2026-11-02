<?php

/**
 * =====================================================================
 * EMOSai - Queries: Lines (Master)
 * Semua query terkait tabel `lines`
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

/**
 * Ambil semua lines, urut berdasarkan `urutan`
 *
 * @param bool $aktif_only  Cuma tampilkan yang aktif
 * @return array
 */
function get_all_lines(bool $aktif_only = true): array
{
    $sql = "SELECT * FROM `lines`";
    if ($aktif_only) {
        $sql .= " WHERE `aktif` = 1";
    }
    $sql .= " ORDER BY `urutan`, `kode`";
    return db_fetch_all($sql);
}

/**
 * Ambil satu line by kode
 *
 * @param string $kode
 * @return array|null
 */
function get_line(string $kode): ?array
{
    return db_fetch_one("SELECT * FROM `lines` WHERE `kode` = ? LIMIT 1", [$kode]);
}

/**
 * Mapping kode → nama tabel mentah
 * Dipakai untuk query data auto dari alat
 *
 * @return array  ['l1' => 'l1', 'lv800' => 'lv800', ...]
 */
function line_tabel_map(): array
{
    return [
        'l1'      => 'l1',
        'l3'      => 'l3',
        'l4'      => 'l4',
        'l5'      => 'l5',
        'l10'     => 'l10',
        'l11'     => 'l11',  // ← BARU (dummy, nanti diganti alat)
        'l510'    => 'l510',
        'lv400'   => 'lv400',
        'lv800'   => 'lv800',
        'lv4k'    => 'lv4k',
        'kubikel' => 'kubikel',
        'utility' => 'utility',
    ];
}

/**
 * Cek apakah kode line punya tabel data mentah
 *
 * @param string $kode
 * @return bool
 */
function line_punya_tabel(string $kode): bool
{
    return isset(line_tabel_map()[$kode]);
}

// =====================================================================
// CRUD LINES (Batch 6.1)
// =====================================================================

/**
 * Ambil line by ID
 */
function get_line_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `lines` WHERE `id` = ? LIMIT 1", [$id]);
}

/**
 * Cek apakah kode sudah dipakai (untuk validasi unique)
 */
function line_kode_sudah_ada(string $kode, ?int $kecuali_id = null): bool
{
    if ($kecuali_id) {
        $row = db_fetch_one(
            "SELECT `id` FROM `lines` WHERE `kode` = ? AND `id` != ? LIMIT 1",
            [$kode, $kecuali_id]
        );
    } else {
        $row = db_fetch_one("SELECT `id` FROM `lines` WHERE `kode` = ? LIMIT 1", [$kode]);
    }
    return $row !== null;
}

/**
 * Simpan / update line
 * Kalau $id diisi → update, kalau tidak → insert baru
 */
function simpan_line(array $data, ?int $id = null): array
{
    $errors = [];

    $kode = strtolower(trim($data['kode'] ?? ''));
    $nama = trim($data['nama'] ?? '');
    $tipe = $data['tipe'] ?? 'line';

    if ($kode === '') $errors[] = 'Kode wajib diisi.';
    if ($nama === '') $errors[] = 'Nama wajib diisi.';

    // Validasi format kode (huruf + angka + underscore)
    if ($kode !== '' && !preg_match('/^[a-z0-9_]+$/', $kode)) {
        $errors[] = 'Kode hanya boleh huruf kecil, angka, dan underscore.';
    }

    // Validasi tipe
    if (!in_array($tipe, ['line', 'trafo', 'panel', 'utility'], true)) {
        $errors[] = 'Tipe tidak valid.';
    }

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    // Cek duplikat kode
    if (line_kode_sudah_ada($kode, $id)) {
        return ['success' => false, 'message' => "Kode '{$kode}' sudah dipakai.", 'id' => null];
    }

    try {
        if ($id) {
            // Update
            db_execute(
                "UPDATE `lines` SET
                    `kode` = ?, `nama` = ?, `tipe` = ?, `lokasi` = ?,
                    `aktif` = ?, `urutan` = ?
                 WHERE `id` = ?",
                [
                    $kode,
                    $nama,
                    $tipe,
                    $data['lokasi'] ?? null,
                    (int) ($data['aktif'] ?? 1),
                    (int) ($data['urutan'] ?? 0),
                    $id
                ]
            );
            log_activity('edit_line', 'lines', $id, "Edit line {$kode}");
            return ['success' => true, 'message' => "Line {$kode} berhasil diupdate.", 'id' => $id];
        } else {
            // Insert
            $new_id = db_insert(
                "INSERT INTO `lines`
                    (`kode`, `nama`, `tipe`, `lokasi`, `aktif`, `urutan`)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $kode,
                    $nama,
                    $tipe,
                    $data['lokasi'] ?? null,
                    (int) ($data['aktif'] ?? 1),
                    (int) ($data['urutan'] ?? 0)
                ]
            );
            log_activity('tambah_line', 'lines', $new_id, "Tambah line {$kode}");
            return ['success' => true, 'message' => "Line {$kode} berhasil ditambahkan.", 'id' => $new_id];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

/**
 * Hapus line
 * Cek dulu apakah ada data terkait (manual_inputs)
 */
function hapus_line(int $id): array
{
    $line = get_line_by_id($id);
    if (!$line) return ['success' => false, 'message' => 'Line tidak ditemukan.'];

    $kode = $line['kode'];

    // Cek relasi — manual_inputs
    $n_manual = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `manual_inputs` WHERE `line_kode` = ?",
        [$kode]
    );
    if ($n_manual > 0) {
        return [
            'success' => false,
            'message' => "Tidak bisa hapus: ada {$n_manual} data manual yang terkait line {$kode}.",
        ];
    }

    try {
        db_execute("DELETE FROM `lines` WHERE `id` = ?", [$id]);
        log_activity('hapus_line', 'lines', $id, "Hapus line {$kode}");
        return ['success' => true, 'message' => "Line {$kode} berhasil dihapus."];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

/**
 * Cek apakah line punya tabel data mentah (untuk disable hapus)
 */
function line_punya_data_tabel(string $kode): bool
{
    return line_punya_tabel($kode);
}
