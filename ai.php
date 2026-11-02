<?php
if (!defined('EMOSAI')) die('Akses langsung ditolak.');

// Ambil semua data
function get_ai_master_all(): array
{
    return db_fetch_all("SELECT * FROM `ai_analysis_master` ORDER BY `urutan` ASC");
}

// Ambil 1 data by ID
function get_ai_master_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `ai_analysis_master` WHERE `id` = ? LIMIT 1", [$id]);
}

// Simpan / Update
function simpan_ai_master(array $data, ?int $id = null): array
{
    $judul = trim($data['judul'] ?? '');
    $query_type = trim($data['query_type'] ?? '');
    $prompt = trim($data['prompt_instruksi'] ?? '');

    if ($judul === '') return ['success' => false, 'message' => 'Judul wajib diisi.'];
    if ($query_type === '') return ['success' => false, 'message' => 'Query Type wajib diisi.'];
    if ($prompt === '') return ['success' => false, 'message' => 'Prompt Instruksi wajib diisi.'];

    try {
        if ($id) {
            db_execute(
                "UPDATE `ai_analysis_master` SET
                    `judul` = ?, `deskripsi` = ?, `kategori` = ?,
                    `prompt_instruksi` = ?, `query_type` = ?,
                    `ikon` = ?, `warna` = ?, `urutan` = ?, `aktif` = ?
                 WHERE `id` = ?",
                [
                    $judul,
                    $data['deskripsi'] ?? '',
                    $data['kategori'] ?? 'general',
                    $prompt,
                    $query_type,
                    $data['ikon'] ?? 'bi-stars',
                    $data['warna'] ?? 'primary',
                    (int)($data['urutan'] ?? 0),
                    (int)($data['aktif'] ?? 1),
                    $id
                ]
            );
            return ['success' => true, 'message' => 'Data analisis berhasil diupdate.'];
        } else {
            db_insert(
                "INSERT INTO `ai_analysis_master`
                    (`judul`, `deskripsi`, `kategori`, `prompt_instruksi`, `query_type`, `ikon`, `warna`, `urutan`, `aktif`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $judul,
                    $data['deskripsi'] ?? '',
                    $data['kategori'] ?? 'general',
                    $prompt,
                    $query_type,
                    $data['ikon'] ?? 'bi-stars',
                    $data['warna'] ?? 'primary',
                    (int)($data['urutan'] ?? 0),
                    (int)($data['aktif'] ?? 1)
                ]
            );
            return ['success' => true, 'message' => 'Data analisis berhasil ditambahkan.'];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage()];
    }
}

// Hapus
function hapus_ai_master(int $id): array
{
    try {
        db_execute("DELETE FROM `ai_analysis_master` WHERE `id` = ?", [$id]);
        return ['success' => true, 'message' => 'Data analisis berhasil dihapus.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}
