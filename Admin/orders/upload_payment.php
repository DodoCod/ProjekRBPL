<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

// Pastikan admin sudah login
checkAdminLogin();

// --- PENGATURAN DEBUGGING (AKTIFKAN SAAT PENGEMBANGAN, NONAKTIFKAN DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); // Tampilkan semua error
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Aktifkan pelaporan error MySQLi

// Inisialisasi variabel untuk tampilan
$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$form_success = '';
$form_error = '';
$upload_errors = []; // Array untuk menyimpan error upload per field

// --- DEFINISI GLOBAL UNTUK TIPE FILE DAN UKURAN MAKSIMAL ---
$allowed_file_types = ['application/pdf', 'image/jpeg', 'image/png'];
$max_file_size = 5 * 1024 * 1024; // 5 MB

define('UPLOAD_BASE_DIR_PHYSICAL', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR);
define('UPLOAD_BASE_DIR_URL', BASE_URL . '/public/files/');

// Ambil ID order dari URL
$consult_id_from_url = null; 
if (isset($_POST['consult_id'])) {
    // Jika ini POST request, ambil dari $_POST
    $consult_id_from_url = filter_var($_POST['consult_id'], FILTER_VALIDATE_INT);
} elseif (isset($_GET['IdConsult'])) {
    // Jika ini GET request, ambil dari $_GET
    $consult_id_from_url = filter_var($_GET['IdConsult'], FILTER_VALIDATE_INT);
}

// --- AMBIL DATA ORDER DARI DATABASE ---
$order_data = null;
if ($consult_id_from_url !== false && $consult_id_from_url !== null) { // Pastikan ID valid
    global $conn;

    $sql_get_order = "SELECT
                        c.IdCust, c.Nama AS customer_name,
                        con.IdConsult, con.HasilConsult, con.PembayaranDP, con.BayarLunas,
                        con.StatusPembayaran AS status_pembayaran_db, con.updated_at
                      FROM `consult` AS con
                      JOIN `customer` AS c ON con.IdCust = c.IdCust
                      WHERE con.IdConsult = ?";

    if ($stmt_get_order = $conn->prepare($sql_get_order)) {
        $stmt_get_order->bind_param('i', $consult_id_from_url);
        $stmt_get_order->execute();
        $result = $stmt_get_order->get_result();
        
        if ($result->num_rows > 0) {
            $order_data = $result->fetch_assoc();
            // Konversi nilai '0', '0.00', atau string kosong dari DB menjadi null untuk konsistensi
            foreach (['HasilConsult', 'PembayaranDP', 'BayarLunas'] as $field) {
                if (isset($order_data[$field]) && (empty($order_data[$field]) || $order_data[$field] === '0' || $order_data[$field] === '0.00')) {
                    $order_data[$field] = null;
                }
            }
        } else {
            error_log("DEBUG: No order data found for IdConsult: " . $consult_id_from_url);
        }
        $stmt_get_order->close();
    } else {
        $form_error = "Kesalahan persiapan query pengambilan data order: " . $conn->error;
        error_log("DEBUG: Database query preparation error: " . $conn->error);
    }
} else {
    error_log("DEBUG: IdConsult is invalid or missing: " . var_export($consult_id_from_url, true));
}

// --- PENANGANAN JIKA consult_id TIDAK DITEMUKAN ATAU TIDAK VALID ---
if ($consult_id_from_url === false || $consult_id_from_url === null || !$order_data) {
    $form_error = "Order Konsultasi tidak ditemukan atau ID tidak valid. Mohon akses melalui Order Form Tahap 1.";
    // Inisialisasi $order_data agar HTML tidak error "Undefined index"
    $order_data = [
        'IdCust' => null, 'customer_name' => '', 'IdConsult' => null,
        'HasilConsult' => null, 'PembayaranDP' => null, 'BayarLunas' => null,
        'status_pembayaran_db' => 'Belum Dibayar', 'updated_at' => null
    ];
    if ($_SERVER['REQUEST_METHOD'] == 'POST' || (isset($_GET['IdConsult']) && $consult_id_from_url === false) || !isset($_GET['IdConsult'])) {
        error_log("DEBUG: Redirecting due to invalid consult_id or no order data during POST or initial GET failure.");
        header('location: ' . BASE_URL . '/admin/orders/index.php?upload_error=' . urlencode($form_error));
        exit;
    }
}

/**
 * Fungsi helper untuk menangani proses upload file.
 * Mengembalikan path URL relatif (TANPA BASE_URL) jika berhasil, atau null jika gagal/tidak ada upload.
 * Menambahkan pesan error ke global $upload_errors array.
 *
 * @param string $file_input_html_name Nama atribut 'name' dari input file HTML.
 * @param string $upload_sub_dir Nama sub-direktori di dalam public/files/ (misal: 'HasilConsult', 'BayarDp').
 * @param string $prefix Prefix untuk nama file yang disimpan (misal: 'hasil_konsultasi', 'dp', 'lunas').
 * @param int $consult_id ID Konsultasi untuk penamaan file.
 * @param int $customer_id ID Customer untuk penamaan file.
 * @param string $customer_name Nama Customer untuk penamaan file.
 * @param string|null $existing_db_path Path file yang sudah ada di database (URL relatif).
 * @return string|null Path file URL relatif (misal: /public/files/...) yang baru diupload atau path lama jika tidak ada upload/gagal.
 */
function handle_upload($file_input_html_name, $upload_sub_dir, $prefix, $consult_id, $customer_id, $customer_name, $existing_db_path) {
    global $upload_errors, $allowed_file_types, $max_file_size;

    // Default: pertahankan path yang sudah ada dari DB (yang seharusnya sudah relatif)
    $uploaded_file_path = $existing_db_path; 

    error_log("--- Mulai handle_upload untuk: " . $file_input_html_name . " ---");
    error_log("Existing DB Path: " . ($existing_db_path ?? 'NULL'));

    // Cek apakah ada file yang diupload untuk input ini
    if (isset($_FILES[$file_input_html_name]) && $_FILES[$file_input_html_name]['error'] != UPLOAD_ERR_NO_FILE) {
        $file_error_code = $_FILES[$file_input_html_name]['error'];
        error_log("File Error Code for {$file_input_html_name}: " . $file_error_code);

        // Periksa error PHP system yang terkait upload
        if ($file_error_code != UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => "Ukuran file melebihi batas maksimal server (php.ini: upload_max_filesize / post_max_size). Batas server: " . ini_get('upload_max_filesize'),
                UPLOAD_ERR_FORM_SIZE => "Ukuran file melebihi batas maksimal form HTML.",
                UPLOAD_ERR_PARTIAL => "File hanya terunggah sebagian.",
                UPLOAD_ERR_NO_TMP_DIR => "Direktori temporary tidak ditemukan di server. Hubungi administrator server.",
                UPLOAD_ERR_CANT_WRITE => "Gagal menulis file ke disk server. Periksa izin folder temporary atau disk penuh.",
                UPLOAD_ERR_EXTENSION => "Unggahan file dihentikan oleh ekstensi PHP.",
            ];
            $upload_errors[$file_input_html_name][] = $error_messages[$file_error_code] ?? "Terjadi error tidak dikenal saat mengunggah (Kode: " . $file_error_code . ").";
            error_log("Upload System Error for {$file_input_html_name}: " . ($upload_errors[$file_input_html_name][0] ?? ''));
            return $uploaded_file_path; // Kembalikan path lama jika ada error sistem
        }

        // Lanjutkan proses jika tidak ada error sistem
        $file_tmp_name = $_FILES[$file_input_html_name]['tmp_name'];
        $file_name_original = basename($_FILES[$file_input_html_name]['name']);
        $file_size = $_FILES[$file_input_html_name]['size'];
        
        // Dapatkan MIME type (lebih aman dari ekstensi)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_type = finfo_file($finfo, $file_tmp_name);
        finfo_close($finfo);

        $file_ext = strtolower(pathinfo($file_name_original, PATHINFO_EXTENSION));

        // Bersihkan nama customer untuk nama file
        $clean_customer_name = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $customer_name));
        $id_cust_for_filename = ($customer_id) ? $customer_id : 'NO_CUST_ID';
        $new_file_name = $prefix . '_' . $consult_id . '_' . $id_cust_for_filename . '_' . $clean_customer_name . '.' . $file_ext;
        
        $full_upload_dir_physical = UPLOAD_BASE_DIR_PHYSICAL . $upload_sub_dir . DIRECTORY_SEPARATOR;
        $destination_physical = $full_upload_dir_physical . $new_file_name;
        
        // Simpan path RELATIF terhadap root web server/aplikasi di database
        $relative_path_for_db = '/public/files/' . $upload_sub_dir . '/' . $new_file_name; 

        error_log("Target physical directory: " . $full_upload_dir_physical);
        error_log("Destination physical path: " . $destination_physical);
        error_log("Relative path for DB: " . $relative_path_for_db); // Log path yang akan masuk DB

        // --- Validasi Konten File ---
        if (!in_array($file_type, $allowed_file_types)) {
            $upload_errors[$file_input_html_name][] = "Tipe file '{$file_type}' tidak diizinkan. Hanya " . implode(', ', $allowed_file_types) . ".";
            error_log("Validation Error: Invalid file type for {$file_input_html_name}.");
        }
        if ($file_size > $max_file_size) {
            $upload_errors[$file_input_html_name][] = "Ukuran file terlalu besar (maks " . ($max_file_size / 1024 / 1024) . "MB).";
            error_log("Validation Error: File size too large for {$file_input_html_name}. Size: {$file_size}");
        }

        if (empty($upload_errors[$file_input_html_name])) {
            // Buat direktori jika belum ada
            if (!is_dir($full_upload_dir_physical)) {
                if (!mkdir($full_upload_dir_physical, 0775, true)) {
                    $upload_errors[$file_input_html_name][] = "Gagal membuat direktori unggahan: " . $full_upload_dir_physical . ". Periksa izin folder root public/files/.";
                    error_log("Directory Creation Error for {$file_input_html_name}: Gagal membuat direktori.");
                    return $uploaded_file_path;
                }
            }

            // Cek izin tulis
            if (!is_writable($full_upload_dir_physical)) {
                $upload_errors[$file_input_html_name][] = "Direktori unggahan tidak memiliki izin tulis: " . $full_upload_dir_physical . ". Mohon atur izin folder ke 0775 atau 0777.";
                error_log("Permissions Error for {$file_input_html_name}: Direktori tidak writable.");
                return $uploaded_file_path;
            }
            

            $old_file_full_path_physical = UPLOAD_BASE_DIR_PHYSICAL . str_replace('/public/files/', '', $existing_db_path);


            if (!empty($existing_db_path)) {
                // Hapus BASE_URL dari existing_db_path jika ada
                $clean_existing_path = str_replace(BASE_URL, '', $existing_db_path);
                // Pastikan itu benar-benar mengarah ke public/files/ sub-folder
                if (strpos($clean_existing_path, '/public/files/') === 0) {
                    $old_file_full_path_physical = dirname(dirname(__DIR__)) . $clean_existing_path;
                } else {
                    // Fallback jika existing_db_path tidak sesuai format yang diharapkan
                    $old_file_full_path_physical = UPLOAD_BASE_DIR_PHYSICAL . $existing_db_path;
                }
            } else {
                $old_file_full_path_physical = null;
            }


            if (!empty($old_file_full_path_physical) && file_exists($old_file_full_path_physical) && $old_file_full_path_physical !== $destination_physical) {
                if (unlink($old_file_full_path_physical)) {
                    error_log("SUCCESS: File lama '{$old_file_full_path_physical}' berhasil dihapus.");
                } else {
                    error_log("WARNING: Gagal menghapus file lama '{$old_file_full_path_physical}'. Periksa izin atau path.");
                }
            }

            // Pindahkan file yang diupload dari direktori temp ke tujuan akhir
            if (move_uploaded_file($file_tmp_name, $destination_physical)) {
                $uploaded_file_path = $relative_path_for_db; // Path yang disimpan di DB (relatif terhadap root aplikasi)
                error_log("SUCCESS: File '{$file_name_original}' berhasil diunggah ke '{$uploaded_file_path}'.");
            } else {
                $upload_errors[$file_input_html_name][] = "Gagal memindahkan file. Ini bisa jadi masalah izin, jalur tujuan, atau file sementara hilang. Error PHP: " . (error_get_last()['message'] ?? 'Tidak diketahui');
                error_log("FAILED: move_uploaded_file failed for {$file_input_html_name}. PHP Error: " . (error_get_last()['message'] ?? 'N/A') . " from tmp: {$file_tmp_name} to dest: {$destination_physical}");
            }
        }
    }
    error_log("--- Selesai handle_upload untuk: " . $file_input_html_name . ", Final Path: " . ($uploaded_file_path ?? 'NULL') . " ---");
    return $uploaded_file_path;
}


// --- PENANGANAN POST REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_files') {
    global $conn, $order_data, $upload_errors;

    $current_consult_id = (int)($consult_id_from_url); // Menggunakan variabel yang sudah divalidasi
    $customer_id = $order_data['IdCust'] ?? 0;
    $customer_name = $order_data['customer_name'] ?? 'Unknown Customer';

    // Ambil path file yang sudah ada di database SEBELUM proses upload baru
    $existing_hasil_konsultasi_file = $order_data['HasilConsult'];
    $existing_pembayaran_dp_file = $order_data['PembayaranDP'];
    $existing_pembayaran_lunas_file = $order_data['BayarLunas'];
    $current_db_payment_status = $order_data['status_pembayaran_db'];

    // Inisialisasi path yang akan diupdate ke DB dengan nilai yang sudah ada
    $new_hasil_konsultasi_path = $existing_hasil_konsultasi_file;
    $new_pembayaran_dp_path = $existing_pembayaran_dp_file;
    $new_pembayaran_lunas_path = $existing_pembayaran_lunas_file;

    // --- Proses Upload Hasil Konsultasi ---
    // Panggil handle_upload, hasilnya akan jadi path baru (relatif) atau null/error
    $uploaded_hasil_konsultasi = handle_upload(
        'hasil_konsultasi_file', 'HasilConsult', 'hasil_konsultasi',
        $current_consult_id, $customer_id, $customer_name, $existing_hasil_konsultasi_file
    );
    // Jika ada file baru diupload dan tidak ada error, gunakan path baru
    // Jika ada error upload, path tetap pakai yang lama.
    if (isset($_FILES['hasil_konsultasi_file']) && $_FILES['hasil_konsultasi_file']['error'] == UPLOAD_ERR_OK && empty($upload_errors['hasil_konsultasi_file'])) {
        $new_hasil_konsultasi_path = $uploaded_hasil_konsultasi;
    } else if (!empty($upload_errors['hasil_konsultasi_file']) || (isset($_FILES['hasil_konsultasi_file']) && $_FILES['hasil_konsultasi_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        // Jika ada error, atau user mencoba upload tapi gagal, pertahankan file lama
        $new_hasil_konsultasi_path = $existing_hasil_konsultasi_file;
    } 

    // --- Proses Upload Pembayaran DP ---
    $uploaded_pembayaran_dp = handle_upload(
        'PembayaranDP', 'BayarDp', 'dp',
        $current_consult_id, $customer_id, $customer_name, $existing_pembayaran_dp_file
    );
    if (isset($_FILES['PembayaranDP']) && $_FILES['PembayaranDP']['error'] == UPLOAD_ERR_OK && empty($upload_errors['PembayaranDP'])) {
        $new_pembayaran_dp_path = $uploaded_pembayaran_dp;
    } else if (!empty($upload_errors['PembayaranDP']) || (isset($_FILES['PembayaranDP']) && $_FILES['PembayaranDP']['error'] != UPLOAD_ERR_NO_FILE)) {
        $new_pembayaran_dp_path = $existing_pembayaran_dp_file;
    }

    // --- Proses Upload Pembayaran Lunas ---
    $uploaded_pembayaran_lunas = handle_upload(
        'BayarLunas', 'BayarLunas', 'lunas',
        $current_consult_id, $customer_id, $customer_name, $existing_pembayaran_lunas_file
    );
    if (isset($_FILES['BayarLunas']) && $_FILES['BayarLunas']['error'] == UPLOAD_ERR_OK && empty($upload_errors['BayarLunas'])) {
        $new_pembayaran_lunas_path = $uploaded_pembayaran_lunas;
    } else if (!empty($upload_errors['BayarLunas']) || (isset($_FILES['BayarLunas']) && $_FILES['BayarLunas']['error'] != UPLOAD_ERR_NO_FILE)) {
        $new_pembayaran_lunas_path = $existing_pembayaran_lunas_file;
    }

    // --- Tentukan Status Pembayaran Baru berdasarkan file yang ADA (lama atau baru) ---
    $new_payment_status = $current_db_payment_status; // Default: pertahankan status lama

    if (!empty($new_pembayaran_lunas_path)) {
        $new_payment_status = 'Lunas';
    } elseif (!empty($new_pembayaran_dp_path)) {
        $new_payment_status = 'DP Dibayar';
    } else {
        // Jika tidak ada file DP maupun Lunas (baik lama maupun baru), status menjadi Belum Dibayar
        $new_payment_status = 'Belum Dibayar';
    }


    // --- Validasi Akhir untuk Pembayaran ---
    // Ini berlaku jika pada awal form, TIDAK ADA bukti pembayaran sama sekali.
    // Jika tidak ada bukti pembayaran yang diupload dan sebelumnya juga tidak ada, maka error.
    // Dapatkan status file pembayaran sebelum proses update POST
    $is_any_payment_file_exists_in_db = !empty($order_data['PembayaranDP']) || !empty($order_data['BayarLunas']);

    $is_any_payment_file_exists_after_process = !empty($new_pembayaran_dp_path) || !empty($new_pembayaran_lunas_path);
    if (!$is_any_payment_file_exists_after_process && !$is_any_payment_file_exists_in_db) {
        $upload_errors['pembayaran_umum'][] = "Bukti pembayaran DP atau Lunas wajib diunggah untuk order baru.";
    }


    // --- Cek Total Error dan Lakukan Redirect jika ada ---
    if (!empty($upload_errors)) {
        $error_messages_flat = [];
        foreach ($upload_errors as $field => $messages) {
            foreach ($messages as $msg) {
                $error_messages_flat[] = $msg;
            }
        }
        $form_error = "Terdapat kesalahan saat mengunggah file:<br>" . implode("<br>", $error_messages_flat);
        header('location: ' . BASE_URL . '/admin/orders/upload_payment.php?consult_id=' . urlencode($current_consult_id) . '&upload_error=' . urlencode($form_error));
        exit;
    } else {
        // --- Lakukan Update ke Database ---
        $sql_update_consult_parts = [];
        $update_params = [];
        $update_types = '';

        // Hanya tambahkan ke update jika path berubah dari yang ada di DB (atau dari NULL menjadi ada)
        if ($new_hasil_konsultasi_path !== ($existing_hasil_konsultasi_file ?? '')) {
            $sql_update_consult_parts[] = "`HasilConsult` = ?";
            $update_params[] = &$new_hasil_konsultasi_path;
            $update_types .= 's';
        }

        if ($new_pembayaran_dp_path !== ($existing_pembayaran_dp_file ?? '')) {
            $sql_update_consult_parts[] = "`PembayaranDP` = ?";
            $update_params[] = &$new_pembayaran_dp_path;
            $update_types .= 's';
        }

        if ($new_pembayaran_lunas_path !== ($existing_pembayaran_lunas_file ?? '')) {
            $sql_update_consult_parts[] = "`BayarLunas` = ?";
            $update_params[] = &$new_pembayaran_lunas_path;
            $update_types .= 's';
        }
        
        // Status pembayaran selalu diupdate jika berubah
        if ($new_payment_status !== $current_db_payment_status) {
            $sql_update_consult_parts[] = "`StatusPembayaran` = ?";
            $update_params[] = &$new_payment_status;
            $update_types .= 's';
        }

        // --- Perbaikan nama kolom updated_at ---
        $updated_at_value = date('Y-m-d H:i:s');
        $sql_update_consult_parts[] = "`updated_at` = ?"; // Gunakan 'updated_at' sesuai nama kolom di DB
        $update_params[] = &$updated_at_value;
        $update_types .= 's';
        
        // Cek apakah ada perubahan yang signifikan selain updated_at
        $has_significant_change = (
            $new_hasil_konsultasi_path !== ($existing_hasil_konsultasi_file ?? '') ||
            $new_pembayaran_dp_path !== ($existing_pembayaran_dp_file ?? '') ||
            $new_pembayaran_lunas_path !== ($existing_pembayaran_lunas_file ?? '') ||
            $new_payment_status !== $current_db_payment_status
        );

        // Hanya update jika ada perubahan signifikan atau setidaknya ada kolom untuk diupdate (selain hanya updated_at yang otomatis di DB)
        if ($has_significant_change || count($sql_update_consult_parts) > 1 || (count($sql_update_consult_parts) === 1 && strpos($sql_update_consult_parts[0], 'updated_at') === false) ) {
            $sql_update_consult_str = "UPDATE `consult` SET " . implode(', ', $sql_update_consult_parts) . " WHERE `IdConsult` = ?";
            $update_types .= 'i'; // Untuk IdConsult
            $update_params[] = &$current_consult_id;

            $stmt_update = null;
            try {
                if ($stmt_update = $conn->prepare($sql_update_consult_str)) {
                    call_user_func_array([$stmt_update, 'bind_param'], array_merge([$update_types], $update_params));
                    if ($stmt_update->execute()) {
                        $form_success = 'File berhasil diunggah dan data diperbarui!';
                        header('location: ' . BASE_URL . '/admin/orders/index.php?upload_success=true');
                        exit;
                    } else {
                        throw new Exception('Gagal memperbarui database (eksekusi): ' . $stmt_update->error);
                    }
                } else {
                    throw new Exception('Kesalahan persiapan query update database: ' . $conn->error);
                }
            } catch (Exception $e) {
                $form_error = "Terjadi error fatal: " . $e->getMessage();
                header('location: ' . BASE_URL . '/admin/orders/upload_payment.php?consult_id=' . urlencode($current_consult_id) . '&upload_error=' . urlencode($form_error));
                exit;
            } finally {
                if ($stmt_update instanceof mysqli_stmt) {
                    $stmt_update->close();
                }
            }
        } else {
            // Jika tidak ada perubahan signifikan, hanya informasikan
            $form_success = 'Tidak ada perubahan file atau status pembayaran untuk diperbarui.';
            header('location: ' . BASE_URL . '/admin/orders/index.php?upload_success=' . urlencode($form_success)); // Kirim pesan spesifik
            exit;
        }
    }
}

// Tangani pesan success/error dari redirect GET
if (isset($_GET['upload_success'])) {
    $form_success = htmlspecialchars(urldecode($_GET['upload_success'])); // Menggunakan urldecode
    if ($form_success === 'true') {
        $form_success = 'File berhasil diunggah dan data diperbarui!';
    }
} elseif (isset($_GET['upload_error'])) {
    $form_error = htmlspecialchars(urldecode($_GET['upload_error'])); // Menggunakan urldecode
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Form (Upload Files)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* CSS Umum */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            width: 100%;
            min-height: 100vh;
            background-color: #204447;
            background-image: url('<?php echo BASE_URL; ?>/Public/Background/backgroundbatik1.png');
            background-repeat: repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        .overlay {
            position: absolute;
            background-color: rgba(16, 39, 40, 0.85);
            z-index: 1;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .form-header {
            padding: 30px;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 3;
            display: flex;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: auto;
            margin-right: 20px;
        }
        .form-header .back-arrow {
            color: white;
            font-size: 1.5rem;
            text-decoration: none;
            margin-left: 20px;
            transition: color 0.2s;
        }
        .form-header .back-arrow:hover {
            color: #d1d5db;
        }

        .logo img {
            height: 30px;
            width: auto;
        }

        .logo h1 {
            font-size: 18px;
            color: white;
            font-weight: 600;
        }

        .wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            z-index: 2;
            position: relative;
            width: 100%;
            min-height: 100vh;
        }

        .form-container {
            background-color: #FFFF;
            width: 100%;
            max-width: 700px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .form-body {
            padding: 30px;
        }

        .form-body h2 {
            text-align: center;
            font-size: 24px;
            margin: 25px 0 20px 0;
            font-weight: 700;
            color: #1a202c;
        }
        
        .form-body h3 { 
            font-size: 1.125rem;
            font-weight: 600;
            color: #1a202c;
            margin: 20px 0 10px 10px;
        }


        /* Styles for the file upload areas */
        .file-upload-area {
            border: 1px dashed #D1D5DB;
            border-radius: 0.5rem;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out;
            background-color: #f9f9f9;
            margin: 10px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 120px;
            position: relative; 
        }
        .file-upload-area:hover {
            background-color: #f3f4f6;
            border-color: #9CA3AF;
        }
        .file-upload-area .upload-icon {
            width: 48px;
            height: 48px;
            margin-bottom: 0.75rem;
            object-fit: contain;
        }
        .file-upload-area p {
            font-size: 0.9rem;
            color: #6B7280;
            margin-bottom: 0.5rem;
        }
        .file-upload-area .choose-file-btn {
            background-color: #10B981;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-block; 
            z-index: 10; 
        }
        .file-upload-area .choose-file-btn:hover {
            background-color: #059669;
        }
        .file-upload-area input[type="file"] {
            display: none; 
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            cursor: pointer;
        }
        .file-upload-area .file-name-display {
            font-size: 0.85rem;
            color: #4B5563;
            margin-top: 0.5rem;
            word-break: break-all;
            display: none; 
        }

        .file-chosen .upload-icon,
        .file-chosen p:nth-of-type(2), 
        .file-chosen .choose-file-btn {
            display: none !important;
        }
        .file-chosen .file-name-display {
            display: block !important;
        }

        .file-upload-area p:first-child { 
            display: block; 
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin: 0 0 10px 0;
        }

        .file-chosen.file-upload-area p:first-child {
             display: none !important;
        }


        form hr {
            margin: 20px 0px;
            border: none;
            border-top: 1px solid #c7c7c7;
        }

        .flex-row {
            display: flex;
            gap: 10px;
        }

        .flex-row .file-upload-area { 
            flex: 1;
            box-sizing: border-box;
            width: auto;
        }


        .button-container {
            display: flex;
            justify-content: center;
        }

        button[type="submit"] {
            width: 50%;
            padding: 12px 14px;
            border-radius: 20px;
            margin-top: 20px;
            font-size: 14px;
            font-weight: 600;
            color: #FFFF;
            background-color: #91D047;
            border: none;
            cursor: pointer;
            transition: 0.3s ease;
        }

        button[type="submit"]:hover {
            background-color: #7bc13F;
        }
        
        /* Error messages */
        .error-message {
            color: #ef4444;
            font-size: 12px;
            margin-top: 5px;
            margin-left: 10px;
            display: block;
        }
        .alert-error {
            background-color: #fee2e2;
            border: 1px solid #ef4444;
            color: #dc2626;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .alert-success {
            background-color: #D1FAE5;
            border: 1px solid #10B981;
            color: #065F46;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="overlay"></div>
    
    <div class="form-header">
        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="back-arrow"><i class="fas fa-chevron-left"></i></a>
        <div class="logo">
            <img src="<?php echo BASE_URL; ?>/public/Logo/Logo2.png" alt="ExpertUs">
        </div>
    </div>
    
    <div class="wrapper">
        <div class="form-container">
            <div class="form-body">
                <h2>Order Form</h2>

                <?php if (!empty($form_error)): ?>
                    <div class="alert-error"><?php echo htmlspecialchars($form_error); ?></div>
                <?php endif; ?>
                <?php if (!empty($form_success)): ?>
                    <div class="alert-success"><?php echo htmlspecialchars($form_success); ?></div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?consult_id=<?php echo urlencode($consult_id_from_url); ?>" method="post" autocomplete="off" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_files">
                    <input type="hidden" name="consult_id" value="<?php echo htmlspecialchars($consult_id_from_url); ?>">

                    <h3>Hasil Konsultasi</h3>
                    <div class="file-upload-area" id="hasil_konsultasi_drop_area">
                        <input type="file" name="hasil_konsultasi_file" id="hasil_konsultasi_file" accept=".pdf,image/*">
                        <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                        <p>Drag or Drop your file here</p>
                        <label for="hasil_konsultasi_file" class="choose-file-btn">Choose File</label>
                        <span class="file-name-display" id="hasil_konsultasi_file_name"
                              data-original-file-name="<?php echo htmlspecialchars($order_data['HasilConsult'] ? basename($order_data['HasilConsult']) : ''); ?>">
                            <?php echo htmlspecialchars($order_data['HasilConsult'] ? basename($order_data['HasilConsult']) : 'Tidak ada file'); ?>
                        </span>
                    </div>
                    <?php if (!empty($upload_errors) && isset($upload_errors['hasil_konsultasi_file'])): ?>
                        <?php foreach($upload_errors['hasil_konsultasi_file'] as $err_msg): ?>
                            <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <hr>

                    <h3>Bukti Pembayaran</h3>
                    <div class="flex-row">
                        <div class="file-upload-area" id="pembayaran_dp_drop_area">
                            <p>Pembayaran DP</p>
                            <input type="file" name="PembayaranDP" id="PembayaranDP" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="PembayaranDP" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="pembayaran_dp_file_name"
                                  data-original-file-name="<?php echo htmlspecialchars($order_data['PembayaranDP'] ? basename($order_data['PembayaranDP']) : ''); ?>">
                                <?php echo htmlspecialchars($order_data['PembayaranDP'] ? basename($order_data['PembayaranDP']) : 'Tidak ada file'); ?>
                            </span>
                        </div>
                        <div class="file-upload-area" id="pembayaran_lunas_drop_area">
                            <p>Pembayaran Lunas</p>
                            <input type="file" name="BayarLunas" id="BayarLunas" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="BayarLunas" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="pembayaran_lunas_file_name"
                                  data-original-file-name="<?php echo htmlspecialchars($order_data['BayarLunas'] ? basename($order_data['BayarLunas']) : ''); ?>">
                                <?php echo htmlspecialchars($order_data['BayarLunas'] ? basename($order_data['BayarLunas']) : 'Tidak ada file'); ?>
                            </span>
                        </div>
                    </div>
                    <?php if (!empty($upload_errors) && (isset($upload_errors['pembayaran_umum']) || isset($upload_errors['PembayaranDP']) || isset($upload_errors['BayarLunas']))): ?>
                        <?php if (isset($upload_errors['pembayaran_umum'])): ?>
                            <p class="error-message"><?php echo htmlspecialchars($upload_errors['pembayaran_umum'][0]); ?></p>
                        <?php endif; ?>
                        <?php foreach($upload_errors['PembayaranDP'] ?? [] as $err_msg): ?>
                            <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                        <?php endforeach; ?>
                        <?php foreach($upload_errors['BayarLunas'] ?? [] as $err_msg): ?>
                            <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="button-container">
                        <button type="submit">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function setupFileUpload(dropAreaId, fileInputId, fileNameDisplayId, initialFileName, isPaymentSection = false) {
                const dropArea = document.getElementById(dropAreaId);
                const fileInput = document.getElementById(fileInputId);
                const fileNameDisplay = document.getElementById(fileNameDisplayId);
                const uploadIcon = dropArea.querySelector('.upload-icon');
                const dragDropText = dropArea.querySelector('p:nth-of-type(2)');
                const chooseFileBtn = dropArea.querySelector('label.choose-file-btn');
                const paymentLabel = dropArea.querySelector('p:first-child');

                const updateDisplay = (hasFile, fileName = '') => {
                    if (hasFile) {
                        dropArea.classList.add('file-chosen');
                        if (uploadIcon) uploadIcon.style.display = 'block'; 
                        if (dragDropText) dragDropText.style.display = 'none';
                        if (chooseFileBtn) chooseFileBtn.style.display = 'none';
                        fileNameDisplay.textContent = fileName;
                        fileNameDisplay.style.display = 'block';
                        if (isPaymentSection && paymentLabel) {
                            paymentLabel.style.display = 'none';
                        }
                    } else {
                        dropArea.classList.remove('file-chosen');
                        fileNameDisplay.textContent = 'Tidak ada file'; 
                        fileNameDisplay.style.display = 'none';
                        if (uploadIcon) uploadIcon.style.display = 'block';
                        if (dragDropText) dragDropText.style.display = 'block';
                        if (chooseFileBtn) chooseFileBtn.style.display = 'inline-block';
                        if (isPaymentSection && paymentLabel) {
                            paymentLabel.style.display = 'block';
                        }
                    }
                };

                if (initialFileName && initialFileName !== 'Tidak ada file') {
                    updateDisplay(true, initialFileName);
                } else {
                    updateDisplay(false);
                }

                dropArea.addEventListener('click', (e) => {
                    if (e.target !== fileNameDisplay && e.target !== chooseFileBtn && e.target !== paymentLabel) {
                        fileInput.click();
                    }
                });

                fileInput.addEventListener('change', (event) => {
                    if (event.target.files.length > 0) {
                        updateDisplay(true, event.target.files[0].name);
                    } else {
                        const originalFileName = fileNameDisplay.dataset.originalFileName;
                        if (originalFileName && originalFileName !== '') {
                            updateDisplay(true, originalFileName);
                        } else {
                            updateDisplay(false);
                        }
                    }
                });

                // Drag and drop events
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, preventDefaults, false);
                });

                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                ['dragenter', 'dragover'].forEach(eventName => {
                    dropArea.addEventListener(eventName, () => dropArea.classList.add('highlight'), false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, () => dropArea.classList.remove('highlight'), false);
                });

                dropArea.addEventListener('drop', (e) => {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    fileInput.files = files; 
                    if (files.length > 0) {
                        updateDisplay(true, files[0].name);
                    } else {
                        updateDisplay(false);
                    }
                }, false);
            }

            // Setup for each upload area
            setupFileUpload('hasil_konsultasi_drop_area', 'hasil_konsultasi_file', 'hasil_konsultasi_file_name', '<?php echo htmlspecialchars($order_data['HasilConsult'] ? basename($order_data['HasilConsult']) : ''); ?>');
            setupFileUpload('pembayaran_dp_drop_area', 'PembayaranDP', 'pembayaran_dp_file_name', '<?php echo htmlspecialchars($order_data['PembayaranDP'] ? basename($order_data['PembayaranDP']) : ''); ?>', true); 
            setupFileUpload('pembayaran_lunas_drop_area', 'BayarLunas', 'pembayaran_lunas_file_name', '<?php echo htmlspecialchars($order_data['BayarLunas'] ? basename($order_data['BayarLunas']) : ''); ?>', true);
        });
    </script>
</body>
</html>