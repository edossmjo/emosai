<?php

/**
 * =====================================================================
 * EMOSai - Queries: Users
 * Batch 6.3
 * =====================================================================
 */

if (!defined('EMOSAI')) die('Akses langsung tidak diizinkan.');

// =====================================================================
// READ
// =====================================================================

/**
 * Ambil semua user (kecuali diri sendiri — optional)
 */
function get_all_users(bool $include_self = true, ?int $self_id = null): array
{
    if (!$include_self && $self_id) {
        return db_fetch_all(
            "SELECT * FROM `users` WHERE `id` != ? ORDER BY `role` DESC, `name` ASC",
            [$self_id]
        );
    }
    return db_fetch_all("SELECT * FROM `users` ORDER BY `role` DESC, `name` ASC");
}

/**
 * Ambil user by ID
 */
function get_user_by_id(int $id): ?array
{
    return db_fetch_one("SELECT * FROM `users` WHERE `id` = ? LIMIT 1", [$id]);
}

/**
 * Ambil user by email
 */
function get_user_by_email(string $email): ?array
{
    return db_fetch_one("SELECT * FROM `users` WHERE `email` = ? LIMIT 1", [$email]);
}

/**
 * Cek apakah email sudah dipakai (untuk unique validation)
 */
function user_email_sudah_ada(string $email, ?int $kecuali_id = null): bool
{
    if ($kecuali_id) {
        $row = db_fetch_one(
            "SELECT `id` FROM `users` WHERE `email` = ? AND `id` != ? LIMIT 1",
            [$email, $kecuali_id]
        );
    } else {
        $row = db_fetch_one("SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1", [$email]);
    }
    return $row !== null;
}

/**
 * Hitung jumlah admin aktif
 */
function count_admin_aktif(?int $kecuali_id = null): int
{
    if ($kecuali_id) {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `aktif` = 1 AND `id` != ?",
            [$kecuali_id]
        );
    }
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `aktif` = 1"
    );
}

/**
 * Statistik users
 */
function get_users_stats(): array
{
    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `users`");
    $aktif = (int) db_fetch_value("SELECT COUNT(*) FROM `users` WHERE `aktif` = 1");
    $admin = (int) db_fetch_value("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `aktif` = 1");
    $user  = (int) db_fetch_value("SELECT COUNT(*) FROM `users` WHERE `role` = 'user' AND `aktif` = 1");

    return [
        'total'      => $total,
        'aktif'      => $aktif,
        'nonaktif'   => $total - $aktif,
        'admin'      => $admin,
        'user'       => $user,
    ];
}

// =====================================================================
// CREATE
// =====================================================================

/**
 * Simpan user baru
 */
function simpan_user(array $data): array
{
    $nama     = trim($data['name'] ?? '');
    $email    = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $role     = $data['role'] ?? 'user';

    $errors = [];
    if ($nama === '')  $errors[] = 'Nama wajib diisi.';
    if ($email === '') $errors[] = 'Email wajib diisi.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
    if ($password === '') $errors[] = 'Password wajib diisi.';
    if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter.';
    if (!in_array($role, ['admin', 'user'], true)) $errors[] = 'Role tidak valid.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors), 'id' => null];

    if (user_email_sudah_ada($email)) {
        return ['success' => false, 'message' => "Email '{$email}' sudah dipakai.", 'id' => null];
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $new_id = db_insert(
            "INSERT INTO `users`
                (`name`, `email`, `password`, `role`, `aktif`, `email_verified_at`)
             VALUES (?, ?, ?, ?, 1, NOW())",
            [$nama, $email, $hash, $role]
        );

        log_activity('tambah_user', 'users', $new_id, "Tambah user {$email} ({$role})");
        return ['success' => true, 'message' => "User {$nama} berhasil ditambahkan.", 'id' => $new_id];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage(), 'id' => null];
    }
}

// =====================================================================
// UPDATE
// =====================================================================

/**
 * Update user (nama, email, role, aktif)
 * Password tidak diubah di sini
 */
function update_user(int $id, array $data): array
{
    $existing = get_user_by_id($id);
    if (!$existing) return ['success' => false, 'message' => 'User tidak ditemukan.'];

    $nama  = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $role  = $data['role'] ?? 'user';
    $aktif = (int) ($data['aktif'] ?? 1);

    $errors = [];
    if ($nama === '')  $errors[] = 'Nama wajib diisi.';
    if ($email === '') $errors[] = 'Email wajib diisi.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
    if (!in_array($role, ['admin', 'user'], true)) $errors[] = 'Role tidak valid.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors)];

    if (user_email_sudah_ada($email, $id)) {
        return ['success' => false, 'message' => "Email '{$email}' sudah dipakai user lain."];
    }

    // Proteksi: jangan sampai tidak ada admin aktif
    if ($existing['role'] === 'admin' && ($role !== 'admin' || !$aktif)) {
        if (count_admin_aktif($id) === 0) {
            return ['success' => false, 'message' => 'Tidak bisa: harus ada minimal 1 admin aktif.'];
        }
    }

    try {
        db_execute(
            "UPDATE `users` SET
                `name` = ?, `email` = ?, `role` = ?, `aktif` = ?
             WHERE `id` = ?",
            [$nama, $email, $role, $aktif, $id]
        );
        log_activity('edit_user', 'users', $id, "Edit user {$email}");
        return ['success' => true, 'message' => "User {$nama} berhasil diupdate."];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal update: ' . $e->getMessage()];
    }
}

/**
 * Reset password user
 */
function reset_password_user(int $id, string $password_baru): array
{
    $existing = get_user_by_id($id);
    if (!$existing) return ['success' => false, 'message' => 'User tidak ditemukan.'];

    if (strlen($password_baru) < 6) {
        return ['success' => false, 'message' => 'Password minimal 6 karakter.'];
    }

    try {
        $hash = password_hash($password_baru, PASSWORD_BCRYPT);
        db_execute(
            "UPDATE `users` SET `password` = ? WHERE `id` = ?",
            [$hash, $id]
        );
        log_activity('reset_password', 'users', $id, "Reset password user {$existing['email']}");
        return ['success' => true, 'message' => 'Password berhasil direset.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal reset: ' . $e->getMessage()];
    }
}

/**
 * Toggle aktif/nonaktif user
 */
function toggle_user_aktif(int $id, int $current_user_id): array
{
    $existing = get_user_by_id($id);
    if (!$existing) return ['success' => false, 'message' => 'User tidak ditemukan.'];

    // Proteksi: tidak bisa nonaktifkan diri sendiri
    if ($id === $current_user_id) {
        return ['success' => false, 'message' => 'Tidak bisa nonaktifkan akun sendiri.'];
    }

    $aktif_baru = $existing['aktif'] ? 0 : 1;

    // Proteksi: kalau nonaktifkan admin, harus ada admin lain
    if ($existing['role'] === 'admin' && !$aktif_baru) {
        if (count_admin_aktif($id) === 0) {
            return ['success' => false, 'message' => 'Tidak bisa: harus ada minimal 1 admin aktif.'];
        }
    }

    try {
        db_execute("UPDATE `users` SET `aktif` = ? WHERE `id` = ?", [$aktif_baru, $id]);
        $verb = $aktif_baru ? 'diaktifkan' : 'dinonaktifkan';
        log_activity('toggle_user', 'users', $id, "User {$existing['email']} {$verb}");
        return ['success' => true, 'message' => "User {$existing['name']} berhasil {$verb}."];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal: ' . $e->getMessage()];
    }
}

// =====================================================================
// DELETE
// =====================================================================

/**
 * Hapus user
 * Proteksi: tidak bisa hapus diri sendiri, minimal 1 admin harus ada
 */
function hapus_user(int $id, int $current_user_id): array
{
    $existing = get_user_by_id($id);
    if (!$existing) return ['success' => false, 'message' => 'User tidak ditemukan.'];

    // Proteksi: tidak bisa hapus diri sendiri
    if ($id === $current_user_id) {
        return ['success' => false, 'message' => 'Tidak bisa hapus akun sendiri.'];
    }

    // Proteksi: kalau admin, harus ada admin lain
    if ($existing['role'] === 'admin') {
        if (count_admin_aktif($id) === 0) {
            return ['success' => false, 'message' => 'Tidak bisa: harus ada minimal 1 admin aktif.'];
        }
    }

    try {
        db_execute("DELETE FROM `users` WHERE `id` = ?", [$id]);
        log_activity('hapus_user', 'users', $id, "Hapus user {$existing['email']}");
        return ['success' => true, 'message' => "User {$existing['name']} berhasil dihapus."];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()];
    }
}

/**
 * Update last_login user
 */
function update_last_login(int $user_id): void
{
    try {
        db_execute("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?", [$user_id]);
    } catch (Throwable $e) {
        error_log('[EMOSai last_login] ' . $e->getMessage());
    }
}

// =====================================================================
// PROFILE (Batch 6.4)
// =====================================================================

/**
 * Update profil user sendiri (nama + email)
 * Tidak bisa ubah role atau status
 */
function update_profile(int $user_id, array $data): array
{
    $existing = get_user_by_id($user_id);
    if (!$existing) return ['success' => false, 'message' => 'User tidak ditemukan.'];

    $nama  = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));

    $errors = [];
    if ($nama === '')  $errors[] = 'Nama wajib diisi.';
    if ($email === '') $errors[] = 'Email wajib diisi.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';

    if ($errors) return ['success' => false, 'message' => implode(' ', $errors)];

    if (user_email_sudah_ada($email, $user_id)) {
        return ['success' => false, 'message' => "Email '{$email}' sudah dipakai user lain."];
    }

    try {
        db_execute(
            "UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?",
            [$nama, $email, $user_id]
        );

        // Update session biar nama baru langsung muncul
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_name']  = $nama;
        $_SESSION['user_email'] = $email;

        log_activity('update_profile', 'users', $user_id, "Update profil {$email}");
        return ['success' => true, 'message' => 'Profil berhasil diupdate.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal update: ' . $e->getMessage()];
    }
}

/**
 * Ganti password user sendiri
 * Wajib konfirmasi password lama
 */
function ganti_password(int $user_id, string $password_lama, string $password_baru, string $konfirmasi): array
{
    $user = get_user_by_id($user_id);
    if (!$user) return ['success' => false, 'message' => 'User tidak ditemukan.'];

    if ($password_lama === '') return ['success' => false, 'message' => 'Password lama wajib diisi.'];
    if ($password_baru === '') return ['success' => false, 'message' => 'Password baru wajib diisi.'];
    if (strlen($password_baru) < 6) return ['success' => false, 'message' => 'Password baru minimal 6 karakter.'];

    if ($password_baru !== $konfirmasi) {
        return ['success' => false, 'message' => 'Konfirmasi password tidak cocok.'];
    }

    if ($password_lama === $password_baru) {
        return ['success' => false, 'message' => 'Password baru tidak boleh sama dengan yang lama.'];
    }

    // Verifikasi password lama
    if (!password_verify($password_lama, $user['password'])) {
        return ['success' => false, 'message' => 'Password lama salah.'];
    }

    try {
        $hash = password_hash($password_baru, PASSWORD_BCRYPT);
        db_execute("UPDATE `users` SET `password` = ? WHERE `id` = ?", [$hash, $user_id]);

        log_activity('ganti_password', 'users', $user_id, 'Ganti password sendiri');
        return ['success' => true, 'message' => 'Password berhasil diubah.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Gagal ganti password: ' . $e->getMessage()];
    }
}
