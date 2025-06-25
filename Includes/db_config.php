<?php

use PHPMailer\PHPMailer\PHPMailer;
// expertus-app/includes/db_config.php

define('DB_SERVER', '127.0.0.1'); // Host database (misalnya localhost)
define('DB_USERNAME', 'root');   // Username database
define('DB_PASSWORD', '');       // Password database (kosongkan jika tidak ada)
define('DB_NAME', 'expertus');   // Nama database yang sudah kamu buat

// --- Konfigurasi PHPMailer SMTP (Tambahkan ini) ---
define('MAIL_HOST', 'smtp.gmail.com'); // Ganti dengan host SMTP Anda (misal: smtp.gmail.com untuk Gmail)
define('MAIL_USERNAME', 'mandutch816@gmail.com'); // Ganti dengan email pengirim Anda
define('MAIL_PASSWORD', 'kdwc hmyh haeq evtd'); // Ganti dengan password email Anda atau App Password (untuk Gmail)
define('MAIL_SMTP_SECURE', 'ssl'); // Gunakan SMTPS (465) atau STARTTLS (587)
define('MAIL_PORT', 465); // Port untuk SMTPS (465) atau STARTTLS (587)
define('MAIL_FROM_EMAIL', 'noreply@expertus.com'); // Email yang akan muncul sebagai pengirim
define('MAIL_FROM_NAME', 'ExpertUs Support'); // Nama pengirim

// --- DEFINISI BASE_URL secara dinamis ---
// Ini akan secara otomatis mendeteksi protokol, host, dan subdirektori proyek
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script_name = $_SERVER['SCRIPT_NAME'];
    $script_dir = dirname($script_name); // Direktori dari file PHP yang dieksekusi (misal: /expertus-app/admin/orders)

    // Temukan posisi 'expertus-app' di jalur skrip untuk menentukan base URL
    $base_url_path = '';
    $path_segments = explode('/', trim($script_dir, '/')); // Pisahkan segmen jalur

    // Cari segmen 'expertus-app' atau identifikasi root proyek secara lebih umum
    // Jika proyek Anda selalu di dalam folder 'expertus-app' di root web server atau subdomain
    $found_app_root = false;
    foreach ($path_segments as $segment) {
        if ($segment === 'expertus-app') { // Ganti 'expertus-app' jika nama folder proyek Anda berbeda
            $base_url_path .= '/' . $segment;
            $found_app_root = true;
            break; // Berhenti setelah menemukan root aplikasi
        }
        // Jika proyek Anda langsung di root domain atau sub-domain, Anda mungkin tidak perlu segmen ini
        // Jika Anda mengakses http://localhost/ saja dan expertus-app adalah root web server, $base_url_path akan kosong
    }

    if (!$found_app_root && $script_dir === '/') { // Jika diakses langsung dari root domain/subdomain
         $base_url = $protocol . "://" . $host;
    } elseif ($found_app_root) {
        $base_url = $protocol . "://" . $host . $base_url_path;
    } else {
        // Fallback: mencoba mengambil path dari script_dir, mungkin jika ada lebih banyak subfolder
        // Ini mungkin perlu disesuaikan lebih lanjut jika struktur Anda kompleks
        $base_url = $protocol . "://" . $host . $script_dir;
    }

    // Jika Anda ingin *selalu* menggunakan jalur tertentu di localhost, Anda bisa override di sini:
    // Contoh: if ($host === 'localhost' || strpos($host, '192.168.') === 0) { $base_url = 'http://localhost/expertus-app'; }

    define('BASE_URL', rtrim($base_url, '/')); // Pastikan tidak ada trailing slash ganda
}

// Membuat koneksi
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Mengecek koneksi
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}
?>