<?php
/**
 * REST API Backend untuk Deploy Website Instan di CWP (CentOS Web Panel)
 * Mendukung pembuatan folder otomatis, file saving, dan ekstrak .ZIP
 * Dilengkapi dengan enkripsi hash password untuk perlindungan berkas tersimpan.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Matikan log error ke browser agar output JSON tetap bersih
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'deploy';
$base_dir = __DIR__;

/**
 * Fungsi pembantu untuk memvalidasi Site ID / Nama Subfolder
 */
function validate_site_id($site_id) {
    $clean_id = preg_replace('/[^a-zA-Z0-9\-_]/', '', $site_id);
    return strtolower(trim($clean_id));
}

/**
 * Validasi password dengan metadata tersimpan
 */
function verify_site_password($site_dir, $password) {
    $meta_file = $site_dir . '/.metadata.json';
    $legacy_lock = $site_dir . '/.password_lock';

    if (file_exists($meta_file)) {
        $meta = json_decode(file_get_contents($meta_file), true);
        if ($meta && isset($meta['password_hash'])) {
            return password_verify($password, $meta['password_hash']);
        }
    }
    
    // Fallback bila password diletakkan sebagai plain text lama
    if (file_exists($legacy_lock)) {
        $stored = trim(file_get_contents($legacy_lock));
        return $stored === $password;
    }

    return true; // Jika folder baru
}

/**
 * AKSI 1: DEPLOY JSON (Upload langsung kode HTML mentah)
 */
if ($action === 'deploy') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $site_id = isset($input['siteId']) ? validate_site_id($input['siteId']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';
    $files = isset($input['files']) ? $input['files'] : [];

    if (empty($site_id)) {
        echo json_encode(['error' => 'Nama website tidak valid. Silakan gunakan huruf, angka, dan tanda hubung saja.']);
        exit;
    }

    if (strlen($password) < 4) {
        echo json_encode(['error' => 'Kata sandi terlalu pendek. Demi keamanan, silakan gunakan minimal 4 karakter.']);
        exit;
    }

    $site_dir = $base_dir . '/' . $site_id;
    $meta_file = $site_dir . '/.metadata.json';

    // Periksa otorisasi folder lama
    if (file_exists($site_dir) && (file_exists($meta_file) || file_exists($site_dir . '/.password_lock'))) {
        if (!verify_site_password($site_dir, $password)) {
            echo json_encode(['error' => 'Website ini sudah terdaftar oleh pengguna lain, atau kata sandi yang dimasukkan tidak sesuai.']);
            exit;
        }
    }

    // Jika folder belum ada, buat foldernya terlebih dahulu
    if (!file_exists($site_dir)) {
        if (!mkdir($site_dir, 0755, true)) {
            echo json_encode(['error' => 'Gagal membuat folder di server. Silakan periksa hak akses direktori Anda.']);
            exit;
        }
    }

    // Perbarui metadata sandi
    $meta_data = [
        'siteId' => $site_id,
        'created_at' => date('Y-m-d H:i:s'),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT)
    ];
    file_put_contents($meta_file, json_encode($meta_data, JSON_PRETTY_PRINT));
    file_put_contents($site_dir . '/.password_lock', $password); // Fallback txt pelindung

    // Simpan berkas HTML / JS / CSS satu per satu
    $written_count = 0;
    foreach ($files as $file) {
        $file_path = isset($file['path']) ? trim($file['path']) : '';
        
        // Proteksi directory traversal (keamanan server CWP)
        if (empty($file_path) || strpos($file_path, '..') !== false || strpos($file_path, '/') === 0 || strpos($file_path, '\\') === 0) {
            continue;
        }

        $full_path = $site_dir . '/' . $file_path;
        $dir_name = dirname($full_path);

        if (!file_exists($dir_name)) {
            mkdir($dir_name, 0755, true);
        }

        $content = isset($file['content']) ? $file['content'] : '';
        if (isset($file['encoding']) && $file['encoding'] === 'base64') {
            $content = base64_decode($content);
        }

        if (file_put_contents($full_path, $content) !== false) {
            $written_count++;
        }
    }

    echo json_encode([
        'success' => true,
        'siteId' => $site_id,
        'files_written' => $written_count,
        'url' => '/' . $site_id . '/'
    ]);
    exit;
}

/**
 * AKSI 2: EXTRACT ZIP DEPLOY (Mengunggah ZIP utuh)
 */
if ($action === 'zip_deploy') {
    $site_id = isset($_POST['siteId']) ? validate_site_id($_POST['siteId']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($site_id) || empty($password)) {
        echo json_encode(['error' => 'Nama website dan kata sandi pelindung wajib diisi.']);
        exit;
    }

    if (!isset($_FILES['zipfile']) || $_FILES['zipfile']['error'] !== UPLOAD_ERR_OK) {
        $err_code = isset($_FILES['zipfile']) ? $_FILES['zipfile']['error'] : -1;
        if ($err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE) {
            $err_msg = 'Ukuran berkas ZIP melebihi batas yang diizinkan server. Coba perkecil ukuran berkas atau hubungi administrator hosting Anda.';
        } elseif ($err_code === UPLOAD_ERR_PARTIAL) {
            $err_msg = 'Berkas ZIP hanya terunggah sebagian. Silakan periksa koneksi internet Anda dan coba lagi.';
        } elseif ($err_code === UPLOAD_ERR_NO_FILE) {
            $err_msg = 'Tidak ada berkas ZIP yang dipilih. Silakan pilih berkas terlebih dahulu.';
        } else {
            $err_msg = 'Terjadi kesalahan saat mengunggah berkas. Pastikan berkas tidak rusak dan coba lagi.';
        }
        echo json_encode(['error' => $err_msg]);
        exit;
    }

    $site_dir = $base_dir . '/' . $site_id;
    $meta_file = $site_dir . '/.metadata.json';

    // Validasi folder lama
    if (file_exists($site_dir) && (file_exists($meta_file) || file_exists($site_dir . '/.password_lock'))) {
        if (!verify_site_password($site_dir, $password)) {
            echo json_encode(['error' => 'Website ini sudah terdaftar. Silakan masukkan kata sandi yang sesuai atau gunakan nama folder lain.']);
            exit;
        }
    }

    if (!file_exists($site_dir)) {
        mkdir($site_dir, 0755, true);
    }

    // Perbarui hash sandi
    $meta_data = [
        'siteId' => $site_id,
        'created_at' => date('Y-m-d H:i:s'),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT)
    ];
    file_put_contents($meta_file, json_encode($meta_data, JSON_PRETTY_PRINT));
    file_put_contents($site_dir . '/.password_lock', $password);

    // Proses bongkar ekstensi ZIP
    if (!class_exists('ZipArchive')) {
        echo json_encode(['error' => 'Ekstensi ZipArchive belum tersedia di server. Silakan hubungi administrator untuk mengaktifkannya.']);
        exit;
    }

    $zip = new ZipArchive;
    if ($zip->open($_FILES['zipfile']['tmp_name']) === TRUE) {
        $files_extracted = 0;

        // Kumpulkan semua entry yang valid terlebih dahulu
        $valid_entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Bersihkan file temporary bawaan OS Mac / DS Store
            if (strpos($filename, '__MACOSX') === 0 || strpos($filename, '.DS_Store') !== false) {
                continue;
            }

            // Keamanan filter path traversal berkas jahat
            if (strpos($filename, '..') !== false) {
                continue;
            }

            $valid_entries[$i] = $filename;
        }

        // Deteksi apakah semua berkas berada dalam satu folder root tunggal
        // Contoh: ZIP berisi "myproject/index.html" -> strip "myproject/" agar langsung ke site_dir
        $strip_prefix = '';
        if (!empty($valid_entries)) {
            $first = reset($valid_entries);
            $slash_pos = strpos($first, '/');
            if ($slash_pos !== false) {
                $candidate = substr($first, 0, $slash_pos + 1);
                $all_in_root = true;
                foreach ($valid_entries as $fname) {
                    if (strpos($fname, $candidate) !== 0) {
                        $all_in_root = false;
                        break;
                    }
                }
                if ($all_in_root) {
                    $strip_prefix = $candidate;
                }
            }
        }

        $real_site_dir = realpath($site_dir);

        // Ekstrak berkas satu per satu dengan dukungan penuh untuk folder
        foreach ($valid_entries as $idx => $filename) {
            // Hilangkan prefix folder root tunggal jika terdeteksi
            $relative = $strip_prefix ? substr($filename, strlen($strip_prefix)) : $filename;
            if ($relative === '' || $relative === false) {
                continue;
            }

            $full_target = $real_site_dir . '/' . ltrim($relative, '/');

            // Handle entry direktori — cukup buat folder-nya
            if (substr($filename, -1) === '/') {
                if (!file_exists($full_target)) {
                    mkdir($full_target, 0755, true);
                }
                continue;
            }

            // Buat folder induk (parent directory) jika belum ada
            $parent_dir = dirname($full_target);
            if (!file_exists($parent_dir)) {
                mkdir($parent_dir, 0755, true);
            }

            // Tulis konten berkas menggunakan getFromIndex untuk kompatibilitas maksimal
            $content = $zip->getFromIndex($idx);
            if ($content !== false) {
                if (file_put_contents($full_target, $content) !== false) {
                    $files_extracted++;
                }
            }
        }
        $zip->close();

        echo json_encode([
            'success' => true,
            'siteId' => $site_id,
            'files_extracted' => $files_extracted,
            'url' => '/' . $site_id . '/'
        ]);
    } else {
        echo json_encode(['error' => 'Berkas ZIP tidak dapat diproses. Pastikan berkas tidak rusak dan coba unggah kembali.']);
    }
    exit;
}

echo json_encode(['error' => 'Permintaan tidak dapat diproses. Aksi API yang diminta tidak dikenali.']);
