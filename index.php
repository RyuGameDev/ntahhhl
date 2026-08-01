<?php
$output_msg = "";
$status = "";

// Mengirim response JSON jika dipicu lewat AJAX request
if (isset($_GET['action']) && $_GET['action'] === 'run') {
    header('Content-Type: application/json');
    $target = trim($_POST['target'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $threads = intval($_POST['threads'] ?? 300);
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

    echo json_encode(['status' => 'success', 'message' => 'Booster berhasil dijalankan di background server!']);
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
    <title>TikTok Booster Standalone</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #0f172a; 
            font-family: 'Poppins', sans-serif; 
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-custom { 
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1); 
            border-radius: 20px; 
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        .btn-primary-gradient {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        .btn-primary-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 92, 246, 0.4);
            color: white;
        }
        .btn-danger-gradient {
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        .btn-danger-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(239, 68, 68, 0.4);
            color: white;
        }
        .form-control {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
            border-radius: 12px;
            padding: 12px;
        }
        .form-control:focus {
            background-color: rgba(15, 23, 42, 0.8);
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.3);
            color: #f1f5f9;
        }
        /* Terminal Log Style */
        .terminal-box {
            background-color: #020617;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            font-family: 'Fira Code', monospace;
            font-size: 13px;
            padding: 15px;
            height: 310px;
            overflow-y: auto;
            color: #10b981;
            white-space: pre-wrap;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.8);
        }
        .terminal-header {
            background-color: #1e293b;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            padding: 8px 15px;
            font-family: 'Fira Code', monospace;
            font-size: 12px;
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-bottom: none;
            display: flex;
            align-items: center;
        }
        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .dot-red { background-color: #ef4444; }
        .dot-yellow { background-color: #eab308; }
        .dot-green { background-color: #22c55e; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center g-4">
        <!-- Card Form Booster -->
        <div class="col-lg-6">
            <div class="card card-custom p-4 p-md-5">
                <h3 class="text-center mb-4 fw-bold text-transparent bg-clip-text" style="background-image: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);">
                    <i class="fa-brands fa-tiktok me-2"></i>TikTok Views Booster
                </h3>
                
                <form id="boosterForm">
                    <div class="mb-3">
                        <label for="target" class="form-label fw-medium text-secondary">Link Video TikTok / Video ID</label>
                        <input type="text" class="form-control" id="target" name="target" placeholder="https://www.tiktok.com/@username/video/..." required>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label for="quantity" class="form-label fw-medium text-secondary">Jumlah Views</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" placeholder="Contoh: 1000" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="threads" class="form-label fw-medium text-secondary">Threads (Kecepatan)</label>
                            <input type="number" class="form-control" id="threads" name="threads" value="300" min="1" max="3000">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="proxy" class="form-label fw-medium text-secondary">Proxy HTTP (Opsional)</label>
                        <input type="text" class="form-control" id="proxy" name="proxy" placeholder="Format: host:port ATAU host:port:user:pw">
                        <div class="form-text text-muted" style="font-size: 11px;">Biarkan kosong untuk koneksi langsung tanpa proxy.</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-8">
                            <button type="submit" id="submitBtn" class="btn btn-primary-gradient w-100 py-3 fw-semibold">
                                <i class="fa-solid fa-play me-2"></i>Mulai Suntik
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" id="stopBtn" class="btn btn-danger-gradient w-100 py-3 fw-semibold" disabled>
                                <i class="fa-solid fa-stop me-2"></i>Stop
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Terminal Log Real-time -->
        <div class="col-lg-6" id="logSection" style="display: none;">
            <div class="h-100 d-flex flex-column">
                <div class="terminal-header">
                    <span class="dot dot-red"></span>
                    <span class="dot dot-yellow"></span>
                    <span class="dot dot-green"></span>
                    <span class="ms-2"><i class="fa-solid fa-terminal me-2"></i>Live Console Output</span>
                </div>
                <div class="terminal-box flex-grow-1" id="terminalLog">[*] Menunggu instruksi...</div>
            </div>
        </div>
    </div>
</div>

<script>
    let pollInterval = null;

    document.getElementById('boosterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const target = document.getElementById('target').value;
        const quantity = document.getElementById('quantity').value;
        const threads = document.getElementById('threads').value;
        const proxy = document.getElementById('proxy').value;
        
        const btn = document.getElementById('submitBtn');
        const stopBtn = document.getElementById('stopBtn');
        const logSection = document.getElementById('logSection');
        const terminal = document.getElementById('terminalLog');

        // Ganti status tombol
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Memproses...';
        btn.disabled = true;
        stopBtn.disabled = false;

        // Tampilkan terminal
        logSection.style.display = 'block';
        terminal.innerHTML = '[*] Menghubungkan ke API server...\n';

        const formData = new FormData();
        formData.append('target', target);
        formData.append('quantity', quantity);
        formData.append('threads', threads);
        formData.append('proxy', proxy);

        // Kirim request untuk trigger Go binary
        fetch('index.php?action=run', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                terminal.innerHTML += `[+] ${data.message}\n`;
                
                // Mulai polling logs setiap 500ms
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(fetchLogs, 500);
            } else {
                terminal.innerHTML += `[!] Error: ${data.message}\n`;
                resetUI();
            }
        })
        .catch(err => {
            terminal.innerHTML += `[!] Gagal memicu process: ${err.message}\n`;
            resetUI();
        });
    });

    // Event handler tombol Stop
    document.getElementById('stopBtn').addEventListener('click', function() {
        const terminal = document.getElementById('terminalLog');
        const stopBtn = document.getElementById('stopBtn');

        stopBtn.disabled = true;
        stopBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Stopping...';
        terminal.innerHTML += '\n[*] Mengirim sinyal penghentian proses ke server...\n';

        fetch('index.php?action=stop')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                terminal.innerHTML += `[+] ${data.message}\n`;
            } else {
                terminal.innerHTML += `[!] Error: ${data.message}\n`;
            }
            clearInterval(pollInterval);
            fetchLogs(); // Ambil log terakhir satu kali
            resetUI();
        })
        .catch(err => {
            terminal.innerHTML += `[!] Gagal mengirim instruksi stop: ${err.message}\n`;
            clearInterval(pollInterval);
            resetUI();
        });
    });

    function fetchLogs() {
        const terminal = document.getElementById('terminalLog');

        fetch('index.php?action=get_log')
        .then(res => res.text())
        .then(text => {
            terminal.innerHTML = text;
            
            // Auto scroll terminal ke baris terbawah
            terminal.scrollTop = terminal.scrollHeight;

            // Hentikan polling jika log mendeteksi tanda selesai atau dihentikan
            if (text.includes('[✓] Selesai!') || text.includes('[!] Gagal') || text.includes('dihentikan secara paksa') || text.includes('tidak valid!')) {
                clearInterval(pollInterval);
                resetUI();
            }
        })
        .catch(err => {
            console.error('Error polling logs:', err);
        });
    }

    function resetUI() {
        const btn = document.getElementById('submitBtn');
        const stopBtn = document.getElementById('stopBtn');

        btn.innerHTML = '<i class="fa-solid fa-play me-2"></i>Mulai Suntik';
        btn.disabled = false;
        
        stopBtn.innerHTML = '<i class="fa-solid fa-stop me-2"></i>Stop';
        stopBtn.disabled = true;
    }
</script>
</body>
</html>
