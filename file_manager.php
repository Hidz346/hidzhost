<?php
/**
 * Single-File PHP File Manager untuk Kelola Berkas Web Hosting CWP
 * Dilengkapi visual editor berbasis Monaco/Ace Editor, pembuatan file baru,
 * penghapusan file, serta enkripsi proteksi password yang handal.
 */

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

$base_dir = __DIR__;

// Penanganan Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['cwp_logged_in']);
    unset($_SESSION['cwp_site_id']);
    header('Location: file_manager.php');
    exit;
}

// Proses Login Otorisasi
$auth_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $site_id = preg_replace('/[^a-zA-Z0-9\-_]/', '', $_POST['site_id']);
    $site_id = strtolower(trim($site_id));
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($site_id) || empty($password)) {
        $auth_err = 'Nama subfolder dan kata sandi wajib diisi untuk melanjutkan.';
    } else {
        $target_dir = $base_dir . '/' . $site_id;
        $meta_file = $target_dir . '/.metadata.json';
        $lock_file = $target_dir . '/.password_lock';

        if (!file_exists($target_dir)) {
            $auth_err = 'Folder website tidak ditemukan di server. Silakan buat terlebih dahulu melalui halaman utama.';
        } else {
            // Verifikasi Password
            $authorized = false;
            if (file_exists($meta_file)) {
                $meta = json_decode(file_get_contents($meta_file), true);
                if ($meta && isset($meta['password_hash'])) {
                    if (password_verify($password, $meta['password_hash'])) {
                        $authorized = true;
                    }
                }
            }
            
            // Fallback plain key checking
            if (!$authorized && file_exists($lock_file)) {
                $stored = trim(file_get_contents($lock_file));
                if ($stored === $password) {
                    $authorized = true;
                }
            }

            if ($authorized) {
                $_SESSION['cwp_logged_in'] = true;
                $_SESSION['cwp_site_id'] = $site_id;
                header('Location: file_manager.php');
                exit;
            } else {
                $auth_err = 'Kata sandi yang Anda masukkan tidak sesuai. Silakan periksa kembali atau hubungi pemilik website.';
            }
        }
    }
}

// Pre-fill site_id dari URL query parameter jika ada (?siteId=xxx)
$prefill_site_id = isset($_GET['siteId']) ? preg_replace('/[^a-zA-Z0-9\-_]/', '', $_GET['siteId']) : '';

// Periksa apakah sudah berhasil login
if (!isset($_SESSION['cwp_logged_in']) || $_SESSION['cwp_logged_in'] !== true) {
    // TAMPILKAN PAGE LOGIN JIKA BELUM TERAUTENTIKASI
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Masuk ke Pengelola Berkas — HidzHost</title>
        <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
        <style>
            :root {
                --pink: #FF2D78;
                --cyan: #00E5FF;
                --lime: #BFFF00;
                --yellow: #FFE600;
                --purple: #B537F2;
                --blue: #3D5AFE;
                --bg: #FFF5F9;
                --card-bg: #FFFFFF;
                --text-dark: #1a1a2e;
                --border-thick: 3px solid #1a1a2e;
                --shadow: 5px 5px 0px #1a1a2e;
                --shadow-lg: 8px 8px 0px #1a1a2e;
                --success: #00C853;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: var(--bg);
                color: var(--text-dark);
                font-family: 'Inter', sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                position: relative;
                overflow: hidden;
            }
            body::before {
                content: '';
                position: fixed;
                top: 0; left: 0; width: 100%; height: 100%;
                background:
                    radial-gradient(circle at 20% 30%, rgba(255, 45, 120, 0.12) 0%, transparent 45%),
                    radial-gradient(circle at 80% 70%, rgba(0, 229, 255, 0.1) 0%, transparent 45%);
                pointer-events: none;
            }
            .sparkle {
                position: fixed;
                pointer-events: none;
                font-size: 22px;
                opacity: 0.3;
                animation: float-sparkle 6s ease-in-out infinite;
            }
            @keyframes float-sparkle {
                0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
                50% { transform: translateY(-20px) rotate(180deg); opacity: 0.55; }
            }
            .login-card {
                background: var(--card-bg);
                border: var(--border-thick);
                border-radius: 20px;
                padding: 36px;
                width: 100%;
                max-width: 440px;
                box-shadow: var(--shadow-lg);
                position: relative;
                z-index: 1;
            }
            .login-card::before {
                content: '✦';
                position: absolute;
                top: -16px;
                right: 24px;
                font-size: 28px;
                color: var(--purple);
            }
            .login-header {
                text-align: center;
                margin-bottom: 28px;
            }
            .login-header h1 {
                font-family: 'Rubik', sans-serif;
                font-size: 26px;
                font-weight: 900;
                color: var(--text-dark);
                margin-bottom: 8px;
                text-transform: uppercase;
            }
            .login-header .title-badge {
                display: inline-block;
                background: var(--cyan);
                color: var(--text-dark);
                padding: 2px 10px;
                border-radius: 6px;
                border: 2px solid var(--text-dark);
                box-shadow: 2px 2px 0px var(--text-dark);
                font-size: 20px;
            }
            .login-header p {
                font-size: 14px;
                color: #666;
                margin-top: 8px;
                line-height: 1.5;
            }
            .form-group {
                margin-bottom: 20px;
            }
            label {
                display: block;
                font-size: 12px;
                font-weight: 700;
                color: var(--text-dark);
                margin-bottom: 6px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-family: 'Rubik', sans-serif;
            }
            input {
                width: 100%;
                background: #FAFAFA;
                border: var(--border-thick);
                border-radius: 12px;
                padding: 13px 16px;
                color: var(--text-dark);
                font-size: 14px;
                transition: all 0.2s;
                box-shadow: 3px 3px 0px var(--text-dark);
            }
            input:focus {
                outline: none;
                border-color: var(--pink);
                box-shadow: 3px 3px 0px var(--pink);
                background: white;
            }
            .btn-login {
                width: 100%;
                background: var(--pink);
                color: white;
                border: var(--border-thick);
                border-radius: 12px;
                padding: 14px;
                font-weight: 800;
                font-size: 15px;
                cursor: pointer;
                transition: all 0.15s;
                box-shadow: var(--shadow);
                font-family: 'Rubik', sans-serif;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .btn-login:hover {
                transform: translate(-2px, -2px);
                box-shadow: 7px 7px 0px var(--text-dark);
                background: #E91E6B;
            }
            .btn-login:active {
                transform: translate(3px, 3px);
                box-shadow: 2px 2px 0px var(--text-dark);
            }
            .error-alert {
                background: #FFF3F0;
                border: 3px solid #E53935;
                color: #C62828;
                padding: 12px 16px;
                border-radius: 12px;
                font-size: 13px;
                font-weight: 600;
                margin-bottom: 20px;
                line-height: 1.5;
                box-shadow: 3px 3px 0px #C62828;
            }
            .foot-link {
                text-align: center;
                margin-top: 20px;
                font-size: 13px;
            }
            .foot-link a {
                color: var(--text-dark);
                text-decoration: none;
                font-weight: 700;
                background: var(--yellow);
                padding: 6px 14px;
                border-radius: 8px;
                border: 2px solid var(--text-dark);
                box-shadow: 2px 2px 0px var(--text-dark);
                display: inline-block;
                transition: all 0.15s;
            }
            .foot-link a:hover {
                transform: translate(-1px, -1px);
                box-shadow: 3px 3px 0px var(--text-dark);
            }
        </style>
    </head>
    <body>
        <div class="sparkle" style="top: 15%; left: 10%;">✦</div>
        <div class="sparkle" style="top: 70%; right: 8%; animation-delay: 1.5s;">✧</div>
        <div class="sparkle" style="top: 40%; left: 5%; animation-delay: 3s;">⟡</div>

        <div class="login-card">
            <div class="login-header">
                <h1>📂 <span class="title-badge">Pengelola</span> Berkas</h1>
                <p>Masukkan identitas website Anda untuk mulai mengelola berkas dengan aman dan mudah.</p>
            </div>

            <?php if (!empty($auth_err)): ?>
                <div class="error-alert">⚠️ <?= htmlspecialchars($auth_err) ?></div>
            <?php endif; ?>

            <form action="file_manager.php" method="POST">
                <div class="form-group">
                    <label>✦ Nama Subfolder Website</label>
                    <input type="text" name="site_id" required value="<?= htmlspecialchars($prefill_site_id) ?>" placeholder="contoh: portofolioku" autocomplete="off">
                </div>

                <div class="form-group">
                    <label>✦ Kata Sandi Keamanan</label>
                    <input type="password" name="password" required placeholder="Masukan Sandi Pelindung Anda">
                </div>

                <button type="submit" name="login_submit" class="btn-login">🔓 Verifikasi & Buka Berkas</button>
            </form>

            <div class="foot-link">
                <a href="index.html">← Kembali ke Halaman Utama</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// SESI BERHASIL MASUK (AUTHORIZED VIEW)
// ==========================================

$site_id = $_SESSION['cwp_site_id'];
$site_dir = $base_dir . '/' . $site_id;

// Proteksi jika folder dihapus secara manual setelah login
if (!file_exists($site_dir)) {
    unset($_SESSION['cwp_logged_in']);
    header('Location: file_manager.php');
    exit;
}

// Handler AJAX API untuk muat isi file, simpan file, buat folder baru, dan hapus berkas
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api_action = $_GET['api'];

    if ($api_action === 'get_file') {
        $file = isset($_GET['file']) ? trim($_GET['file']) : '';
        // Proteksi directory traversal
        if (strpos($file, '..') !== false || strpos($file, '/') === 0) {
            echo json_encode(['error' => 'Akses berkas tidak diizinkan.']);
            exit;
        }

        $full_file_path = $site_dir . '/' . $file;
        if (!file_exists($full_file_path) || is_dir($full_file_path)) {
            echo json_encode(['error' => 'Berkas tidak ditemukan.']);
            exit;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // Berkas gambar — kirim sebagai base64 untuk pratinjau langsung
        $image_exts = ['jpg','jpeg','png','gif','webp','ico','bmp','avif'];
        if (in_array($ext, $image_exts)) {
            $mime = function_exists('mime_content_type') ? (mime_content_type($full_file_path) ?: 'image/' . $ext) : 'image/' . $ext;
            echo json_encode([
                'success'   => true,
                'file_type' => 'image',
                'mime'      => $mime,
                'content'   => base64_encode(file_get_contents($full_file_path)),
                'filename'  => basename($file)
            ]);
            exit;
        }

        // Berkas teks — kirim isi langsung ke editor
        $text_exts = ['html','htm','css','js','mjs','cjs','ts','tsx','jsx','vue',
                      'json','yaml','yml','toml','xml','svg','md','txt','log','csv',
                      'php','py','rb','go','rs','java','c','cpp','h','sql',
                      'sh','bat','env','htaccess','scss','sass','less'];
        if (in_array($ext, $text_exts) || $ext === '') {
            $raw = file_get_contents($full_file_path);
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', mb_detect_encoding($raw) ?: 'auto');
            }
            echo json_encode(['success' => true, 'file_type' => 'text', 'content' => $raw]);
            exit;
        }

        // Ekstensi tidak dikenal — deteksi otomatis teks atau biner
        $raw = file_get_contents($full_file_path);
        if (mb_check_encoding($raw, 'UTF-8') && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw)) {
            echo json_encode(['success' => true, 'file_type' => 'text', 'content' => $raw]);
        } else {
            echo json_encode([
                'success'   => true,
                'file_type' => 'binary',
                'filename'  => basename($file),
                'size'      => filesize($full_file_path),
                'ext'       => $ext
            ]);
        }
        exit;
    }

    if ($api_action === 'save_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $file = isset($input['file']) ? trim($input['file']) : '';
        $content = isset($input['content']) ? $input['content'] : '';

        if (strpos($file, '..') !== false || strpos($file, '/') === 0 || empty($file)) {
            echo json_encode(['error' => 'Izin menulis berkas ditolak.']);
            exit;
        }

        // Jangan izinkan mengubah file config internal via editor
        if ($file === '.metadata.json' || $file === '.password_lock') {
            echo json_encode(['error' => 'Izin ditolak untuk memodifikasi pengaturan keamanan sistem.']);
            exit;
        }

        $full_file_path = $site_dir . '/' . $file;

        // Buat folder induk jika belum ada (penting untuk berkas dalam subfolder)
        $parent_dir = dirname($full_file_path);
        if (!file_exists($parent_dir)) {
            mkdir($parent_dir, 0755, true);
        }

        if (file_put_contents($full_file_path, $content) !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Gagal menyimpan berkas di server. Silakan periksa kapasitas penyimpanan.']);
        }
        exit;
    }

    if ($api_action === 'create_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $filename = isset($input['filename']) ? trim($input['filename']) : '';

        if (strpos($filename, '..') !== false || strpos($filename, '/') === 0 || empty($filename)) {
            echo json_encode(['error' => 'Nama berkas tidak valid.']);
            exit;
        }

        $full_path = $site_dir . '/' . $filename;
        if (file_exists($full_path)) {
            echo json_encode(['error' => 'Berkas dengan nama tersebut sudah ada.']);
            exit;
        }

        $dir = dirname($full_path);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($full_path, '') !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Gagal membuat berkas baru.']);
        }
        exit;
    }

    if ($api_action === 'delete_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $file = isset($input['file']) ? trim($input['file']) : '';

        if (strpos($file, '..') !== false || strpos($file, '/') === 0 || empty($file)) {
            echo json_encode(['error' => 'Izin menghapus ditolak.']);
            exit;
        }

        if ($file === '.metadata.json' || $file === '.password_lock') {
            echo json_encode(['error' => 'Berkas keamanan dilindungi dan tidak dapat dihapus.']);
            exit;
        }

        $full_path = $site_dir . '/' . $file;
        if (file_exists($full_path)) {
            if (is_dir($full_path)) {
                // Hapus folder rekursif
                function delTree($dir) {
                    $files = array_diff(scandir($dir), array('.','..'));
                    foreach ($files as $file) {
                        (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
                    }
                    return rmdir($dir);
                }
                if (delTree($full_path)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['error' => 'Gagal menghapus folder.']);
                }
            } else {
                if (unlink($full_path)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['error' => 'Gagal menghapus berkas.']);
                }
            }
        } else {
            echo json_encode(['error' => 'Berkas tidak ditemukan.']);
        }
        exit;
    }

    if ($api_action === 'download_file') {
        $file = isset($_GET['file']) ? trim($_GET['file']) : '';
        if (strpos($file, '..') !== false || strpos($file, '/') === 0 || empty($file)) {
            http_response_code(403);
            exit('Akses ditolak.');
        }
        $full_file_path = $site_dir . '/' . $file;
        if (!file_exists($full_file_path) || is_dir($full_file_path)) {
            http_response_code(404);
            exit('Berkas tidak ditemukan.');
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($full_file_path) ?: 'application/octet-stream') : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($full_file_path));
        header('Cache-Control: no-cache');
        readfile($full_file_path);
        exit;
    }

    echo json_encode(['error' => 'Aksi API tidak valid.']);
    exit;
}

// Fungsi helper ikon berkas berdasarkan ekstensi
function get_file_icon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'html' => '🌐', 'htm' => '🌐',
        'css' => '🎨', 'scss' => '🎨', 'sass' => '🎨', 'less' => '🎨',
        'js' => '⚡', 'mjs' => '⚡', 'cjs' => '⚡', 'ts' => '⚡', 'tsx' => '⚡', 'jsx' => '⚡',
        'json' => '📋', 'yaml' => '📋', 'yml' => '📋', 'xml' => '📋', 'toml' => '📋',
        'php' => '🐘',
        'md' => '📝', 'txt' => '📝', 'log' => '📝',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️',
        'webp' => '🖼️', 'ico' => '🖼️', 'bmp' => '🖼️', 'avif' => '🖼️', 'svg' => '🖼️',
        'pdf' => '📕',
        'mp4' => '🎬', 'webm' => '🎬', 'mov' => '🎬', 'mkv' => '🎬', 'avi' => '🎬',
        'mp3' => '🎵', 'wav' => '🎵', 'ogg' => '🎵', 'flac' => '🎵',
        'zip' => '📦', 'tar' => '📦', 'gz' => '📦', 'rar' => '📦', '7z' => '📦',
        'sql' => '🗄️', 'db' => '🗄️', 'sqlite' => '🗄️',
        'py' => '💻', 'rb' => '💻', 'go' => '💻', 'sh' => '💻', 'bat' => '💻',
        'ttf' => '🔤', 'woff' => '🔤', 'woff2' => '🔤', 'eot' => '🔤',
        'env' => '🔒',
    ];
    return isset($icons[$ext]) ? $icons[$ext] : '📄';
}

// Scan daftar berkas secara rekursif
function get_site_files($dir, $relative_path = '') {
    $results = [];
    $abs_path = empty($relative_path) ? $dir : $dir . '/' . $relative_path;
    $items = array_diff(scandir($abs_path), array('.', '..'));

    foreach ($items as $item) {
        $path = empty($relative_path) ? $item : $relative_path . '/' . $item;
        $full_path = $abs_path . '/' . $item;
        
        // Sembunyikan file metadata rahasia
        if ($item === '.metadata.json' || $item === '.password_lock') {
            continue;
        }

        if (is_dir($full_path)) {
            $results[] = [
                'name' => $item,
                'path' => $path,
                'is_dir' => true,
                'children' => get_site_files($dir, $path)
            ];
        } else {
            $results[] = [
                'name' => $item,
                'path' => $path,
                'is_dir' => false,
                'size' => filesize($full_path)
            ];
        }
    }

    // Urutkan folder dulu kemudian file
    usort($results, function($a, $b) {
        if ($a['is_dir'] && !$b['is_dir']) return -1;
        if (!$a['is_dir'] && $b['is_dir']) return 1;
        return strcmp($a['name'], $b['name']);
    });

    return $results;
}

$file_tree = get_site_files($site_dir);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengelola Berkas: <?= htmlspecialchars($site_id) ?> — HidzHost</title>
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- Ace Editor Library via CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.15.0/ace.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <style>
        :root {
            --pink: #FF2D78;
            --cyan: #00E5FF;
            --lime: #BFFF00;
            --yellow: #FFE600;
            --purple: #B537F2;
            --blue: #3D5AFE;
            --bg: #FFF5F9;
            --sidebar-bg: #FFFFFF;
            --editor-header: #FFF8E1;
            --border-thick: 3px solid #1a1a2e;
            --border-thin: 2px solid #1a1a2e;
            --text-dark: #1a1a2e;
            --text-muted: #777;
            --shadow: 5px 5px 0px #1a1a2e;
            --shadow-sm: 3px 3px 0px #1a1a2e;
            --success: #00C853;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html {
            overflow-x: hidden;
            max-width: 100vw;
        }
        body {
            background: var(--bg);
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
            height: 100vh;
            max-width: 100vw;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Top Header Navigation */
        header {
            background: var(--yellow);
            border-bottom: var(--border-thick);
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 0px #1a1a2e;
            overflow: hidden;
            width: 100%;
            min-width: 0;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
            overflow: hidden;
        }

        .header-left h2 {
            font-family: 'Rubik', sans-serif;
            font-size: 15px;
            font-weight: 900;
            text-transform: uppercase;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .header-left .site-badge {
            font-family: 'JetBrains Mono', monospace;
            background: var(--cyan);
            border: var(--border-thin);
            color: var(--text-dark);
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 2px 2px 0px var(--text-dark);
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .header-right {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
            align-items: center;
        }

        .btn {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            border: var(--border-thin);
            transition: all 0.15s;
            font-family: 'Rubik', sans-serif;
            text-transform: uppercase;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-logout {
            background: white;
            color: #E53935;
            box-shadow: 2px 2px 0px var(--text-dark);
        }

        .btn-logout:hover {
            background: #FFF3F0;
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0px var(--text-dark);
        }

        .btn-view {
            background: var(--blue);
            color: white;
            box-shadow: 2px 2px 0px var(--text-dark);
        }

        .btn-view:hover {
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0px var(--text-dark);
        }

        /* Outer Container */
        .workspace {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Sidebar File Tree */
        .sidebar {
            width: 290px;
            background: var(--sidebar-bg);
            border-right: var(--border-thick);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 16px;
            border-bottom: var(--border-thick);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #F0FFF0;
        }

        .sidebar-header h3 {
            font-family: 'Rubik', sans-serif;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dark);
        }

        .btn-icon {
            background: var(--lime);
            border: var(--border-thin);
            color: var(--text-dark);
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            box-shadow: 2px 2px 0px var(--text-dark);
            transition: all 0.15s;
        }

        .btn-icon:hover {
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0px var(--text-dark);
        }

        .tree-root {
            padding: 12px 16px;
            list-style: none;
        }

        .tree-node {
            margin-bottom: 4px;
        }

        .tree-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s;
            border: 2px solid transparent;
            font-weight: 500;
        }

        .tree-label:hover {
            background: #FFF8E1;
            border-color: var(--yellow);
        }

        .tree-label.active {
            background: var(--cyan);
            border: var(--border-thin);
            color: var(--text-dark);
            font-weight: 700;
            box-shadow: 2px 2px 0px var(--text-dark);
        }

        .node-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .node-size {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .node-delete-btn {
            opacity: 0;
            color: #E53935;
            transition: opacity 0.15s;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 900;
        }

        .tree-label:hover .node-delete-btn {
            opacity: 1;
        }

        /* Editor Panel */
        .editor-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #FAFAFA;
        }

        .editor-top-bar {
            background: var(--editor-header);
            border-bottom: var(--border-thick);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .current-filename {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
            background: white;
            padding: 4px 12px;
            border-radius: 6px;
            border: var(--border-thin);
            box-shadow: 2px 2px 0px var(--text-dark);
        }

        .save-btn {
            background: var(--success);
            color: white;
            padding: 8px 18px;
            border-radius: 8px;
            border: var(--border-thin);
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
            box-shadow: 2px 2px 0px var(--text-dark);
            font-family: 'Rubik', sans-serif;
            text-transform: uppercase;
        }

        .save-btn:hover {
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0px var(--text-dark);
        }

        #codeEditorSection {
            flex: 1;
            width: 100%;
            height: 100%;
        }

        .placeholder-screen {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            text-align: center;
            background: var(--bg);
        }

        .placeholder-icon {
            font-size: 56px;
            margin-bottom: 16px;
        }

        .placeholder-screen h2 {
            font-family: 'Rubik', sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .placeholder-screen p {
            font-size: 14px;
            color: #888;
            max-width: 320px;
            line-height: 1.5;
        }

        /* Binary / Image Preview Panel */
        .binary-preview-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
            overflow: auto;
            background: var(--bg);
            text-align: center;
        }

        .binary-preview-content img {
            max-width: 100%;
            max-height: 65vh;
            object-fit: contain;
            border: var(--border-thin);
            border-radius: 10px;
            box-shadow: var(--shadow);
        }

        .binary-preview-content .binary-info {
            margin-top: 20px;
        }

        .binary-preview-content .binary-info h3 {
            font-family: 'Rubik', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .binary-preview-content .binary-info p {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .btn-download {
            display: inline-block;
            margin-top: 18px;
            padding: 10px 28px;
            background: var(--blue);
            color: white;
            border-radius: 8px;
            border: var(--border-thin);
            font-family: 'Rubik', sans-serif;
            font-weight: 800;
            font-size: 13px;
            text-decoration: none;
            text-transform: uppercase;
            box-shadow: var(--shadow);
            transition: all 0.15s;
        }

        .btn-download:hover {
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0px var(--text-dark);
        }

        .save-btn-download {
            background: var(--blue);
        }
    </style>
</head>
<body>
    <header>
        <div class="header-left">
            <h2>📂</h2>
            <span class="site-badge">/<?= htmlspecialchars($site_id) ?></span>
        </div>
        <div class="header-right">
            <a href="/<?= htmlspecialchars($site_id) ?>/" target="_blank" class="btn btn-view">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Lihat Website
            </a>
            <a href="file_manager.php?action=logout" class="btn btn-logout">🔒 Keluar</a>
        </div>
    </header>

    <div class="workspace">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h3>✦ Daftar Berkas</h3>
                <button class="btn-icon" onclick="promptCreateFile()" title="Buat Berkas Baru">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                </button>
            </div>

            <ul class="tree-root">
                <?php if (empty($file_tree)): ?>
                    <li style="font-size: 13px; color: var(--text-muted); text-align: center; padding-top: 24px; font-weight: 600;">Belum ada berkas tersedia.</li>
                <?php else: ?>
                    <?php
                    function render_tree($tree) {
                        foreach ($tree as $node) {
                            $escaped_path = htmlspecialchars($node['path']);
                            $escaped_name = htmlspecialchars($node['name']);
                            
                            if ($node['is_dir']) {
                                ?>
                                <li class="tree-node">
                                    <div class="tree-label" style="font-weight: 700;">
                                        <div class="node-info">
                                            <span>📂</span>
                                            <span><?= $escaped_name ?></span>
                                        </div>
                                        <button class="node-delete-btn" onclick="deleteFile('<?= $escaped_path ?>', event)">✕</button>
                                    </div>
                                    <ul style="list-style: none; padding-left: 14px; margin-top: 2px;">
                                        <?php render_tree($node['children']); ?>
                                    </ul>
                                </li>
                                <?php
                            } else {
                                $size_kb = round($node['size'] / 1024, 1);
                                ?>
                                <li class="tree-node">
                                    <div class="tree-label file-node" data-path="<?= $escaped_path ?>" onclick="loadFile('<?= $escaped_path ?>')">
                                        <div class="node-info">
                                            <span><?= get_file_icon($node['name']) ?></span>
                                            <span><?= $escaped_name ?></span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="node-size"><?= $size_kb ?> KB</span>
                                            <button class="node-delete-btn" onclick="deleteFile('<?= $escaped_path ?>', event)">✕</button>
                                        </div>
                                    </div>
                                </li>
                                <?php
                            }
                        }
                    }
                    render_tree($file_tree);
                    ?>
                <?php endif; ?>
            </ul>
        </aside>

        <!-- Editor Section -->
        <main class="editor-container" id="editorScreen" style="display: none;">
            <div class="editor-top-bar">
                <span class="current-filename" id="editorFilename">index.html</span>
                <button class="save-btn" onclick="saveCurrentFile()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    💾 Simpan
                </button>
            </div>
            <div id="codeEditorSection"></div>
        </main>

        <!-- Binary / Image Preview Panel -->
        <main class="editor-container" id="binaryPreviewPanel" style="display: none;">
            <div class="editor-top-bar">
                <span class="current-filename" id="binaryFilename">file</span>
                <a id="downloadBinaryBtn" href="#" target="_blank" class="save-btn save-btn-download" style="text-decoration: none;">⬇️ Unduh Berkas</a>
            </div>
            <div class="binary-preview-content" id="binaryPreviewContent"></div>
        </main>

        <!-- Placeholder No Selected File Screen -->
        <div class="placeholder-screen" id="noSelectedPlaceholder">
            <div class="placeholder-icon">✦</div>
            <h2>Pilih Berkas untuk Diedit</h2>
            <p>Klik salah satu berkas di panel sebelah kiri untuk membuka editor kode secara langsung.</p>
        </div>
    </div>

    <script>
        let editor = null;
        let selectedFilePath = "";

        // Inisialisasi Ace Editor
        window.addEventListener('DOMContentLoaded', () => {
            editor = ace.edit("codeEditorSection");
            editor.setTheme("ace/theme/one_dark");
            editor.setOptions({
                fontSize: "13px",
                showPrintMargin: false,
                fontFamily: "JetBrains Mono, monospace",
                enableBasicAutocompletion: true,
                useWorker: false
            });
        });

        // Unduh berkas & Masukkan ke dalam Code Editor
        async function loadFile(filePath) {
            selectedFilePath = filePath;
            document.querySelectorAll('.file-node').forEach(node => node.classList.remove('active'));
            
            const node = document.querySelector(`.file-node[data-path="${filePath}"]`);
            if (node) node.classList.add('active');

            const showPanel = (panel) => {
                document.getElementById('noSelectedPlaceholder').style.display = 'none';
                document.getElementById('editorScreen').style.display = panel === 'editor' ? 'flex' : 'none';
                document.getElementById('binaryPreviewPanel').style.display = panel === 'binary' ? 'flex' : 'none';
            };

            try {
                const res = await fetch(`file_manager.php?api=get_file&file=${encodeURIComponent(filePath)}`);
                const data = await res.json();

                if (data.error) {
                    alert('Terjadi kesalahan: ' + data.error);
                    return;
                }

                document.getElementById('editorFilename').innerText = filePath;

                if (data.file_type === 'image') {
                    // Tampilkan pratinjau gambar
                    showPanel('binary');
                    document.getElementById('binaryFilename').innerText = filePath;
                    document.getElementById('downloadBinaryBtn').href = `file_manager.php?api=download_file&file=${encodeURIComponent(filePath)}`;
                    document.getElementById('binaryPreviewContent').innerHTML = `
                        <img src="data:${data.mime};base64,${data.content}" alt="${data.filename}">
                        <div class="binary-info">
                            <p>🖼️ ${data.filename}</p>
                            <a href="file_manager.php?api=download_file&file=${encodeURIComponent(filePath)}" target="_blank" class="btn-download">⬇️ Unduh Gambar</a>
                        </div>`;

                } else if (data.file_type === 'binary') {
                    // Tampilkan info berkas biner + tombol unduh
                    showPanel('binary');
                    document.getElementById('binaryFilename').innerText = filePath;
                    document.getElementById('downloadBinaryBtn').href = `file_manager.php?api=download_file&file=${encodeURIComponent(filePath)}`;
                    const sizeMB = data.size >= 1024 * 1024
                        ? (data.size / (1024 * 1024)).toFixed(2) + ' MB'
                        : (data.size / 1024).toFixed(1) + ' KB';
                    const icons = { pdf:'📕', mp4:'🎬', webm:'🎬', mov:'🎬', mkv:'🎬', avi:'🎬',
                                    mp3:'🎵', wav:'🎵', ogg:'🎵', flac:'🎵',
                                    zip:'📦', rar:'📦', gz:'📦', tar:'📦', '7z':'📦',
                                    db:'🗄️', sqlite:'🗄️', ttf:'🔤', woff:'🔤', woff2:'🔤' };
                    const icon = icons[data.ext] || '📦';
                    document.getElementById('binaryPreviewContent').innerHTML = `
                        <div style="font-size: 64px; margin-bottom: 16px;">${icon}</div>
                        <div class="binary-info">
                            <h3>${data.filename}</h3>
                            <p>Ukuran: <strong>${sizeMB}</strong> &nbsp;|&nbsp; Tipe: <strong>.${data.ext || 'unknown'}</strong></p>
                            <p>Berkas biner tidak dapat dibuka di editor teks.</p>
                            <a href="file_manager.php?api=download_file&file=${encodeURIComponent(filePath)}" target="_blank" class="btn-download">⬇️ Unduh Berkas</a>
                        </div>`;

                } else {
                    // Berkas teks — tampilkan di editor
                    showPanel('editor');
                    const extension = filePath.split('.').pop().toLowerCase();
                    let mode = "ace/mode/text";
                    if (['html','htm'].includes(extension))          mode = "ace/mode/html";
                    else if (['css','scss','sass','less'].includes(extension)) mode = "ace/mode/css";
                    else if (['js','mjs','cjs','jsx'].includes(extension))     mode = "ace/mode/javascript";
                    else if (['ts','tsx'].includes(extension))       mode = "ace/mode/typescript";
                    else if (extension === 'json')                   mode = "ace/mode/json";
                    else if (extension === 'php')                    mode = "ace/mode/php";
                    else if (['xml','svg'].includes(extension))      mode = "ace/mode/xml";
                    else if (extension === 'md')                     mode = "ace/mode/markdown";
                    else if (extension === 'py')                     mode = "ace/mode/python";
                    else if (extension === 'sql')                    mode = "ace/mode/sql";
                    else if (['sh','bat'].includes(extension))       mode = "ace/mode/sh";
                    else if (['yaml','yml'].includes(extension))     mode = "ace/mode/yaml";

                    editor.session.setMode(mode);
                    editor.setValue(data.content, -1);
                }
            } catch (err) {
                alert('Tidak dapat memuat berkas. Silakan coba lagi.');
            }
        }

        // Simpan perubahan ke server
        async function saveCurrentFile() {
            if (!selectedFilePath) return;
            const content = editor.getValue();

            const saveBtn = document.querySelector('.save-btn');
            saveBtn.disabled = true;
            saveBtn.innerText = '⏳ Menyimpan...';

            try {
                const res = await fetch('file_manager.php?api=save_file', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        file: selectedFilePath,
                        content: content
                    })
                });
                const data = await res.json();
                if (data.error) {
                    alert('Gagal menyimpan: ' + data.error);
                } else {
                    toastSuccess();
                }
            } catch (err) {
                alert('Koneksi ke server terputus. Silakan periksa jaringan Anda.');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> 💾 Simpan';
            }
        }

        function toastSuccess() {
            const originalText = document.getElementById('editorFilename').innerText;
            document.getElementById('editorFilename').innerText = "✓ Berkas Berhasil Disimpan!";
            document.getElementById('editorFilename').style.background = '#E8F5E9';
            document.getElementById('editorFilename').style.borderColor = '#00C853';
            setTimeout(() => {
                document.getElementById('editorFilename').innerText = originalText;
                document.getElementById('editorFilename').style.background = 'white';
                document.getElementById('editorFilename').style.borderColor = '#1a1a2e';
            }, 2000);
        }

        // Penanganan manipulasi buat berkas baru
        async function promptCreateFile() {
            const name = prompt('Masukkan nama berkas baru (contoh: style.css atau sub/tentang.html):');
            if (!name) return;

            try {
                const res = await fetch('file_manager.php?api=create_file', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename: name })
                });
                const data = await res.json();
                if (data.error) {
                    alert('Gagal membuat berkas: ' + data.error);
                } else {
                    window.location.reload();
                }
            } catch (err) {
                alert('Tidak dapat terhubung ke server.');
            }
        }

        // Penghapusan Berkas / Direktori Folder
        async function deleteFile(filePath, event) {
            event.stopPropagation();
            if (!confirm(`Apakah Anda yakin ingin menghapus "${filePath}" secara permanen? Tindakan ini tidak dapat dibatalkan.`)) {
                return;
            }

            try {
                const res = await fetch('file_manager.php?api=delete_file', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ file: filePath })
                });
                const data = await res.json();
                if (data.error) {
                    alert('Gagal menghapus: ' + data.error);
                } else {
                    window.location.reload();
                }
            } catch (err) {
                alert('Tidak dapat terhubung ke server.');
            }
        }
    </script>
</body>
</html>
