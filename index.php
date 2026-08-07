<?php
$output_msg = "";
$status = "";

// Mengirim response JSON jika dipicu lewat AJAX request
if (isset($_GET['action']) && $_GET['action'] === 'run') {
    header('Content-Type: application/json');
    $target = trim($_POST['target'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $threads = intval($_POST['threads'] ?? 300);
    if ($threads < 1) $threads = 1;
    if ($threads > 10000) $threads = 10000; // Dukungan penuh hingga 5000 - 10000 threads
    $proxy = trim($_POST['proxy'] ?? '');

    if (empty($target) || $quantity <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Harap masukkan Link Video dan Jumlah dengan benar.']);
        exit;
    }

    $binary_path = __DIR__ . '/bot_views'; 

    // Kosongkan/buat file log baru
    file_put_contents(__DIR__ . '/output.log', "[*] Mempersiapkan views booster...\n");

    // Escape argumen secara individual (aman dari shell injection)
    $target_esc = escapeshellarg($target);
    $quantity_esc = escapeshellarg($quantity);
    $threads_esc = escapeshellarg($threads);

    // Tambahkan parameter proxy jika diinput
    if (!empty($proxy)) {
        $proxy_esc = escapeshellarg($proxy);
        $cmd = "$binary_path $target_esc $quantity_esc $threads_esc $proxy_esc";
    } else {
        $cmd = "$binary_path $target_esc $quantity_esc $threads_esc";
    }
    
    // Jalankan secara asynchronous di background dan catat Process ID (PID)
    $pid_path = __DIR__ . '/pid.txt';
    exec("$cmd > output.log 2>&1 & echo \$! > " . escapeshellarg($pid_path));

    echo json_encode([
        'status' => 'success', 
        'message' => 'Booster berhasil dijalankan dengan ' . number_format($threads) . ' threads!'
    ]);
    exit;
}

// Endpoint untuk menghentikan proses booster
if (isset($_GET['action']) && $_GET['action'] === 'stop') {
    header('Content-Type: application/json');
    $pid_path = __DIR__ . '/pid.txt';
    if (file_exists($pid_path)) {
        $pid = intval(trim(file_get_contents($pid_path)));
        if ($pid > 0) {
            // Hentikan proses binary di Linux secara paksa (SIGKILL)
            exec("kill -9 $pid");
            
            // Tulis info penghentian ke file log
            file_put_contents(__DIR__ . '/output.log', "\n[!] Booster dihentikan secara paksa oleh pengguna.\n", FILE_APPEND);
        }
        @unlink($pid_path);
        echo json_encode(['status' => 'success', 'message' => 'Proses booster berhasil dihentikan!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Tidak ada proses booster aktif yang ditemukan.']);
    }
    exit;
}

// Endpoint untuk membaca log secara real-time
if (isset($_GET['action']) && $_GET['action'] === 'get_log') {
    header('Content-Type: text/plain');
    $log_path = __DIR__ . '/output.log';
    if (file_exists($log_path)) {
        echo file_get_contents($log_path);
    } else {
        echo "[*] Belum ada aktivitas log.";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Booster Ultra - Multi-Thread Engine</title>
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
            --bg-dark: #080c14;
            --card-bg: rgba(15, 23, 42, 0.75);
            --card-border: rgba(255, 255, 255, 0.08);
            --accent-cyan: #00f2fe;
            --accent-pink: #ff0050;
            --accent-purple: #8b5cf6;
            --accent-green: #10b981;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        body { 
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(0, 242, 254, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(255, 0, 80, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(139, 92, 246, 0.05) 0%, transparent 60%);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Glassmorphism Card */
        .glass-card { 
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border); 
            border-radius: 20px; 
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .glass-card:hover {
            border-color: rgba(255, 255, 255, 0.15);
        }

        /* Header Neon Logo */
        .brand-logo {
            background: linear-gradient(135deg, #00f2fe 0%, #4facfe 40%, #ff0050 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            letter-spacing: -0.5px;
            text-shadow: 0 0 30px rgba(0, 242, 254, 0.3);
        }

        .status-badge {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 30px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 10px #10b981;
            animation: pulse-ring 1.8s infinite;
        }

        .pulse-dot.running {
            background-color: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-cyan);
            animation: pulse-ring 1s infinite;
        }

        .pulse-dot.stopped {
            background-color: #ef4444;
            box-shadow: 0 0 10px #ef4444;
            animation: none;
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.5; }
            100% { transform: scale(0.95); opacity: 1; }
        }

        /* Metric Mini Cards */
        .stat-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 16px 20px;
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            background: rgba(30, 41, 59, 0.6);
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            margin-top: 4px;
        }

        /* Input Controls */
        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 8px;
        }

        .input-group-text {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-right: none;
            color: var(--accent-cyan);
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .form-control {
            background-color: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.25s ease;
        }

        .form-control.has-addon {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .form-control:focus {
            background-color: rgba(15, 23, 42, 0.9);
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px rgba(0, 242, 254, 0.25);
            color: #fff;
        }

        /* Preset Pills */
        .preset-btn {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .preset-btn:hover {
            background: rgba(0, 242, 254, 0.15);
            border-color: var(--accent-cyan);
            color: #fff;
        }
        .preset-btn.active {
            background: linear-gradient(135deg, rgba(0, 242, 254, 0.2), rgba(139, 92, 246, 0.2));
            border-color: var(--accent-cyan);
            color: var(--accent-cyan);
        }

        /* Range Slider */
        .form-range::-webkit-slider-thumb {
            background: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-cyan);
            cursor: pointer;
        }

        /* Action Buttons */
        .btn-launch {
            background: linear-gradient(135deg, #00f2fe 0%, #4facfe 50%, #8b5cf6 100%);
            background-size: 200% auto;
            border: none;
            color: #000;
            font-weight: 700;
            font-size: 15px;
            border-radius: 14px;
            padding: 14px 20px;
            letter-spacing: 0.3px;
            transition: all 0.4s ease;
            box-shadow: 0 8px 25px rgba(0, 242, 254, 0.3);
        }

        .btn-launch:hover:not(:disabled) {
            background-position: right center;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(139, 92, 246, 0.5);
        }

        .btn-stop {
            background: linear-gradient(135deg, #ef4444 0%, #991b1b 100%);
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            border-radius: 14px;
            padding: 14px 20px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.25);
        }

        .btn-stop:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(239, 68, 68, 0.45);
        }

        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Terminal Window */
        .terminal-wrapper {
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6);
            background-color: #030712;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 480px;
        }

        .terminal-header {
            background-color: #0f172a;
            padding: 12px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .mac-dots {
            display: flex;
            gap: 8px;
        }
        .mac-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        .mac-dot.close { background-color: #ff5f56; }
        .mac-dot.min { background-color: #ffbd2e; }
        .mac-dot.max { background-color: #27c93f; }

        .terminal-title {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }

        .terminal-actions button {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .terminal-actions button:hover {
            color: var(--accent-cyan);
            background: rgba(255, 255, 255, 0.05);
        }

        /* Progress Overlay */
        .progress-bar-container {
            background: rgba(15, 23, 42, 0.9);
            padding: 14px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .custom-progress {
            height: 10px;
            background-color: rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            overflow: hidden;
        }

        .custom-progress-inner {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #00f2fe 0%, #8b5cf6 50%, #ff0050 100%);
            border-radius: 20px;
            transition: width 0.4s ease;
            box-shadow: 0 0 12px rgba(0, 242, 254, 0.6);
        }

        .terminal-body {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            line-height: 1.6;
            padding: 20px;
            flex-grow: 1;
            overflow-y: auto;
            color: #38bdf8;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .log-info { color: #38bdf8; }
        .log-success { color: #34d399; font-weight: 600; }
        .log-warn { color: #fbbf24; }
        .log-danger { color: #f87171; font-weight: 600; }
        .log-highlight { color: #e879f9; }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.2);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
    </style>
</head>
<body>

<div class="container py-4 py-md-5">

    <!-- Header Bar -->
    <div class="d-flex flex-column flex-md-row align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div class="d-flex align-items-center gap-3 mb-3 mb-md-0">
            <div class="p-3 glass-card d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; border-radius: 16px;">
                <i class="fa-brands fa-tiktok text-info fs-3"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h2 class="brand-logo mb-0">TikTok Views Booster</h2>
                    <span class="badge bg-purple text-wrap" style="background: linear-gradient(135deg, #8b5cf6, #ec4899); font-size: 10px;">ULTRA v3.5</span>
                </div>
                <p class="text-muted small mb-0">High-Performance Asynchronous Multi-Threading Views Injector</p>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <div class="status-badge" id="systemStatusBadge">
                <span class="pulse-dot" id="statusDot"></span>
                <span id="statusText">System Ready</span>
            </div>
            <button class="btn btn-outline-light btn-sm rounded-circle p-2" style="width: 38px; height: 38px;" title="Reset Form" onclick="resetForm()">
                <i class="fa-solid fa-rotate-right"></i>
            </button>
        </div>
    </div>

    <!-- Quick Stats Grid -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label"><i class="fa-solid fa-server me-1 text-info"></i> Engine Status</div>
                <div class="stat-value text-info" id="cardStatus">IDLE</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label"><i class="fa-solid fa-microchip me-1 text-purple"></i> Max Threads</div>
                <div class="stat-value text-warning" id="cardThreads">5,000 Max</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label"><i class="fa-solid fa-bullseye me-1 text-success"></i> Views Target</div>
                <div class="stat-value text-success" id="cardTarget">0</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label"><i class="fa-solid fa-bolt me-1 text-danger"></i> Injection Speed</div>
                <div class="stat-value text-danger" id="cardSpeed">0 /sec</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Configuration Form (Left Column) -->
        <div class="col-lg-5">
            <div class="glass-card p-4 p-md-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="fw-bold mb-0 text-white">
                        <i class="fa-solid fa-sliders text-info me-2"></i>Configuration Panel
                    </h5>
                    <span class="badge bg-secondary bg-opacity-25 text-light font-monospace" style="font-size: 11px;">Go Engine v1.21</span>
                </div>
                
                <form id="boosterForm">
                    <!-- Target Link Input -->
                    <div class="mb-3">
                        <label for="target" class="form-label">TikTok Video Link / Video ID</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-link"></i></span>
                            <input type="text" class="form-control has-addon" id="target" name="target" placeholder="https://www.tiktok.com/@username/video/73... / ID" required>
                            <button type="button" class="btn btn-outline-secondary text-light px-3" onclick="pasteClipboard()" title="Paste Link">
                                <i class="fa-regular fa-clipboard"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted" style="font-size: 11px;">Mendukung format URL TikTok standar, mobile, maupun Video ID angka.</div>
                    </div>

                    <!-- Quantity Input & Presets -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="quantity" class="form-label mb-0">Jumlah Views</label>
                            <span class="text-muted" style="font-size: 11px;">Preset Cepat:</span>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text"><i class="fa-solid fa-eye"></i></span>
                            <input type="number" class="form-control has-addon" id="quantity" name="quantity" min="1" placeholder="Contoh: 10000" required>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="preset-btn" onclick="setQuantity(1000)">+1,000</button>
                            <button type="button" class="preset-btn" onclick="setQuantity(5000)">+5,000</button>
                            <button type="button" class="preset-btn active" onclick="setQuantity(10000)">+10,000</button>
                            <button type="button" class="preset-btn" onclick="setQuantity(50000)">+50,000</button>
                            <button type="button" class="preset-btn" onclick="setQuantity(100000)">+100,000</button>
                        </div>
                    </div>

                    <!-- Threads Range & Number Input (UP TO 5000 THREADS) -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="threads" class="form-label mb-0">Goroutine Threads (Kecepatan)</label>
                            <span class="badge bg-info bg-opacity-10 text-info font-monospace fw-bold" id="threadValueDisplay">500 Threads</span>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text"><i class="fa-solid fa-gauge-high"></i></span>
                            <input type="number" class="form-control has-addon font-monospace fw-bold" id="threads" name="threads" value="500" min="1" max="5000" oninput="syncThreadsFromInput(this.value)">
                        </div>
                        <!-- Range Slider -->
                        <input type="range" class="form-range" id="threadRange" min="10" max="5000" step="10" value="500" oninput="syncThreadsFromSlider(this.value)">
                        
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="button" class="preset-btn" onclick="setThreads(300)">300 Standard</button>
                            <button type="button" class="preset-btn" onclick="setThreads(1000)">1,000 High</button>
                            <button type="button" class="preset-btn" onclick="setThreads(3000)">3,000 Turbo</button>
                            <button type="button" class="preset-btn active" style="border-color: var(--accent-pink); color: var(--accent-pink);" onclick="setThreads(5000)">5,000 EXTREME ⚡</button>
                        </div>
                        <div class="form-text text-warning mt-2" style="font-size: 11px;" id="threadWarning">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Max threads 5,000 diaktifkan. Gunakan koneksi/VPS dengan spek tinggi untuk hasil optimal.
                        </div>
                    </div>

                    <!-- Proxy Input (Optional) -->
                    <div class="mb-4">
                        <label for="proxy" class="form-label">Proxy HTTP / HTTPS (Opsional)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-shield-halved"></i></span>
                            <input type="text" class="form-control has-addon" id="proxy" name="proxy" placeholder="ip:port ATAU ip:port:user:password">
                        </div>
                        <div class="form-text text-muted" style="font-size: 11px;">Kosongkan jika ingin menggunakan Outbound Direct Network VPS.</div>
                    </div>

                    <!-- Buttons -->
                    <div class="row g-2 pt-2">
                        <div class="col-8">
                            <button type="submit" id="submitBtn" class="btn btn-launch w-100">
                                <i class="fa-solid fa-bolt me-2"></i>Mulai Suntik (5000 Max)
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" id="stopBtn" class="btn btn-stop w-100" disabled>
                                <i class="fa-solid fa-square me-1"></i>Stop
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Terminal & Real-Time Log Monitor (Right Column) -->
        <div class="col-lg-7">
            <div class="terminal-wrapper">
                <!-- Mac-Style Window Bar -->
                <div class="terminal-header">
                    <div class="d-flex align-items-center gap-2">
                        <div class="mac-dots">
                            <span class="mac-dot close"></span>
                            <span class="mac-dot min"></span>
                            <span class="mac-dot max"></span>
                        </div>
                        <span class="terminal-title ms-2">
                            <i class="fa-solid fa-terminal text-info me-1"></i>live_output.log
                        </span>
                    </div>

                    <div class="terminal-actions d-flex align-items-center gap-2">
                        <div class="form-check form-switch mb-0 me-2" style="font-size: 11px;">
                            <input class="form-check-input" type="checkbox" id="autoScrollCheck" checked>
                            <label class="form-check-label text-muted" for="autoScrollCheck">Auto-scroll</label>
                        </div>
                        <button type="button" onclick="copyTerminalLogs()" title="Copy Logs">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                        <button type="button" onclick="clearTerminalLogs()" title="Clear Screen">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>

                <!-- Progress Header -->
                <div class="progress-bar-container" id="progressContainer">
                    <div class="d-flex justify-content-between align-items-center mb-2" style="font-size: 12px;">
                        <span class="text-muted font-monospace">
                            PROGRESS: <strong class="text-white" id="progressPercentText">0%</strong>
                        </span>
                        <span class="font-monospace text-info" id="progressDetailText">0 / 0 Views</span>
                    </div>
                    <div class="custom-progress">
                        <div class="custom-progress-inner" id="progressBarInner"></div>
                    </div>
                </div>

                <!-- Terminal Body -->
                <div class="terminal-body" id="terminalLog">[*] Menunggu instruksi dari Configuration Panel...</div>
            </div>
        </div>
    </div>
</div>

<script>
    let pollInterval = null;
    let targetViewsGoal = 0;

    // Sync Slider & Input Num
    function syncThreadsFromSlider(val) {
        document.getElementById('threads').value = val;
        updateThreadDisplay(val);
    }
    function syncThreadsFromInput(val) {
        let v = parseInt(val) || 1;
        if (v > 10000) v = 10000;
        document.getElementById('threadRange').value = Math.min(v, 5000);
        updateThreadDisplay(v);
    }

    function updateThreadDisplay(val) {
        const display = document.getElementById('threadValueDisplay');
        const cardThreads = document.getElementById('cardThreads');
        display.innerText = parseInt(val).toLocaleString() + ' Threads';
        cardThreads.innerText = parseInt(val).toLocaleString() + ' Active';
        
        const warning = document.getElementById('threadWarning');
        if (val > 3000) {
            warning.innerHTML = `<i class="fa-solid fa-bolt me-1 text-danger"></i><strong>TURBO EXTREME (${parseInt(val).toLocaleString()} Threads)</strong>: Membutuhkan CPU & bandwidth tinggi.`;
            warning.className = "form-text text-danger mt-2";
        } else if (val > 1000) {
            warning.innerHTML = `<i class="fa-solid fa-gauge-high me-1 text-warning"></i>High Performance Mode (${parseInt(val).toLocaleString()} Threads).`;
            warning.className = "form-text text-warning mt-2";
        } else {
            warning.innerHTML = `<i class="fa-solid fa-circle-check me-1 text-success"></i>Standard Stable Mode (${parseInt(val).toLocaleString()} Threads).`;
            warning.className = "form-text text-success mt-2";
        }
    }

    function setQuantity(val) {
        document.getElementById('quantity').value = val;
        document.getElementById('cardTarget').innerText = val.toLocaleString();
        
        // Highlight active button
        document.querySelectorAll('.preset-btn').forEach(btn => {
            if (btn.innerText.includes(val.toLocaleString())) {
                btn.classList.add('active');
            }
        });
    }

    function setThreads(val) {
        document.getElementById('threads').value = val;
        document.getElementById('threadRange').value = Math.min(val, 5000);
        updateThreadDisplay(val);
    }

    async function pasteClipboard() {
        try {
            const text = await navigator.clipboard.readText();
            if (text) {
                document.getElementById('target').value = text;
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    background: '#0f172a',
                    color: '#fff'
                });
                Toast.fire({ icon: 'success', title: 'Link berhasil ditempel!' });
            }
        } catch (err) {
            console.error('Clipboard access denied:', err);
        }
    }

    function resetForm() {
        document.getElementById('boosterForm').reset();
        document.getElementById('threads').value = 500;
        document.getElementById('threadRange').value = 500;
        updateThreadDisplay(500);
        document.getElementById('cardTarget').innerText = '0';
        document.getElementById('cardStatus').innerText = 'IDLE';
        document.getElementById('cardStatus').className = 'stat-value text-info';
        document.getElementById('cardSpeed').innerText = '0 /sec';
        document.getElementById('progressBarInner').style.width = '0%';
        document.getElementById('progressPercentText').innerText = '0%';
        document.getElementById('progressDetailText').innerText = '0 / 0 Views';
    }

    document.getElementById('boosterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const target = document.getElementById('target').value.trim();
        const quantity = parseInt(document.getElementById('quantity').value) || 0;
        const threads = parseInt(document.getElementById('threads').value) || 300;
        const proxy = document.getElementById('proxy').value.trim();
        
        targetViewsGoal = quantity;
        document.getElementById('cardTarget').innerText = quantity.toLocaleString();

        const btn = document.getElementById('submitBtn');
        const stopBtn = document.getElementById('stopBtn');
        const terminal = document.getElementById('terminalLog');

        // Update UI State to RUNNING
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Injecting...';
        btn.disabled = true;
        stopBtn.disabled = false;

        setEngineStatus('RUNNING', 'running');

        terminal.innerHTML = `[+] Inisialisasi Booster Ultra Engine...\n[*] Target: ${target}\n[*] Quantity: ${quantity.toLocaleString()} views\n[*] Threads: ${threads.toLocaleString()} Goroutines\n-------------------------------------------------\n`;

        const formData = new FormData();
        formData.append('target', target);
        formData.append('quantity', quantity);
        formData.append('threads', threads);
        formData.append('proxy', proxy);

        // Fetch execution API
        fetch('index.php?action=run', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                terminal.innerHTML += `[✓] ${data.message}\n[*] Memulai pemantauan real-time logs...\n\n`;
                
                // Toast notification
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    background: '#0f172a',
                    color: '#fff'
                });
                Toast.fire({ icon: 'success', title: 'Booster Berhasil Dijalankan!' });

                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(fetchLogs, 400);
            } else {
                terminal.innerHTML += `[!] Error: ${data.message}\n`;
                Swal.fire({ icon: 'error', title: 'Gagal Memulai', text: data.message, background: '#0f172a', color: '#fff' });
                resetUI();
            }
        })
        .catch(err => {
            terminal.innerHTML += `[!] Error Server: ${err.message}\n`;
            resetUI();
        });
    });

    // Event handler Stop
    document.getElementById('stopBtn').addEventListener('click', function() {
        const terminal = document.getElementById('terminalLog');
        const stopBtn = document.getElementById('stopBtn');

        stopBtn.disabled = true;
        stopBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Stopping...';
        terminal.innerHTML += '\n[!] Mengirim sinyal SIGKILL penghentian paksa ke server...\n';

        fetch('index.php?action=stop')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                terminal.innerHTML += `[✓] ${data.message}\n`;
            } else {
                terminal.innerHTML += `[!] Error: ${data.message}\n`;
            }
            clearInterval(pollInterval);
            fetchLogs(); 
            resetUI('STOPPED');
        })
        .catch(err => {
            terminal.innerHTML += `[!] Gagal menghentikan: ${err.message}\n`;
            clearInterval(pollInterval);
            resetUI('STOPPED');
        });
    });

    function setEngineStatus(stateText, dotClass) {
        const badge = document.getElementById('systemStatusBadge');
        const dot = document.getElementById('statusDot');
        const text = document.getElementById('statusText');
        const cardStatus = document.getElementById('cardStatus');

        text.innerText = stateText;
        cardStatus.innerText = stateText;

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

    function fetchLogs() {
        const terminal = document.getElementById('terminalLog');
        const autoScroll = document.getElementById('autoScrollCheck').checked;

        fetch('index.php?action=get_log')
        .then(res => res.text())
        .then(text => {
            // Colorize log text
            let formattedText = text
                .replace(/(\[\+\][^\n]*)/g, '<span class="log-success">$1</span>')
                .replace(/(\[\*\][^\n]*)/g, '<span class="log-info">$1</span>')
                .replace(/(\[\!\][^\n]*)/g, '<span class="log-danger">$1</span>')
                .replace(/(\[✓\][^\n]*)/g, '<span class="log-success">$1</span>');

            terminal.innerHTML = formattedText;
            
            if (autoScroll) {
                terminal.scrollTop = terminal.scrollHeight;
            }

            // Parse progress views count & rate from log if available (e.g., "views: 450/1000  rate: 120/s")
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
                    document.getElementById('progressBarInner').style.width = percent + '%';
                    document.getElementById('progressPercentText').innerText = percent + '%';
                    document.getElementById('progressDetailText').innerText = `${currentViews.toLocaleString()} / ${totalGoal.toLocaleString()} Views`;
                }
            }

            // Detect finish or stop condition
            if (text.includes('[✓] Selesai!') || text.includes('[!] Gagal') || text.includes('dihentikan secara paksa') || text.includes('tidak valid!')) {
                clearInterval(pollInterval);
                if (text.includes('[✓] Selesai!')) {
                    document.getElementById('progressBarInner').style.width = '100%';
                    document.getElementById('progressPercentText').innerText = '100%';
                    resetUI('FINISHED');
                } else {
                    resetUI('STOPPED');
                }
            }
        })
        .catch(err => {
            console.error('Log polling error:', err);
        });
    }

    function resetUI(finalState = 'IDLE') {
        const btn = document.getElementById('submitBtn');
        const stopBtn = document.getElementById('stopBtn');

        btn.innerHTML = '<i class="fa-solid fa-bolt me-2"></i>Mulai Suntik (5000 Max)';
        btn.disabled = false;
        
        stopBtn.innerHTML = '<i class="fa-solid fa-square me-1"></i>Stop';
        stopBtn.disabled = true;

        if (finalState === 'FINISHED') {
            setEngineStatus('FINISHED ✓', 'ready');
        } else if (finalState === 'STOPPED') {
            setEngineStatus('STOPPED', 'stopped');
        } else {
            setEngineStatus('System Ready', 'ready');
        }
    }

    function clearTerminalLogs() {
        document.getElementById('terminalLog').innerHTML = '[*] Screen cleared.\n';
    }

    function copyTerminalLogs() {
        const text = document.getElementById('terminalLog').innerText;
        navigator.clipboard.writeText(text).then(() => {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000,
                background: '#0f172a',
                color: '#fff'
            });
            Toast.fire({ icon: 'info', title: 'Logs berhasil disalin!' });
        });
    }

    // Initialize display on load
    updateThreadDisplay(500);
</script>

</body>
</html>
