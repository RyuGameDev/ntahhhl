<?php
// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Pastikan folder logs tersedia
$logs_dir = __DIR__ . '/logs';
if (!is_dir($logs_dir)) {
    @mkdir($logs_dir, 0755, true);
}

$sessions_file = __DIR__ . '/sessions.json';
$active_session_file = __DIR__ . '/active_session.json';
$pid_path = __DIR__ . '/pid.txt';

// Kirim error API secara konsisten tanpa menyimpan respons sensitif di cache.
function json_api_error($status_code, $message, $error_code = null) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $response = [
        'status' => 'error',
        'message' => $message
    ];

    if ($error_code !== null) {
        $response['error_code'] = $error_code;
    }

    echo json_encode($response);
    exit;
}

// Ambil Authorization header pada Apache maupun reverse proxy/FastCGI.
function get_authorization_header() {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $server_key) {
        if (!empty($_SERVER[$server_key])) {
            return trim((string) $_SERVER[$server_key]);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $header_name => $header_value) {
                if (strcasecmp($header_name, 'Authorization') === 0) {
                    return trim((string) $header_value);
                }
            }
        }
    }

    return '';
}

// Lindungi aksi administratif menggunakan ADMIN_TOKEN dari environment Railway.
function require_admin_auth() {
    $expected_token = getenv('ADMIN_TOKEN');

    if (!is_string($expected_token) || strlen($expected_token) < 32) {
        error_log('ADMIN_TOKEN belum dikonfigurasi atau panjangnya kurang dari 32 karakter.');
        json_api_error(503, 'Autentikasi server belum dikonfigurasi.', 'auth_not_configured');
    }

    $authorization = get_authorization_header();
    $provided_token = '';

    if (preg_match('/^Bearer[ \t]+([^\s]+)$/i', $authorization, $matches) === 1) {
        $provided_token = $matches[1];
    }

    if ($provided_token === '' || !hash_equals($expected_token, $provided_token)) {
        header('WWW-Authenticate: Bearer realm="booster-admin"');
        json_api_error(401, 'Token autentikasi tidak valid.', 'unauthorized');
    }
}

// Aksi yang mengubah state wajib menggunakan POST.
function require_post_request() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_api_error(405, 'Method tidak diizinkan. Gunakan POST.', 'method_not_allowed');
    }
}

// Utility helper untuk membaca JSON input (cURL / Fetch API compatibility)
function get_request_data() {
    $data = $_REQUEST;
    $raw_input = file_get_contents('php://input');
    if (!empty($raw_input)) {
        $json = json_decode($raw_input, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $data;
}

// Utility helper untuk membaca daftar semua sesi dari sessions.json
function load_sessions() {
    global $sessions_file;
    if (file_exists($sessions_file)) {
        $content = file_get_contents($sessions_file);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

// Utility helper untuk menyimpan daftar sesi ke sessions.json
function save_sessions($sessions) {
    global $sessions_file;
    file_put_contents($sessions_file, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Utility helper untuk menyimpan 1 sesi
function update_session_record($session_data) {
    $sessions = load_sessions();
    $sessions[$session_data['session_id']] = $session_data;
    save_sessions($sessions);
}

// Utility helper untuk mengecek apakah PID masih berjalan di OS / Docker Container
function is_process_running($pid) {
    $pid = intval($pid);
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    if (file_exists("/proc/$pid")) {
        return true;
    }
    $out = [];
    exec("ps -p " . $pid, $out);
    return count($out) > 1;
}

// Utility helper untuk sinkronisasi state aktif
function get_current_active_session() {
    global $active_session_file, $pid_path;
    if (!file_exists($active_session_file)) {
        return null;
    }
    $active = json_decode(file_get_contents($active_session_file), true);
    if (!is_array($active) || empty($active['session_id'])) {
        @unlink($active_session_file);
        @unlink($pid_path);
        return null;
    }

    $pid = intval($active['pid'] ?? 0);
    if (!is_process_running($pid)) {
        // Proses sudah selesai di background
        $sessions = load_sessions();
        if (isset($sessions[$active['session_id']])) {
            if ($sessions[$active['session_id']]['status'] === 'running') {
                $sessions[$active['session_id']]['status'] = 'completed';
                $sessions[$active['session_id']]['ended_at'] = date('Y-m-d H:i:s');
                save_sessions($sessions);
            }
        }
        @unlink($active_session_file);
        @unlink($pid_path);
        return null;
    }

    // Hitung durasi berjalan
    if (!empty($active['started_at'])) {
        $active['duration_seconds'] = time() - strtotime($active['started_at']);
    }

    return $active;
}

// ----------------------------------------------------
// ROUTING HANDLER ACTION (JSON API & cURL Compatible)
// ----------------------------------------------------

$action = $_GET['action'] ?? null;

// Aksi mutasi hanya boleh dipanggil melalui POST dengan Bearer token yang valid.
if (in_array($action, ['run', 'stop', 'clear_history'], true)) {
    require_post_request();
    require_admin_auth();
}

// 1. ACTION: RUN (Mulai booster baru)
if ($action === 'run') {
    header('Content-Type: application/json');
    $req = get_request_data();
    
    $target = trim($req['target'] ?? '');
    $quantity = intval($req['quantity'] ?? 0);
    $threads = intval($req['threads'] ?? 300);
    if ($threads < 1) $threads = 1;
    if ($threads > 10000) $threads = 10000;
    $proxy = trim($req['proxy'] ?? '');

    if (empty($target) || $quantity <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Harap masukkan Link Video dan Jumlah target dengan benar.'
        ]);
        exit;
    }

    // Cek apakah ada sesi lain yang sedang aktif berjalan
    $current_active = get_current_active_session();
    if ($current_active !== null) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Masih ada proses booster aktif dengan Session ID: ' . $current_active['session_id'],
            'data' => $current_active
        ]);
        exit;
    }

    // Generate Session ID unik
    $session_id = 'sess_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 4);
    $log_rel_path = "logs/{$session_id}.log";
    $log_abs_path = __DIR__ . '/' . $log_rel_path;
    $binary_path = __DIR__ . '/bot_views';

    // Inisialisasi file log sesi dan output.log (untuk backward compatibility)
    $init_header = "[+] Session ID: {$session_id}\n[*] Mempersiapkan TikTok views booster...\n[*] Target: {$target}\n[*] Quantity: {$quantity}\n[*] Threads: {$threads}\n" . (!empty($proxy) ? "[*] Proxy: {$proxy}\n" : "") . "-------------------------------------------------\n";
    file_put_contents($log_abs_path, $init_header);
    file_put_contents(__DIR__ . '/output.log', $init_header);

    // Build command & escape arguments
    $target_esc = escapeshellarg($target);
    $quantity_esc = escapeshellarg($quantity);
    $threads_esc = escapeshellarg($threads);

    if (!empty($proxy)) {
        $proxy_esc = escapeshellarg($proxy);
        $cmd = "$binary_path $target_esc $quantity_esc $threads_esc $proxy_esc";
    } else {
        $cmd = "$binary_path $target_esc $quantity_esc $threads_esc";
    }

    $log_abs_esc = escapeshellarg($log_abs_path);
    $pid_path_esc = escapeshellarg($pid_path);

    // Exec di background dan alirkan output ke log file sesi & sync ke output.log
    exec("$cmd > $log_abs_esc 2>&1 & echo \$! > $pid_path_esc");

    // Ambil PID
    $pid = 0;
    if (file_exists($pid_path)) {
        $pid = intval(trim(file_get_contents($pid_path)));
    }

    $session_data = [
        'session_id' => $session_id,
        'pid' => $pid,
        'target' => $target,
        'quantity' => $quantity,
        'threads' => $threads,
        'proxy' => $proxy,
        'status' => 'running',
        'started_at' => date('Y-m-d H:i:s'),
        'ended_at' => null,
        'log_file' => $log_rel_path
    ];

    // Simpan ke database JSON & active session
    update_session_record($session_data);
    file_put_contents($active_session_file, json_encode($session_data, JSON_PRETTY_PRINT));

    echo json_encode([
        'status' => 'success',
        'message' => "Booster berhasil dijalankan [ID: {$session_id}] dengan " . number_format($threads) . " threads!",
        'data' => $session_data
    ]);
    exit;
}

// 2. ACTION: STOP (Hentikan booster)
if ($action === 'stop') {
    header('Content-Type: application/json');
    $req = get_request_data();
    $target_session_id = trim($req['session_id'] ?? '');

    $current_active = get_current_active_session();
    $sessions = load_sessions();

    $stopped_data = null;

    if (file_exists($pid_path)) {
        $pid = intval(trim(file_get_contents($pid_path)));
        if ($pid > 0) {
            exec("kill -9 $pid");
        }
        @unlink($pid_path);
    }

    if ($current_active !== null) {
        $sid = $current_active['session_id'];
        $log_abs = __DIR__ . '/logs/' . $sid . '.log';
        $stop_msg = "\n[!] Booster dihentikan secara paksa oleh pengguna pada " . date('Y-m-d H:i:s') . ".\n";
        
        if (file_exists($log_abs)) {
            file_put_contents($log_abs, $stop_msg, FILE_APPEND);
        }
        file_put_contents(__DIR__ . '/output.log', $stop_msg, FILE_APPEND);

        if (isset($sessions[$sid])) {
            $sessions[$sid]['status'] = 'stopped';
            $sessions[$sid]['ended_at'] = date('Y-m-d H:i:s');
            save_sessions($sessions);
            $stopped_data = $sessions[$sid];
        }

        @unlink($active_session_file);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Proses booster berhasil dihentikan!',
        'data' => $stopped_data
    ]);
    exit;
}

// 3. ACTION: GET_STATUS (Status real-time untuk reconnect/cURL)
if ($action === 'get_status') {
    header('Content-Type: application/json');
    $active = get_current_active_session();
    
    echo json_encode([
        'status' => 'success',
        'running' => ($active !== null),
        'active_session' => $active
    ]);
    exit;
}

// 4. ACTION: GET_LOG (Baca log sesi tertentu atau sesi aktif)
if ($action === 'get_log') {
    $req = get_request_data();
    $sid = trim($req['session_id'] ?? '');
    $format = trim($req['format'] ?? '');
    $is_full = intval($req['full'] ?? 0); // 1 = full log (untuk viewer/download), 0 = tail 500 baris untuk live view
    
    $log_content = "";
    
    if (!empty($sid)) {
        $log_file = __DIR__ . '/logs/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sid) . '.log';
        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
        } else {
            $log_content = "[!] Log untuk Session ID {$sid} tidak ditemukan.";
        }
    } else {
        $active = get_current_active_session();
        if ($active !== null && !empty($active['session_id'])) {
            $log_file = __DIR__ . '/logs/' . $active['session_id'] . '.log';
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
            }
        }
        if (empty($log_content) && file_exists(__DIR__ . '/output.log')) {
            $log_content = file_get_contents(__DIR__ . '/output.log');
        }
    }

    if (empty($log_content)) {
        $log_content = "[*] Belum ada aktivitas log.";
    }

    $is_json_request = ($format === 'json') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($is_json_request) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'session_id' => $sid,
            'log' => $log_content
        ]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $log_content;
    }
    exit;
}

// 5. ACTION: GET_HISTORY (Daftar semua riwayat sesi)
if ($action === 'get_history') {
    header('Content-Type: application/json');
    
    // Refresh active session status dulu
    get_current_active_session();
    
    $sessions = load_sessions();
    // Urutkan dari yang terbaru (descending)
    usort($sessions, function($a, $b) {
        return strtotime($b['started_at'] ?? '0') - strtotime($a['started_at'] ?? '0');
    });

    echo json_encode([
        'status' => 'success',
        'total' => count($sessions),
        'sessions' => $sessions
    ]);
    exit;
}

// 6. ACTION: CLEAR_HISTORY (Hapus 1 atau semua riwayat log)
if ($action === 'clear_history') {
    header('Content-Type: application/json');
    $req = get_request_data();
    $sid = trim($req['session_id'] ?? '');

    $sessions = load_sessions();

    if ($sid === 'all') {
        foreach ($sessions as $s) {
            if ($s['status'] !== 'running' && !empty($s['log_file'])) {
                @unlink(__DIR__ . '/' . $s['log_file']);
            }
        }
        // Simpan hanya sesi running jika ada
        $sessions = array_filter($sessions, function($s) {
            return $s['status'] === 'running';
        });
        save_sessions($sessions);

        echo json_encode([
            'status' => 'success',
            'message' => 'Semua riwayat sesi berhasil dibersihkan.'
        ]);
        exit;
    } elseif (!empty($sid)) {
        if (isset($sessions[$sid])) {
            if ($sessions[$sid]['status'] === 'running') {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Sesi sedang berjalan dan tidak dapat dihapus.'
                ]);
                exit;
            }
            if (!empty($sessions[$sid]['log_file'])) {
                @unlink(__DIR__ . '/' . $sessions[$sid]['log_file']);
            }
            unset($sessions[$sid]);
            save_sessions($sessions);
            echo json_encode([
                'status' => 'success',
                'message' => "Riwayat sesi {$sid} berhasil dihapus."
            ]);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Session ID tidak valid.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Social Booster Ultra - TikTok & Instagram Views Engine</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:ital,wght@0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --bg: #08080c;
            --surface: #111116;
            --surface-raised: #17171e;
            --surface-soft: rgba(255, 255, 255, 0.035);
            --border: rgba(255, 255, 255, 0.09);
            --border-strong: rgba(255, 255, 255, 0.15);
            --cyan: #25f4ee;
            --cyan-rgb: 37, 244, 238;
            --pink: #fe2c55;
            --pink-rgb: 254, 44, 85;
            --green: #38d996;
            --amber: #fbbf5a;
            --danger: #ff5d72;
            --text: #f7f7f8;
            --muted: #92929f;
            --muted-light: #bebec8;
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 13px;
            --shadow: 0 30px 80px rgba(0, 0, 0, 0.38);
        }

        /* Modal Custom Styles */
        .modal-content.dark-modal {
            background: #111116;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.7);
            color: #f7f7f8;
            border-radius: 16px;
        }

        .modal-content.dark-modal .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 16px 20px;
        }

        .modal-content.dark-modal .modal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 20px;
        }

        .modal-content.dark-modal .table {
            color: #f7f7f8;
            margin-bottom: 0;
        }

        .modal-content.dark-modal .table th {
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            color: #92929f;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.02);
            padding: 12px 16px;
        }

        .modal-content.dark-modal .table td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            vertical-align: middle;
            font-size: 13px;
            padding: 12px 16px;
        }

        .history-terminal {
            background: #08080c;
            border-radius: 12px;
            padding: 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px;
            height: 480px;
            max-height: 480px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
            color: #d1d1e0;
            margin: 0;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        html {
            color-scheme: dark;
        }

        body {
            position: relative;
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 12% -8%, rgba(var(--cyan-rgb), 0.14), transparent 30rem),
                radial-gradient(circle at 92% 8%, rgba(var(--pink-rgb), 0.13), transparent 28rem),
                linear-gradient(180deg, #0b0b10 0%, var(--bg) 44%, #07070a 100%);
            color: var(--text);
            font-family: 'Plus Jakarta Sans', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body::before {
            position: fixed;
            z-index: -1;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.018) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.018) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: linear-gradient(to bottom, black, transparent 76%);
            content: '';
            pointer-events: none;
        }

        button,
        input {
            font: inherit;
        }

        button:focus-visible,
        input:focus-visible {
            outline: 2px solid var(--cyan);
            outline-offset: 3px;
        }

        .text-muted {
            color: var(--muted) !important;
        }

        .app-shell {
            width: min(1440px, calc(100% - 48px));
            margin: 0 auto;
            padding: 28px 0 38px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 14px;
        }

        .brand-mark {
            position: relative;
            display: grid;
            flex: 0 0 auto;
            width: 48px;
            height: 48px;
            place-items: center;
            border: 1px solid var(--border-strong);
            border-radius: 15px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.09), rgba(255, 255, 255, 0.025));
            box-shadow: inset 0 1px rgba(255, 255, 255, 0.1), 0 12px 32px rgba(0, 0, 0, 0.32);
            font-size: 22px;
        }

        .brand-mark::before,
        .brand-mark::after {
            position: absolute;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            content: '';
            filter: blur(12px);
            opacity: 0.55;
        }

        .brand-mark::before {
            top: 5px;
            right: 4px;
            background: var(--pink);
        }

        .brand-mark::after {
            bottom: 5px;
            left: 4px;
            background: var(--cyan);
        }

        .brand-mark i {
            position: relative;
            z-index: 1;
        }

        .brand-copy {
            min-width: 0;
        }

        .brand-title-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
        }

        .brand-title {
            margin: 0;
            color: #fff;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.025em;
        }

        .brand-subtitle {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .version-chip {
            padding: 4px 8px;
            border: 1px solid rgba(var(--pink-rgb), 0.3);
            border-radius: 999px;
            background: rgba(var(--pink-rgb), 0.08);
            color: #ff8aa1;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.06em;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 44px;
            padding: 7px 13px;
            border: 1px solid rgba(56, 217, 150, 0.22);
            border-radius: 13px;
            background: rgba(56, 217, 150, 0.06);
        }

        .status-caption {
            display: block;
            margin-bottom: 1px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.1em;
            line-height: 1;
            text-transform: uppercase;
        }

        #statusText {
            display: block;
            color: #a7f3d0;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.25;
        }

        .status-badge[data-state="running"] {
            border-color: rgba(var(--cyan-rgb), 0.26);
            background: rgba(var(--cyan-rgb), 0.07);
        }

        .status-badge[data-state="running"] #statusText {
            color: var(--cyan);
        }

        .status-badge[data-state="stopped"] {
            border-color: rgba(var(--pink-rgb), 0.28);
            background: rgba(var(--pink-rgb), 0.07);
        }

        .status-badge[data-state="stopped"] #statusText {
            color: #ff8aa1;
        }

        .pulse-dot {
            position: relative;
            flex: 0 0 auto;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 5px rgba(56, 217, 150, 0.09), 0 0 14px rgba(56, 217, 150, 0.7);
        }

        .pulse-dot::after {
            position: absolute;
            inset: -4px;
            border: 1px solid currentColor;
            border-radius: inherit;
            content: '';
            animation: pulse-ring 1.8s ease-out infinite;
        }

        .pulse-dot.running {
            color: var(--cyan);
            background: var(--cyan);
            box-shadow: 0 0 0 5px rgba(var(--cyan-rgb), 0.08), 0 0 14px rgba(var(--cyan-rgb), 0.7);
        }

        .pulse-dot.stopped {
            color: var(--pink);
            background: var(--pink);
            box-shadow: 0 0 0 5px rgba(var(--pink-rgb), 0.08), 0 0 14px rgba(var(--pink-rgb), 0.6);
        }

        .pulse-dot.stopped::after {
            animation: none;
        }

        @keyframes pulse-ring {
            0% { opacity: 0.7; transform: scale(0.6); }
            80%, 100% { opacity: 0; transform: scale(1.45); }
        }

        .icon-button {
            display: inline-grid;
            width: 44px;
            height: 44px;
            padding: 0;
            place-items: center;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.035);
            color: var(--muted-light);
            transition: 180ms ease;
        }

        .icon-button:hover {
            border-color: var(--border-strong);
            background: rgba(255, 255, 255, 0.075);
            color: #fff;
            transform: translateY(-1px);
        }

        .icon-button:disabled {
            cursor: not-allowed;
            opacity: 0.4;
            transform: none;
        }

        .intro {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 32px;
            padding: 40px 0 25px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            color: var(--cyan);
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            width: 20px;
            height: 1px;
            background: var(--cyan);
            content: '';
            box-shadow: 0 0 8px rgba(var(--cyan-rgb), 0.7);
        }

        .intro h1 {
            max-width: 720px;
            margin: 0;
            color: #fff;
            font-size: clamp(28px, 4vw, 48px);
            font-weight: 800;
            letter-spacing: -0.05em;
            line-height: 1.05;
        }

        .intro h1 span {
            background: linear-gradient(90deg, var(--cyan), #9dfbf8 45%, #ff8aa1 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .intro-description {
            max-width: 680px;
            margin: 13px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .intro-tags {
            display: flex;
            flex: 0 0 auto;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
            padding-bottom: 5px;
        }

        .intro-tag {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 11px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.025);
            color: var(--muted-light);
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
        }

        .intro-tag i {
            color: var(--cyan);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .stat-card {
            position: relative;
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 13px;
            padding: 16px;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.052), rgba(255, 255, 255, 0.018));
            box-shadow: inset 0 1px rgba(255, 255, 255, 0.045);
            transition: border-color 180ms ease, transform 180ms ease, background 180ms ease;
        }

        .stat-card:hover {
            border-color: var(--border-strong);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.072), rgba(255, 255, 255, 0.024));
            transform: translateY(-2px);
        }

        .stat-icon {
            display: grid;
            flex: 0 0 auto;
            width: 42px;
            height: 42px;
            place-items: center;
            border: 1px solid rgba(var(--cyan-rgb), 0.16);
            border-radius: 12px;
            background: rgba(var(--cyan-rgb), 0.075);
            color: var(--cyan);
        }

        .stat-icon.pink {
            border-color: rgba(var(--pink-rgb), 0.17);
            background: rgba(var(--pink-rgb), 0.075);
            color: #ff7892;
        }

        .stat-icon.green {
            border-color: rgba(56, 217, 150, 0.17);
            background: rgba(56, 217, 150, 0.075);
            color: var(--green);
        }

        .stat-icon.amber {
            border-color: rgba(251, 191, 90, 0.17);
            background: rgba(251, 191, 90, 0.075);
            color: var(--amber);
        }

        .stat-content {
            min-width: 0;
        }

        .stat-label {
            overflow: hidden;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-overflow: ellipsis;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .stat-value {
            overflow: hidden;
            margin-top: 4px;
            color: #fff !important;
            font-family: 'JetBrains Mono', monospace;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -0.04em;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .workspace-grid {
            display: grid;
            grid-template-columns: minmax(360px, 0.82fr) minmax(500px, 1.18fr);
            align-items: stretch;
            gap: 18px;
        }

        .workspace-grid > *,
        .config-card,
        .terminal-wrapper {
            width: 100%;
            min-width: 0;
        }

        .glass-card {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            background: linear-gradient(155deg, rgba(24, 24, 31, 0.96), rgba(14, 14, 19, 0.96));
            box-shadow: var(--shadow), inset 0 1px rgba(255, 255, 255, 0.05);
        }

        .config-card {
            height: 100%;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.015);
        }

        .panel-heading {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .panel-heading-icon {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border: 1px solid rgba(var(--cyan-rgb), 0.15);
            border-radius: 10px;
            background: rgba(var(--cyan-rgb), 0.07);
            color: var(--cyan);
            font-size: 13px;
        }

        .panel-title {
            margin: 0;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
        }

        .panel-subtitle {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 10px;
        }

        .engine-chip {
            flex: 0 0 auto;
            padding: 6px 9px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.03);
            color: var(--muted-light);
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
            font-weight: 600;
        }

        .config-body {
            padding: 21px 22px 22px;
        }

        .step-block {
            position: relative;
            padding-left: 37px;
        }

        .step-block + .step-block {
            margin-top: 21px;
            padding-top: 1px;
        }

        .step-block:not(:last-of-type)::after {
            position: absolute;
            top: 29px;
            bottom: -18px;
            left: 13px;
            width: 1px;
            background: linear-gradient(to bottom, var(--border-strong), transparent);
            content: '';
        }

        .step-index {
            position: absolute;
            top: 0;
            left: 0;
            display: grid;
            width: 27px;
            height: 27px;
            place-items: center;
            border: 1px solid var(--border-strong);
            border-radius: 9px;
            background: var(--surface-raised);
            color: var(--cyan);
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
            font-weight: 700;
            box-shadow: 0 5px 16px rgba(0, 0, 0, 0.28);
        }

        .field-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 27px;
            margin-bottom: 9px;
        }

        .form-label {
            margin: 0;
            color: #dedee4;
            font-size: 11px;
            font-weight: 700;
        }

        .field-meta {
            color: var(--muted);
            font-size: 9px;
            font-weight: 600;
        }

        .input-group {
            flex-wrap: nowrap;
        }

        .input-group-text,
        .form-control,
        .paste-button {
            min-height: 45px;
            border-color: var(--border);
            background: rgba(5, 5, 8, 0.46);
        }

        .input-group-text {
            min-width: 43px;
            justify-content: center;
            border-right: 0;
            border-radius: var(--radius-md) 0 0 var(--radius-md);
            color: var(--cyan);
            font-size: 12px;
        }

        .form-control {
            min-width: 0;
            padding: 11px 13px;
            border-radius: var(--radius-md);
            color: var(--text);
            font-size: 12px;
            box-shadow: none;
            transition: border-color 180ms ease, background 180ms ease, box-shadow 180ms ease;
        }

        .form-control.has-addon {
            border-left: 0;
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
        }

        .input-group:focus-within .input-group-text,
        .input-group:focus-within .paste-button,
        .form-control:focus {
            border-color: rgba(var(--cyan-rgb), 0.48);
        }

        .input-group:focus-within {
            border-radius: var(--radius-md);
            box-shadow: 0 0 0 3px rgba(var(--cyan-rgb), 0.075);
        }

        .form-control:focus {
            background: rgba(7, 7, 11, 0.72);
            color: #fff;
            box-shadow: none;
        }

        .form-control::placeholder {
            color: #5f5f6b;
        }

        .paste-button {
            min-width: 43px;
            padding: 0 13px;
            border: 1px solid var(--border);
            border-left: 0;
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            color: var(--muted-light);
            transition: 180ms ease;
        }

        .paste-button:hover {
            background: rgba(var(--cyan-rgb), 0.07);
            color: var(--cyan);
        }

        .input-group .form-control.has-end-action {
            border-radius: 0;
        }

        .form-text {
            margin-top: 7px;
            color: var(--muted) !important;
            font-size: 10px;
            line-height: 1.55;
        }

        .preset-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .preset-btn {
            padding: 6px 9px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.025);
            color: var(--muted-light);
            font-size: 9px;
            font-weight: 700;
            cursor: pointer;
            transition: 160ms ease;
        }

        .preset-btn:hover {
            border-color: rgba(var(--cyan-rgb), 0.34);
            background: rgba(var(--cyan-rgb), 0.06);
            color: #fff;
            transform: translateY(-1px);
        }

        .preset-btn.active {
            border-color: rgba(var(--cyan-rgb), 0.46);
            background: rgba(var(--cyan-rgb), 0.1);
            color: var(--cyan);
            box-shadow: inset 0 0 0 1px rgba(var(--cyan-rgb), 0.05);
        }

        .preset-btn.extreme.active,
        .preset-btn.extreme:hover {
            border-color: rgba(var(--pink-rgb), 0.44);
            background: rgba(var(--pink-rgb), 0.09);
            color: #ff8aa1;
        }

        .thread-value {
            padding: 4px 7px;
            border: 1px solid rgba(var(--cyan-rgb), 0.18);
            border-radius: 7px;
            background: rgba(var(--cyan-rgb), 0.06);
            color: var(--cyan);
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
            font-weight: 700;
        }

        .range-wrap {
            position: relative;
            margin: 10px 0 0;
        }

        .form-range {
            height: 16px;
            margin: 0;
        }

        .form-range::-webkit-slider-runnable-track {
            height: 4px;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--cyan), rgba(var(--cyan-rgb), 0.11));
        }

        .form-range::-webkit-slider-thumb {
            width: 15px;
            height: 15px;
            margin-top: -5.5px;
            border: 3px solid #111116;
            background: var(--cyan);
            box-shadow: 0 0 0 2px rgba(var(--cyan-rgb), 0.25), 0 0 16px rgba(var(--cyan-rgb), 0.4);
            cursor: pointer;
        }

        .form-range::-moz-range-track {
            height: 4px;
            border-radius: 999px;
            background: rgba(var(--cyan-rgb), 0.12);
        }

        .form-range::-moz-range-progress {
            height: 4px;
            border-radius: 999px;
            background: var(--cyan);
        }

        .form-range::-moz-range-thumb {
            width: 11px;
            height: 11px;
            border: 3px solid #111116;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 0 2px rgba(var(--cyan-rgb), 0.25);
            cursor: pointer;
        }

        .thread-warning {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            margin-top: 8px;
            color: var(--amber);
            font-size: 10px;
            line-height: 1.5;
        }

        .text-success.thread-warning { color: var(--green) !important; }
        .text-warning.thread-warning { color: var(--amber) !important; }
        .text-danger.thread-warning { color: var(--danger) !important; }

        .action-area {
            margin-top: 23px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }

        .action-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 104px;
            gap: 9px;
        }

        .btn-launch,
        .btn-stop {
            min-height: 48px;
            border-radius: 13px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: -0.01em;
            transition: transform 180ms ease, box-shadow 180ms ease, filter 180ms ease;
        }

        .btn-launch {
            position: relative;
            overflow: hidden;
            border: 0;
            background: linear-gradient(100deg, var(--cyan) 0%, #7af9f5 52%, var(--pink) 160%);
            color: #071111;
            box-shadow: 0 12px 30px rgba(var(--cyan-rgb), 0.16);
        }

        .btn-launch::after {
            position: absolute;
            top: -100%;
            left: -30%;
            width: 24%;
            height: 300%;
            background: rgba(255, 255, 255, 0.48);
            content: '';
            transform: rotate(22deg);
            transition: left 450ms ease;
        }

        .btn-launch:hover:not(:disabled) {
            color: #071111;
            filter: brightness(1.05);
            transform: translateY(-2px);
            box-shadow: 0 15px 36px rgba(var(--cyan-rgb), 0.23);
        }

        .btn-launch:hover:not(:disabled)::after {
            left: 120%;
        }

        .btn-stop {
            border: 1px solid rgba(var(--pink-rgb), 0.28);
            background: rgba(var(--pink-rgb), 0.09);
            color: #ff8aa1;
        }

        .btn-stop:hover:not(:disabled) {
            border-color: rgba(var(--pink-rgb), 0.48);
            background: rgba(var(--pink-rgb), 0.16);
            color: #fff;
            transform: translateY(-2px);
        }

        .btn:disabled {
            cursor: not-allowed;
            filter: grayscale(0.35);
            opacity: 0.42;
            transform: none !important;
        }

        .action-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin: 10px 0 0;
            color: #686875;
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-align: center;
        }

        .action-note i {
            color: var(--green);
        }

        .terminal-wrapper {
            display: flex;
            height: 100%;
            min-height: 706px;
            overflow: hidden;
            flex-direction: column;
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            background: #08090d;
            box-shadow: var(--shadow), inset 0 1px rgba(255, 255, 255, 0.045);
        }

        .terminal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 64px;
            gap: 14px;
            padding: 13px 18px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #17171d, #121217);
        }

        .terminal-identity {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 12px;
        }

        .mac-dots {
            display: flex;
            flex: 0 0 auto;
            gap: 6px;
        }

        .mac-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            box-shadow: inset 0 -1px 1px rgba(0, 0, 0, 0.24);
        }

        .mac-dot.close { background: #ff5f57; }
        .mac-dot.min { background: #febc2e; }
        .mac-dot.max { background: #28c840; }

        .terminal-title {
            overflow: hidden;
            color: #c4c4cc;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .terminal-title i {
            margin-right: 6px;
            color: var(--cyan);
        }

        .live-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 7px;
            border: 1px solid rgba(56, 217, 150, 0.16);
            border-radius: 999px;
            background: rgba(56, 217, 150, 0.05);
            color: #85e8bd;
            font-family: 'JetBrains Mono', monospace;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.06em;
        }

        .live-chip::before {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--green);
            content: '';
            box-shadow: 0 0 8px rgba(56, 217, 150, 0.75);
        }

        .terminal-actions {
            display: flex;
            align-items: center;
            flex: 0 0 auto;
            gap: 4px;
        }

        .auto-scroll-control {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-right: 4px;
        }

        .auto-scroll-control label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 600;
        }

        .form-check-input {
            width: 27px !important;
            height: 15px;
            margin: 0 !important;
            border-color: var(--border-strong);
            background-color: rgba(255, 255, 255, 0.08);
            cursor: pointer;
        }

        .form-check-input:checked {
            border-color: rgba(var(--cyan-rgb), 0.52);
            background-color: var(--cyan);
        }

        .terminal-action {
            display: grid;
            width: 31px;
            height: 31px;
            padding: 0;
            place-items: center;
            border: 1px solid transparent;
            border-radius: 9px;
            background: transparent;
            color: var(--muted);
            font-size: 11px;
            transition: 160ms ease;
        }

        .terminal-action:hover {
            border-color: var(--border);
            background: rgba(255, 255, 255, 0.04);
            color: var(--cyan);
        }

        .progress-bar-container {
            padding: 15px 19px 16px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.018);
        }

        .progress-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 10px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
        }

        .progress-label {
            color: var(--muted);
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        #progressPercentText {
            margin-left: 5px;
            color: #fff;
        }

        #progressDetailText {
            color: var(--cyan);
        }

        .custom-progress {
            height: 6px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.065);
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.45);
        }

        .custom-progress-inner {
            position: relative;
            width: 0;
            height: 100%;
            overflow: hidden;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--cyan), #7cf8f4 65%, var(--pink));
            box-shadow: 0 0 15px rgba(var(--cyan-rgb), 0.32);
            transition: width 350ms ease;
        }

        .custom-progress-inner::after {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent);
            content: '';
            animation: progress-shimmer 1.8s linear infinite;
            transform: translateX(-100%);
        }

        @keyframes progress-shimmer {
            to { transform: translateX(100%); }
        }

        .terminal-body {
            flex: 1 1 auto;
            min-height: 480px;
            max-height: 520px;
            padding: 22px;
            overflow-y: auto;
            background:
                linear-gradient(rgba(var(--cyan-rgb), 0.012) 1px, transparent 1px),
                #090a0f;
            background-size: 100% 27px;
            color: #7dd9d5;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            line-height: 1.72;
            tab-size: 4;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .terminal-body::selection,
        .terminal-body *::selection {
            background: rgba(var(--cyan-rgb), 0.22);
            color: #fff;
        }

        .log-info { color: #79c8e8; }
        .log-success { color: #65dfa8; font-weight: 600; }
        .log-warn { color: var(--amber); }
        .log-danger { color: #ff7184; font-weight: 600; }
        .log-highlight { color: #f192c8; }

        .terminal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 18px;
            border-top: 1px solid var(--border);
            background: #0f1015;
            color: #646470;
            font-family: 'JetBrains Mono', monospace;
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .terminal-footer span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .terminal-footer i {
            color: var(--green);
        }

        .page-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 4px 0;
            color: #585864;
            font-size: 9px;
            font-weight: 600;
        }

        .page-footer span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        ::-webkit-scrollbar {
            width: 7px;
            height: 7px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.012);
        }

        ::-webkit-scrollbar-thumb {
            border: 2px solid transparent;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            background-clip: padding-box;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.28);
            background-clip: padding-box;
        }

        @media (max-width: 1100px) {
            .intro {
                align-items: flex-start;
                flex-direction: column;
                gap: 18px;
            }

            .intro-tags {
                justify-content: flex-start;
            }

            .workspace-grid {
                grid-template-columns: minmax(330px, 0.9fr) minmax(440px, 1.1fr);
            }
        }

        @media (max-width: 900px) {
            .metrics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .workspace-grid {
                grid-template-columns: 1fr;
            }

            .terminal-wrapper {
                min-height: 560px;
            }
        }

        @media (max-width: 575px) {
            .app-shell {
                width: calc(100% - 24px);
                max-width: 100%;
                padding-top: 16px;
            }

            .topbar {
                align-items: center;
                gap: 10px;
                padding-bottom: 17px;
            }

            .brand {
                flex: 1 1 auto;
                min-width: 0;
            }

            .brand-copy {
                overflow: hidden;
            }

            .brand-title-row {
                flex-wrap: nowrap;
            }

            .brand-mark {
                width: 43px;
                height: 43px;
                border-radius: 13px;
            }

            .brand-title {
                overflow: hidden;
                font-size: 14px;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .brand-subtitle,
            .version-chip,
            .status-caption,
            .intro-tags,
            .auto-scroll-control label,
            .live-chip,
            .engine-chip,
            .field-meta {
                display: none;
            }

            .status-badge {
                display: grid;
                width: 40px;
                min-height: 40px;
                padding: 0;
                place-items: center;
            }

            .status-badge > span:last-child {
                display: none;
            }

            .header-actions {
                gap: 7px;
            }

            .icon-button {
                width: 40px;
                height: 40px;
            }

            .intro {
                padding: 29px 1px 22px;
            }

            .intro h1 {
                max-width: 100%;
                font-size: 29px;
                overflow-wrap: anywhere;
            }

            .intro h1 span {
                display: block;
            }

            .intro-description {
                font-size: 11px;
            }

            .metrics-grid {
                width: 100%;
                gap: 8px;
            }

            .stat-card {
                align-items: flex-start;
                flex-direction: column;
                gap: 9px;
                padding: 13px;
            }

            .stat-icon {
                width: 34px;
                height: 34px;
                border-radius: 10px;
                font-size: 12px;
            }

            .stat-value {
                font-size: 13px;
            }

            .panel-header,
            .config-body {
                padding-right: 16px;
                padding-left: 16px;
            }

            .step-block {
                padding-left: 35px;
            }

            .action-grid {
                grid-template-columns: minmax(0, 1fr) 88px;
            }

            .terminal-header {
                min-height: 58px;
                padding-right: 12px;
                padding-left: 14px;
            }

            .terminal-identity {
                flex: 1 1 auto;
            }

            .terminal-title {
                max-width: 118px;
            }

            .terminal-actions {
                gap: 1px;
            }

            .terminal-action {
                width: 36px;
                height: 36px;
            }

            .terminal-wrapper {
                min-height: 480px;
            }

            .terminal-body {
                padding: 17px;
                font-size: 10px;
            }

            .page-footer {
                align-items: flex-start;
                flex-direction: column;
                gap: 6px;
            }
        }

        @media (max-width: 360px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .action-grid {
                grid-template-columns: 1fr;
            }

            .btn-stop {
                min-height: 44px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
<main class="app-shell">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true">
                <i class="fa-solid fa-bolt text-cyan"></i>
            </div>
            <div class="brand-copy">
                <div class="brand-title-row">
                    <p class="brand-title">Social Booster Ultra</p>
                    <span class="version-chip">MULTI v4.0</span>
                </div>
                <p class="brand-subtitle">TikTok & Instagram Multi-Engine</p>
            </div>
        </div>

        <div class="header-actions">
            <div class="status-badge" id="systemStatusBadge" data-state="ready" role="status" aria-live="polite">
                <span class="pulse-dot" id="statusDot" aria-hidden="true"></span>
                <span>
                    <small class="status-caption">Engine status</small>
                    <strong id="statusText">System Ready</strong>
                </span>
            </div>
            <button type="button" class="icon-button" id="resetBtn" title="Reset formulir" aria-label="Reset formulir" onclick="resetForm()">
                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <section class="intro" aria-labelledby="pageTitle">
        <div>
            <span class="eyebrow"><i class="fa-brands fa-tiktok text-cyan me-1"></i> TikTok & <i class="fa-brands fa-instagram text-pink me-1"></i> Instagram Booster Workspace</span>
            <h1 id="pageTitle">Multi-Platform Views Engine. <span>TikTok & Instagram Reels.</span></h1>
            <p class="intro-description">Dukungan penuh untuk TikTok Video & Instagram Reels tanpa login. Pantau live log & statistik secara terpadu.</p>
        </div>
        <div class="intro-tags" aria-label="Fitur engine">
            <span class="intro-tag"><i class="fa-solid fa-wave-square" aria-hidden="true"></i> Live monitor</span>
            <span class="intro-tag"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Multi-thread</span>
            <span class="intro-tag"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Proxy ready</span>
        </div>
    </section>

    <section class="metrics-grid" aria-label="Ringkasan proses">
        <article class="stat-card">
            <span class="stat-icon"><i class="fa-solid fa-server" aria-hidden="true"></i></span>
            <div class="stat-content">
                <div class="stat-label">Status Engine</div>
                <div class="stat-value text-info" id="cardStatus">IDLE</div>
            </div>
        </article>
        <article class="stat-card">
            <span class="stat-icon amber"><i class="fa-solid fa-microchip" aria-hidden="true"></i></span>
            <div class="stat-content">
                <div class="stat-label">Goroutine</div>
                <div class="stat-value text-warning" id="cardThreads">500 Ready</div>
            </div>
        </article>
        <article class="stat-card">
            <span class="stat-icon green"><i class="fa-solid fa-bullseye" aria-hidden="true"></i></span>
            <div class="stat-content">
                <div class="stat-label">Target Views</div>
                <div class="stat-value text-success" id="cardTarget">0</div>
            </div>
        </article>
        <article class="stat-card">
            <span class="stat-icon pink"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
            <div class="stat-content">
                <div class="stat-label">Kecepatan</div>
                <div class="stat-value text-danger" id="cardSpeed">0 /sec</div>
            </div>
        </article>
    </section>

    <section class="workspace-grid" aria-label="Workspace booster">
        <article class="glass-card config-card">
            <header class="panel-header">
                <div class="panel-heading">
                    <span class="panel-heading-icon"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="panel-title">Konfigurasi Booster</h2>
                        <p class="panel-subtitle">Siapkan parameter sebelum engine dijalankan</p>
                    </div>
                </div>
                <span class="engine-chip">GO v1.21</span>
            </header>

            <form id="boosterForm" class="config-body">
                <section class="step-block">
                    <span class="step-index" aria-hidden="true">01</span>
                    <div class="field-heading">
                        <label for="target" class="form-label">Link TikTok Video / Instagram Reel</label>
                        <span class="field-meta">Wajib diisi</span>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-link"></i></span>
                        <input type="text" class="form-control has-addon has-end-action" id="target" name="target" placeholder="https://www.tiktok.com/... atau https://www.instagram.com/reel/..." aria-describedby="targetHelp" autocomplete="off" required>
                        <button type="button" class="paste-button" onclick="pasteClipboard()" title="Tempel dari clipboard" aria-label="Tempel link dari clipboard">
                            <i class="fa-regular fa-clipboard" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="form-text" id="targetHelp">Sistem otomatis mendeteksi platform TikTok atau Instagram Reel secara instant.</div>
                </section>

                <section class="step-block">
                    <span class="step-index" aria-hidden="true">02</span>
                    <div class="field-heading">
                        <label for="quantity" class="form-label">Jumlah views</label>
                        <span class="field-meta">Pilih preset atau isi manual</span>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-eye"></i></span>
                        <input type="number" class="form-control has-addon" id="quantity" name="quantity" min="1" inputmode="numeric" placeholder="Contoh: 10.000" required>
                    </div>
                    <div class="preset-list" aria-label="Preset jumlah views">
                        <button type="button" class="preset-btn quantity-preset" data-quantity="1000" aria-pressed="false" onclick="setQuantity(1000)">1K</button>
                        <button type="button" class="preset-btn quantity-preset" data-quantity="5000" aria-pressed="false" onclick="setQuantity(5000)">5K</button>
                        <button type="button" class="preset-btn quantity-preset" data-quantity="10000" aria-pressed="false" onclick="setQuantity(10000)">10K</button>
                        <button type="button" class="preset-btn quantity-preset" data-quantity="50000" aria-pressed="false" onclick="setQuantity(50000)">50K</button>
                        <button type="button" class="preset-btn quantity-preset" data-quantity="100000" aria-pressed="false" onclick="setQuantity(100000)">100K</button>
                    </div>
                </section>

                <section class="step-block">
                    <span class="step-index" aria-hidden="true">03</span>
                    <div class="field-heading">
                        <label for="threads" class="form-label">Goroutine threads</label>
                        <output class="thread-value" id="threadValueDisplay" for="threads threadRange">500 Threads</output>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-gauge-high"></i></span>
                        <input type="number" class="form-control has-addon font-monospace fw-bold" id="threads" name="threads" value="500" min="1" max="5000" inputmode="numeric" aria-describedby="threadWarning" oninput="syncThreadsFromInput(this.value)">
                    </div>
                    <div class="range-wrap">
                        <input type="range" class="form-range" id="threadRange" min="10" max="5000" step="10" value="500" aria-label="Pilih jumlah goroutine threads" oninput="syncThreadsFromSlider(this.value)">
                    </div>
                    <div class="preset-list" aria-label="Preset jumlah threads">
                        <button type="button" class="preset-btn thread-preset" data-threads="300" aria-pressed="false" onclick="setThreads(300)">300 Standard</button>
                        <button type="button" class="preset-btn thread-preset active" data-threads="500" aria-pressed="true" onclick="setThreads(500)">500 Balanced</button>
                        <button type="button" class="preset-btn thread-preset" data-threads="1000" aria-pressed="false" onclick="setThreads(1000)">1K High</button>
                        <button type="button" class="preset-btn thread-preset" data-threads="3000" aria-pressed="false" onclick="setThreads(3000)">3K Turbo</button>
                        <button type="button" class="preset-btn thread-preset extreme" data-threads="5000" aria-pressed="false" onclick="setThreads(5000)">5K Extreme</button>
                    </div>
                    <div class="thread-warning text-success" id="threadWarning">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                        <span>Standard Stable Mode (500 Threads).</span>
                    </div>
                </section>

                <section class="step-block">
                    <span class="step-index" aria-hidden="true">04</span>
                    <div class="field-heading">
                        <label for="proxy" class="form-label">Proxy HTTP / HTTPS</label>
                        <span class="field-meta">Opsional</span>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
                        <input type="text" class="form-control has-addon" id="proxy" name="proxy" placeholder="ip:port atau ip:port:user:password" aria-describedby="proxyHelp" autocomplete="off">
                    </div>
                    <div class="form-text" id="proxyHelp">Kosongkan untuk menggunakan jaringan outbound langsung dari VPS.</div>
                </section>

                <div class="action-area">
                    <div class="action-grid">
                        <button type="submit" id="submitBtn" class="btn btn-launch">
                            <i class="fa-solid fa-bolt me-2" aria-hidden="true"></i>Mulai Booster
                        </button>
                        <button type="button" id="stopBtn" class="btn btn-stop" disabled>
                            <i class="fa-solid fa-square me-1" aria-hidden="true"></i>Stop
                        </button>
                    </div>
                    <p class="action-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> Parameter dikirim langsung ke engine lokal</p>
                </div>
            </form>
        </article>

        <article class="terminal-wrapper" aria-labelledby="terminalTitle">
            <header class="terminal-header">
                <div class="terminal-identity">
                    <div class="mac-dots" aria-hidden="true">
                        <span class="mac-dot close"></span>
                        <span class="mac-dot min"></span>
                        <span class="mac-dot max"></span>
                    </div>
                    <span class="terminal-title" id="terminalTitle">
                        <i class="fa-solid fa-terminal" aria-hidden="true"></i>engine / live_output.log
                    </span>
                    <span class="live-chip">LIVE</span>
                </div>

                <div class="terminal-actions">
                    <button type="button" class="btn btn-sm btn-outline-info text-nowrap me-2" onclick="openHistoryModal()" style="border-radius: 8px; font-size: 12px; padding: 4px 10px;">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i>Riwayat Sesi
                    </button>
                    <div class="auto-scroll-control form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoScrollCheck" checked>
                        <label for="autoScrollCheck">Auto-scroll</label>
                    </div>
                    <button type="button" class="terminal-action" onclick="copyTerminalLogs()" title="Salin log" aria-label="Salin isi log">
                        <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="terminal-action" onclick="clearTerminalLogs()" title="Bersihkan layar" aria-label="Bersihkan layar terminal">
                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="progress-bar-container" id="progressContainer" role="progressbar" aria-label="Progres views" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="progress-meta">
                    <span class="progress-label">Progress <strong id="progressPercentText">0%</strong></span>
                    <span id="progressDetailText">0 / 0 Views</span>
                </div>
                <div class="custom-progress" aria-hidden="true">
                    <div class="custom-progress-inner" id="progressBarInner"></div>
                </div>
            </div>

            <div class="terminal-body" id="terminalLog" role="log" aria-label="Log proses booster" tabindex="0">[*] Engine siap. Lengkapi konfigurasi untuk memulai proses...</div>

            <footer class="terminal-footer">
                <span><i class="fa-solid fa-circle" aria-hidden="true"></i> POLLING 400MS</span>
                <span>UTF-8 · GO ENGINE</span>
            </footer>
        </article>
    </section>

    <footer class="page-footer">
        <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Local engine control panel</span>
        <span>Multi-thread workspace · v3.5</span>
    </footer>
</main>

<!-- Modal Riwayat Sesi -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content dark-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="historyModalLabel">
                    <i class="fa-solid fa-clock-rotate-left text-info me-2"></i>Riwayat Sesi & Log
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearAllHistory()" title="Hapus Semua Riwayat">
                        <i class="fa-solid fa-trash-can me-1"></i>Bersihkan Semua
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="historyTable">
                        <thead>
                            <tr>
                                <th class="ps-3">Sesi ID & Waktu</th>
                                <th>Target & Threads</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-spinner fa-spin me-2"></i>Memuat riwayat...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Log Viewer -->
<div class="modal fade" id="logViewerModal" tabindex="-1" aria-labelledby="logViewerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content dark-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="logViewerModalLabel">
                    <i class="fa-solid fa-terminal text-info me-2"></i>Detail Log: <span id="viewerSessionId" class="text-cyan"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre class="history-terminal" id="viewerTerminalContent">[*] Memuat isi log...</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyViewerLogs()">
                    <i class="fa-regular fa-copy me-1"></i>Salin Log
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let pollInterval = null;
    let targetViewsGoal = 0;
    let engineRunning = false;
    let logRequestInFlight = false;
    let currentPollingSessionId = null;

    let historyModalInstance = null;
    let logViewerModalInstance = null;

    // Token hanya disimpan di memori halaman dan tidak pernah ditanam di source code.
    const adminApi = (() => {
        let token = '';
        let promptPromise = null;

        function cancelledAuthError() {
            const error = new Error('Autentikasi dibatalkan.');
            error.name = 'AuthCancelledError';
            return error;
        }

        async function getToken(forcePrompt = false) {
            if (!forcePrompt && token) return token;
            if (promptPromise) return promptPromise;

            if (typeof Swal === 'undefined') {
                throw new Error('Dialog autentikasi tidak tersedia. Muat ulang halaman lalu coba lagi.');
            }

            promptPromise = Swal.fire({
                title: 'Autentikasi Admin',
                text: 'Masukkan ADMIN_TOKEN untuk melanjutkan aksi ini.',
                icon: 'info',
                input: 'password',
                inputPlaceholder: 'ADMIN_TOKEN',
                inputAttributes: {
                    autocomplete: 'off',
                    autocapitalize: 'none',
                    spellcheck: 'false'
                },
                showCancelButton: true,
                confirmButtonText: 'Autentikasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#25f4ee',
                cancelButtonColor: '#2b2b36',
                background: '#17171e',
                color: '#f7f7f8',
                inputValidator: value => value && value.trim() ? undefined : 'Token wajib diisi.'
            }).then(result => {
                if (!result.isConfirmed) {
                    throw cancelledAuthError();
                }

                token = result.value.trim();
                return token;
            }).finally(() => {
                promptPromise = null;
            });

            return promptPromise;
        }

        async function request(action, body = {}, retried = false) {
            const activeToken = await getToken(retried);
            const isFormData = body instanceof FormData;
            const headers = new Headers({
                'Accept': 'application/json',
                'Authorization': `Bearer ${activeToken}`
            });

            if (!isFormData) {
                headers.set('Content-Type', 'application/json');
            }

            const response = await fetch(`index.php?action=${encodeURIComponent(action)}`, {
                method: 'POST',
                headers,
                body: isFormData ? body : JSON.stringify(body || {})
            });

            const data = await response.json().catch(() => ({
                status: 'error',
                message: `Respons server tidak valid (${response.status}).`
            }));

            if (response.status === 401 && !retried) {
                token = '';
                showToast('error', 'Token salah. Silakan masukkan ulang.');
                return request(action, body, true);
            }

            if (!response.ok) {
                throw new Error(data.message || `Request gagal (${response.status}).`);
            }

            return data;
        }

        return { request };
    })();

    document.addEventListener('DOMContentLoaded', function() {
        checkActiveStatusOnLoad();
    });

    function checkActiveStatusOnLoad() {
        fetch('index.php?action=get_status')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && data.running && data.active_session) {
                const sess = data.active_session;
                engineRunning = true;
                currentPollingSessionId = sess.session_id;

                if (sess.target) document.getElementById('target').value = sess.target;
                if (sess.quantity) {
                    document.getElementById('quantity').value = sess.quantity;
                    document.getElementById('cardTarget').innerText = Number(sess.quantity).toLocaleString('id-ID');
                    targetViewsGoal = sess.quantity;
                }
                if (sess.threads) {
                    document.getElementById('threads').value = sess.threads;
                    document.getElementById('threadRange').value = Math.max(10, sess.threads);
                    updateThreadDisplay(sess.threads);
                }
                if (sess.proxy) document.getElementById('proxy').value = sess.proxy;

                const btn = document.getElementById('submitBtn');
                const stopBtn = document.getElementById('stopBtn');
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2" aria-hidden="true"></i>Menjalankan...';
                btn.disabled = true;
                stopBtn.disabled = false;
                document.getElementById('resetBtn').disabled = true;

                setEngineStatus('BERJALAN', 'running');
                showToast('info', 'Terhubung kembali ke sesi [' + sess.session_id + ']');

                if (pollInterval) clearInterval(pollInterval);
                fetchLogs();
                pollInterval = setInterval(fetchLogs, 400);
            }
        })
        .catch(err => {
            console.error('Failed to check active status:', err);
        });
    }

    function showToast(icon, title) {
        if (typeof Swal === 'undefined') return;

        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2400,
            timerProgressBar: true,
            background: '#17171e',
            color: '#f7f7f8'
        });

        Toast.fire({ icon, title });
    }

    function escapeHtml(value) {
        const entities = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return String(value).replace(/[&<>"']/g, character => entities[character]);
    }

    function setActivePreset(selector, dataKey, value) {
        document.querySelectorAll(selector).forEach(button => {
            const isActive = Number(button.dataset[dataKey]) === Number(value);
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function normalizeThreads(value) {
        const parsed = parseInt(value, 10);
        return Math.min(5000, Math.max(1, Number.isFinite(parsed) ? parsed : 1));
    }

    function syncThreadsFromSlider(val) {
        const threads = normalizeThreads(val);
        document.getElementById('threads').value = threads;
        updateThreadDisplay(threads);
        setActivePreset('.thread-preset', 'threads', threads);
    }

    function syncThreadsFromInput(val) {
        const threads = normalizeThreads(val);
        const input = document.getElementById('threads');

        if (parseInt(val, 10) > 5000 || parseInt(val, 10) < 1) {
            input.value = threads;
        }

        document.getElementById('threadRange').value = Math.max(10, threads);
        updateThreadDisplay(threads);
        setActivePreset('.thread-preset', 'threads', threads);
    }

    function updateThreadDisplay(val) {
        const threads = normalizeThreads(val);
        const display = document.getElementById('threadValueDisplay');
        const cardThreads = document.getElementById('cardThreads');
        const formattedThreads = threads.toLocaleString('id-ID');

        display.innerText = `${formattedThreads} Threads`;
        cardThreads.innerText = `${formattedThreads} ${engineRunning ? 'Active' : 'Ready'}`;

        const warning = document.getElementById('threadWarning');
        if (threads > 3000) {
            warning.innerHTML = `<i class="fa-solid fa-bolt" aria-hidden="true"></i><span><strong>Turbo Extreme (${formattedThreads} Threads).</strong> Membutuhkan CPU dan bandwidth tinggi.</span>`;
            warning.className = 'thread-warning text-danger';
        } else if (threads > 1000) {
            warning.innerHTML = `<i class="fa-solid fa-gauge-high" aria-hidden="true"></i><span>High Performance Mode (${formattedThreads} Threads).</span>`;
            warning.className = 'thread-warning text-warning';
        } else {
            warning.innerHTML = `<i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Standard Stable Mode (${formattedThreads} Threads).</span>`;
            warning.className = 'thread-warning text-success';
        }
    }

    function setQuantity(val) {
        document.getElementById('quantity').value = val;
        document.getElementById('cardTarget').innerText = val.toLocaleString('id-ID');
        setActivePreset('.quantity-preset', 'quantity', val);
    }

    function setThreads(val) {
        const threads = normalizeThreads(val);
        document.getElementById('threads').value = threads;
        document.getElementById('threadRange').value = Math.max(10, threads);
        updateThreadDisplay(threads);
        setActivePreset('.thread-preset', 'threads', threads);
    }

    async function pasteClipboard() {
        try {
            const text = await navigator.clipboard.readText();
            if (text) {
                document.getElementById('target').value = text;
                showToast('success', 'Link berhasil ditempel');
            }
        } catch (err) {
            console.error('Clipboard access denied:', err);
            showToast('warning', 'Izin clipboard tidak tersedia');
        }
    }

    function setProgress(percent, current = null, total = null) {
        const safePercent = Math.min(100, Math.max(0, Number(percent) || 0));
        const progressContainer = document.getElementById('progressContainer');

        document.getElementById('progressBarInner').style.width = `${safePercent}%`;
        document.getElementById('progressPercentText').innerText = `${Math.round(safePercent)}%`;
        progressContainer.setAttribute('aria-valuenow', String(Math.round(safePercent)));

        if (current !== null && total !== null) {
            const detail = `${Number(current).toLocaleString('id-ID')} / ${Number(total).toLocaleString('id-ID')} Views`;
            document.getElementById('progressDetailText').innerText = detail;
            progressContainer.setAttribute('aria-valuetext', `${Math.round(safePercent)} persen, ${detail}`);
        }
    }

    function resetForm() {
        if (engineRunning) {
            showToast('warning', 'Hentikan proses sebelum mereset formulir');
            return;
        }

        document.getElementById('boosterForm').reset();
        document.getElementById('threads').value = 500;
        document.getElementById('threadRange').value = 500;
        targetViewsGoal = 0;
        setActivePreset('.quantity-preset', 'quantity', -1);
        setActivePreset('.thread-preset', 'threads', 500);
        updateThreadDisplay(500);
        document.getElementById('cardTarget').innerText = '0';
        document.getElementById('cardSpeed').innerText = '0 /sec';
        setProgress(0, 0, 0);
        setEngineStatus('SIAP', 'ready');
        showToast('info', 'Formulir berhasil direset');
    }

    document.getElementById('quantity').addEventListener('input', function() {
        const quantity = Math.max(0, parseInt(this.value, 10) || 0);
        document.getElementById('cardTarget').innerText = quantity.toLocaleString('id-ID');
        setActivePreset('.quantity-preset', 'quantity', quantity);
    });

    document.getElementById('boosterForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const target = document.getElementById('target').value.trim();
        const quantity = parseInt(document.getElementById('quantity').value, 10) || 0;
        const threads = normalizeThreads(document.getElementById('threads').value);
        const proxy = document.getElementById('proxy').value.trim();

        if (!target || quantity <= 0) {
            showToast('warning', 'Lengkapi target dan jumlah views');
            return;
        }

        targetViewsGoal = quantity;
        engineRunning = true;
        document.getElementById('threads').value = threads;
        document.getElementById('cardTarget').innerText = quantity.toLocaleString('id-ID');

        const btn = document.getElementById('submitBtn');
        const stopBtn = document.getElementById('stopBtn');
        const terminal = document.getElementById('terminalLog');

        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2" aria-hidden="true"></i>Menjalankan...';
        btn.disabled = true;
        stopBtn.disabled = false;
        document.getElementById('resetBtn').disabled = true;

        setEngineStatus('BERJALAN', 'running');
        updateThreadDisplay(threads);
        setProgress(0, 0, quantity);

        terminal.textContent = `[+] Inisialisasi Booster Ultra Engine...\n[*] Target: ${target}\n[*] Quantity: ${quantity.toLocaleString('id-ID')} views\n[*] Threads: ${threads.toLocaleString('id-ID')} goroutines\n-------------------------------------------------\n`;

        const formData = new FormData();
        formData.append('target', target);
        formData.append('quantity', quantity);
        formData.append('threads', threads);
        formData.append('proxy', proxy);

        // Request terlindungi dengan Bearer token dari dialog admin.
        adminApi.request('run', formData)
        .then(data => {
            if (data.status === 'success') {
                if (data.data && data.data.session_id) {
                    currentPollingSessionId = data.data.session_id;
                }
                terminal.textContent += `[✓] ${data.message}\n[*] Memulai pemantauan log real-time...\n\n`;
                showToast('success', 'Booster berhasil dijalankan');

                if (pollInterval) clearInterval(pollInterval);
                fetchLogs();
                pollInterval = setInterval(fetchLogs, 400);
            } else {
                terminal.textContent += `[!] Error: ${data.message}\n`;
                Swal.fire({ icon: 'error', title: 'Gagal memulai', text: data.message, background: '#17171e', color: '#f7f7f8' });
                resetUI();
            }
        })
        .catch(err => {
            if (err.name === 'AuthCancelledError') {
                resetUI();
                return;
            }
            terminal.textContent += `[!] Error server: ${err.message}\n`;
            showToast('error', err.message);
            resetUI();
        });
    });

    document.getElementById('stopBtn').addEventListener('click', function() {
        const terminal = document.getElementById('terminalLog');
        const stopBtn = document.getElementById('stopBtn');

        stopBtn.disabled = true;
        stopBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1" aria-hidden="true"></i>Stop...';
        terminal.textContent += '\n[!] Mengirim sinyal penghentian ke server...\n';

        adminApi.request('stop', {})
        .then(data => {
            if (data.status === 'success') {
                terminal.textContent += `[✓] ${data.message}\n`;
                clearInterval(pollInterval);
                fetchLogs();
                resetUI('STOPPED');
            } else {
                terminal.textContent += `[!] Error: ${data.message}\n`;
                stopBtn.disabled = false;
                stopBtn.innerHTML = '<i class="fa-solid fa-square me-1" aria-hidden="true"></i>Stop';
                showToast('error', data.message);
            }
        })
        .catch(err => {
            stopBtn.disabled = false;
            stopBtn.innerHTML = '<i class="fa-solid fa-square me-1" aria-hidden="true"></i>Stop';

            if (err.name === 'AuthCancelledError') {
                terminal.textContent += '[*] Penghentian dibatalkan; proses tetap berjalan.\n';
                return;
            }

            terminal.textContent += `[!] Gagal menghentikan: ${err.message}\n`;
            showToast('error', err.message);
        });
    });

    function setEngineStatus(stateText, dotClass) {
        const badge = document.getElementById('systemStatusBadge');
        const dot = document.getElementById('statusDot');
        const text = document.getElementById('statusText');
        const cardStatus = document.getElementById('cardStatus');

        text.innerText = stateText;
        cardStatus.innerText = stateText;
        badge.dataset.state = dotClass;

        if (dotClass === 'running') {
            dot.className = 'pulse-dot running';
            cardStatus.className = 'stat-value text-success';
        } else if (dotClass === 'stopped') {
            dot.className = 'pulse-dot stopped';
            cardStatus.className = 'stat-value text-danger';
        } else {
            dot.className = 'pulse-dot';
            cardStatus.className = 'stat-value text-info';
        }
    }

    function colorizeLogs(text) {
        return escapeHtml(text)
            .replace(/(\[\+\][^\n]*)/g, '<span class="log-success">$1</span>')
            .replace(/(\[\*\][^\n]*)/g, '<span class="log-info">$1</span>')
            .replace(/(\[\!\][^\n]*)/g, '<span class="log-danger">$1</span>')
            .replace(/(\[✓\][^\n]*)/g, '<span class="log-success">$1</span>');
    }

    // Event listener untuk Auto-scroll switch
    document.getElementById('autoScrollCheck').addEventListener('change', function() {
        if (this.checked) {
            const terminal = document.getElementById('terminalLog');
            terminal.scrollTop = terminal.scrollHeight;
        }
    });

    function fetchLogs() {
        if (logRequestInFlight) return Promise.resolve();

        logRequestInFlight = true;
        const terminal = document.getElementById('terminalLog');
        const autoScroll = document.getElementById('autoScrollCheck').checked;

        // Simpan posisi scroll sebelum update log
        const prevScrollTop = terminal.scrollTop;

        const url = currentPollingSessionId 
            ? `index.php?action=get_log&session_id=${encodeURIComponent(currentPollingSessionId)}`
            : 'index.php?action=get_log';

        return fetch(url)
        .then(res => res.text())
        .then(text => {
            terminal.innerHTML = colorizeLogs(text);
            
            if (autoScroll) {
                // Auto-scroll ON: scroll otomatis ke bagian paling bawah
                terminal.scrollTop = terminal.scrollHeight;
            } else {
                // Auto-scroll OFF: pertahankan posisi scroll manual pengguna!
                terminal.scrollTop = prevScrollTop;
            }

            const viewsMatch = text.match(/views:\s*([\d,]+)\s*\/\s*([\d,]+|\?)/i);
            const rateMatch = text.match(/rate:\s*([\d,]+(?:\.\d+)?)\/s/i);

            if (rateMatch && rateMatch[1]) {
                document.getElementById('cardSpeed').innerText = rateMatch[1] + ' /sec';
            }

            if (viewsMatch && viewsMatch[1]) {
                const currentViews = parseInt(viewsMatch[1].replace(/,/g, '')) || 0;
                const totalGoal = targetViewsGoal || (parseInt(viewsMatch[2].replace(/,/g, '')) || 0);

                if (totalGoal > 0) {
                    const percent = Math.min(100, Math.round((currentViews / totalGoal) * 100));
                    setProgress(percent, currentViews, totalGoal);
                }
            }

            if (text.includes('[✓] Selesai!') || text.includes('[!] Gagal') || text.includes('dihentikan secara paksa') || text.includes('tidak valid!')) {
                clearInterval(pollInterval);
                currentPollingSessionId = null;
                if (text.includes('[✓] Selesai!')) {
                    setProgress(100, targetViewsGoal, targetViewsGoal);
                    resetUI('FINISHED');
                } else {
                    resetUI('STOPPED');
                }
            }
        })
        .catch(err => {
            console.error('Log polling error:', err);
        })
        .finally(() => {
            logRequestInFlight = false;
        });
    }

    function resetUI(finalState = 'IDLE') {
        const btn = document.getElementById('submitBtn');
        const stopBtn = document.getElementById('stopBtn');

        engineRunning = false;
        currentPollingSessionId = null;
        btn.innerHTML = '<i class="fa-solid fa-bolt me-2" aria-hidden="true"></i>Mulai Booster';
        btn.disabled = false;
        stopBtn.innerHTML = '<i class="fa-solid fa-square me-1" aria-hidden="true"></i>Stop';
        stopBtn.disabled = true;
        document.getElementById('resetBtn').disabled = false;
        updateThreadDisplay(document.getElementById('threads').value);

        if (finalState === 'FINISHED') {
            setEngineStatus('SELESAI ✓', 'ready');
        } else if (finalState === 'STOPPED') {
            setEngineStatus('DIHENTIKAN', 'stopped');
        } else {
            setEngineStatus('SIAP', 'ready');
        }
    }

    function clearTerminalLogs() {
        document.getElementById('terminalLog').textContent = '[*] Layar terminal dibersihkan.\n';
    }

    async function copyTerminalLogs() {
        const text = document.getElementById('terminalLog').innerText;

        try {
            await navigator.clipboard.writeText(text);
            showToast('success', 'Log berhasil disalin');
        } catch (err) {
            console.error('Copy log failed:', err);
            showToast('warning', 'Log tidak dapat disalin');
        }
    }

    function openHistoryModal() {
        const modalEl = document.getElementById('historyModal');
        if (!historyModalInstance) {
            historyModalInstance = new bootstrap.Modal(modalEl);
        }
        historyModalInstance.show();
        loadHistoryData();
    }

    function loadHistoryData() {
        const tbody = document.getElementById('historyTableBody');
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Memuat riwayat sesi...</td></tr>';

        fetch('index.php?action=get_history')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && Array.isArray(data.sessions) && data.sessions.length > 0) {
                tbody.innerHTML = data.sessions.map(s => {
                    let badgeClass = 'bg-secondary';
                    let statusLabel = s.status ? s.status.toUpperCase() : 'UNKNOWN';

                    if (s.status === 'running') {
                        badgeClass = 'bg-info text-dark';
                    } else if (s.status === 'completed') {
                        badgeClass = 'bg-success';
                    } else if (s.status === 'stopped') {
                        badgeClass = 'bg-danger';
                    }

                    const targetShort = s.target ? (s.target.length > 35 ? s.target.substring(0, 35) + '...' : s.target) : '-';

                    return `
                        <tr>
                            <td class="ps-3">
                                <div class="fw-semibold text-info">${escapeHtml(s.session_id)}</div>
                                <div class="text-muted small"><i class="fa-regular fa-clock me-1"></i>${escapeHtml(s.started_at || '-')}</div>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 260px;" title="${escapeHtml(s.target)}">${escapeHtml(targetShort)}</div>
                                <div class="small text-muted">${Number(s.threads || 0).toLocaleString('id-ID')} Threads · ${Number(s.quantity || 0).toLocaleString('id-ID')} Goal</div>
                            </td>
                            <td><span class="badge ${badgeClass}">${escapeHtml(statusLabel)}</span></td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-info me-1" onclick="viewSessionLog('${escapeHtml(s.session_id)}')">
                                    <i class="fa-solid fa-terminal me-1"></i>Log
                                </button>
                                ${s.status !== 'running' ? `
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteHistorySession('${escapeHtml(s.session_id)}')">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                ` : ''}
                            </td>
                        </tr>
                    `;
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Belum ada riwayat sesi tersimpan.</td></tr>';
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-danger">Gagal memuat riwayat: ${escapeHtml(err.message)}</td></tr>`;
        });
    }

    function viewSessionLog(sessionId) {
        document.getElementById('viewerSessionId').innerText = sessionId;
        const terminalEl = document.getElementById('viewerTerminalContent');
        terminalEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Membaca file log...';

        const modalEl = document.getElementById('logViewerModal');
        if (!logViewerModalInstance) {
            logViewerModalInstance = new bootstrap.Modal(modalEl);
        }
        logViewerModalInstance.show();

        fetch(`index.php?action=get_log&full=1&session_id=${encodeURIComponent(sessionId)}`)
        .then(res => res.text())
        .then(text => {
            terminalEl.innerHTML = colorizeLogs(text);
        })
        .catch(err => {
            terminalEl.innerText = '[!] Gagal membaca log: ' + err.message;
        });
    }

    async function copyViewerLogs() {
        const text = document.getElementById('viewerTerminalContent').innerText;
        try {
            await navigator.clipboard.writeText(text);
            showToast('success', 'Log berhasil disalin');
        } catch (err) {
            showToast('warning', 'Log tidak dapat disalin');
        }
    }

    function deleteHistorySession(sessionId) {
        Swal.fire({
            title: 'Hapus Riwayat?',
            text: `Yakin ingin menghapus sesi ${sessionId}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff5d72',
            cancelButtonColor: '#2b2b36',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal',
            background: '#17171e',
            color: '#f7f7f8'
        }).then((result) => {
            if (result.isConfirmed) {
                adminApi.request('clear_history', { session_id: sessionId })
                .then(data => {
                    if (data.status === 'success') {
                        showToast('success', data.message);
                        loadHistoryData();
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(err => {
                    if (err.name !== 'AuthCancelledError') {
                        showToast('error', err.message);
                    }
                });
            }
        });
    }

    function clearAllHistory() {
        Swal.fire({
            title: 'Bersihkan Semua Riwayat?',
            text: 'Semua log sesi terdahulu (yang tidak sedang berjalan) akan dihapus permanen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff5d72',
            cancelButtonColor: '#2b2b36',
            confirmButtonText: 'Ya, Hapus Semua',
            cancelButtonText: 'Batal',
            background: '#17171e',
            color: '#f7f7f8'
        }).then((result) => {
            if (result.isConfirmed) {
                adminApi.request('clear_history', { session_id: 'all' })
                .then(data => {
                    if (data.status === 'success') {
                        showToast('success', data.message);
                        loadHistoryData();
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(err => {
                    if (err.name !== 'AuthCancelledError') {
                        showToast('error', err.message);
                    }
                });
            }
        });
    }

    updateThreadDisplay(500);
    setActivePreset('.thread-preset', 'threads', 500);
    setEngineStatus('SIAP', 'ready');
</script>

</body>
</html>
