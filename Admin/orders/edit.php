<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

// Pastikan admin sudah login
checkAdminLogin();

// --- PENGATURAN DEBUGGING (AKTIFKAN SAAT PENGEMBANGAN, NONAKTIFKAN DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Inisialisasi variabel untuk tampilan
$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$form_success = '';
$form_error = '';
$upload_errors = []; 
$data_errors = []; 

// --- DEFINISI GLOBAL UNTUK TIPE FILE DAN UKURAN MAKSIMAL ---
$allowed_file_types = ['application/pdf', 'image/jpeg', 'image/png'];
$max_file_size = 5 * 1024 * 1024; // 5 MB

// Path dasar untuk upload file. Ini harus relatif terhadap root server web Anda.
define('UPLOAD_BASE_DIR_PHYSICAL', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR);

define('UPLOAD_BASE_DIR_URL', '/public/files/');

// Ambil ID order dari URL
$consult_id_from_url = filter_var(isset($_GET['IdConsult']) ? $_GET['IdConsult'] : null, FILTER_VALIDATE_INT);

// --- AMBIL DATA ORDER DARI DATABASE ---
$order_data = null;
if ($consult_id_from_url) {
    global $conn;

    $sql_get_order = "SELECT
                        c.IdCust, c.Nama AS customer_name, c.Email AS customer_email, c.NoTelp AS customer_phone,
                        c.NamaUsaha AS nama_usaha, c.BidangUsaha AS bidang_usaha, c.Lokasi AS lokasi_usaha,
                        c.JenisLayanan AS jenis_layanan, c.Harga AS customer_price,
                        con.IdConsult, con.HasilConsult, con.CatatanRevisi, con.Revisi1, con.Revisi2,
                        con.PembayaranDP, con.BayarLunas,
                        con.StatusPengerjaan AS status_pengerjaan_db, con.StatusPembayaran AS status_pembayaran_db,
                        con.HasilPengerjaan AS hasil_pengerjaan_db, con.created_at, con.updated_at
                      FROM `consult` AS con
                      JOIN `customer` AS c ON con.IdCust = c.IdCust
                      WHERE con.IdConsult = ?";

    if ($stmt_get_order = $conn->prepare($sql_get_order)) {
        $stmt_get_order->bind_param('i', $consult_id_from_url);
        $stmt_get_order->execute();
        $result = $stmt_get_order->get_result();
        
        if ($result->num_rows > 0) {
            $order_data = $result->fetch_assoc();
            // Konversi nilai '0', '0.00', atau string kosong dari DB menjadi null untuk konsistensi path file
            foreach (['HasilConsult', 'Revisi1', 'Revisi2', 'PembayaranDP', 'BayarLunas', 'HasilPengerjaan'] as $field) {
                if (isset($order_data[$field]) && (empty($order_data[$field]) || $order_data[$field] === '0' || $order_data[$field] === '0.00')) {
                    $order_data[$field] = null;
                }
            }
        }
        $stmt_get_order->close();
    } else {
        $form_error = "Kesalahan persiapan query pengambilan data order: " . $conn->error;
    }
}

// --- PENANGANAN JIKA consult_id TIDAK DITEMUKAN ATAU TIDAK VALID ---
if (!$consult_id_from_url || !$order_data) {
    $form_error = "Order Konsultasi tidak ditemukan atau ID tidak valid.";
    // Inisialisasi $order_data agar HTML tidak error "Undefined index"
    $order_data = [
        'IdCust' => null, 'customer_name' => '', 'customer_email' => '', 'customer_phone' => '',
        'nama_usaha' => '', 'bidang_usaha' => '', 'lokasi_usaha' => '', 'jenis_layanan' => '', 'customer_price' => '',
        'IdConsult' => null, 'HasilConsult' => null, 'CatatanRevisi' => null, 'Revisi1' => null, 'Revisi2' => null,
        'PembayaranDP' => null, 'BayarLunas' => null,
        'status_pengerjaan_db' => '', 'status_pembayaran_db' => '', 'hasil_pengerjaan_db' => null,
        'created_at' => null, 'updated_at' => null
    ];
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        header('location: ' . BASE_URL . '/admin/orders/index.php?error=' . urlencode($form_error));
        exit;
    }
}

/**
 * Fungsi helper untuk menangani proses upload file.
 * Mengembalikan path URL relatif jika berhasil, atau null jika gagal/tidak ada upload.
 * Menambahkan pesan error ke global $upload_errors array.
 *
 * @param string $file_input_html_name Nama atribut 'name' dari input file HTML.
 * @param string $upload_sub_dir Nama sub-direktori di dalam public/files/ (misal: 'HasilConsult', 'Revisi1', 'PembayaranDP').
 * @param string $prefix Prefix untuk nama file yang disimpan (misal: 'konsultasi', 'revisi1', 'dp', 'lunas').
 * @param int $consult_id ID Konsultasi untuk penamaan file.
 * @param int $customer_id ID Customer untuk penamaan file.
 * @param string $customer_name Nama Customer untuk penamaan file.
 * @param string|null $existing_db_path Path file yang sudah ada di database (URL relatif).
 * @return string|null Path file URL relatif yang baru diupload atau path lama jika tidak ada upload/gagal.
 */
function handle_upload($file_input_html_name, $upload_sub_dir, $prefix, $consult_id, $customer_id, $customer_name, $existing_db_path) {
    global $upload_errors, $allowed_file_types, $max_file_size;

    $uploaded_file_path = $existing_db_path; // Default: pertahankan path yang sudah ada dari DB

    error_log("--- Mulai handle_upload untuk: " . $file_input_html_name . " ---");
    error_log("Existing DB Path: " . ($existing_db_path ?? 'NULL'));

    if (isset($_FILES[$file_input_html_name]) && $_FILES[$file_input_html_name]['error'] != UPLOAD_ERR_NO_FILE) {
        $file_error_code = $_FILES[$file_input_html_name]['error'];
        error_log("File Error Code for {$file_input_html_name}: " . $file_error_code);

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
            return $uploaded_file_path;
        }

        $file_tmp_name = $_FILES[$file_input_html_name]['tmp_name'];
        $file_name_original = basename($_FILES[$file_input_html_name]['name']);
        $file_size = $_FILES[$file_input_html_name]['size'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_type = finfo_file($finfo, $file_tmp_name);
        finfo_close($finfo);

        $file_ext = strtolower(pathinfo($file_name_original, PATHINFO_EXTENSION));

        $clean_customer_name = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $customer_name));
        $id_cust_for_filename = ($customer_id) ? $customer_id : 'NO_CUST_ID';
        $new_file_name = $prefix . '_' . $consult_id . '_' . $id_cust_for_filename . '_' . $clean_customer_name . '.' . $file_ext;
        
        $full_upload_dir_physical = UPLOAD_BASE_DIR_PHYSICAL . $upload_sub_dir . DIRECTORY_SEPARATOR;
        $destination_physical = $full_upload_dir_physical . $new_file_name;
        // UPLOAD_BASE_DIR_URL sudah disederhanakan, jadi ini akan selalu benar
        $relative_url_path = UPLOAD_BASE_DIR_URL . $upload_sub_dir . '/' . $new_file_name; 

        error_log("Target physical directory: " . $full_upload_dir_physical);
        error_log("Destination physical path: " . $destination_physical);
        error_log("Relative URL path for DB: " . $relative_url_path);

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
            
            // Hapus file lama jika ada dan berbeda dengan yang baru diupload
            $old_file_full_path_physical = '';
            if (!empty($existing_db_path)) {
                // Perbaiki path dari URL DB ke path fisik yang benar
                // Gunakan parse_url untuk mendapatkan path, lalu hapus BASE_URL,
                // dan gabungkan dengan UPLOAD_BASE_DIR_PHYSICAL
                $parsed_url_path = parse_url($existing_db_path, PHP_URL_PATH);
                if ($parsed_url_path !== false && $parsed_url_path !== null) {
                    // Coba hapus BASE_URL dari awal path URL
                    $clean_url_path = str_replace(BASE_URL, '', $parsed_url_path);
                    // Pastikan path fisik dimulai dari root folder public/files
                    $old_file_full_path_physical = UPLOAD_BASE_DIR_PHYSICAL . ltrim($clean_url_path, '/public/files/');
                }
            }
            
            if (!empty($old_file_full_path_physical) && file_exists($old_file_full_path_physical) && $old_file_full_path_physical !== $destination_physical) {
                if (unlink($old_file_full_path_physical)) {
                    error_log("SUCCESS: File lama '{$old_file_full_path_physical}' berhasil dihapus.");
                } else {
                    error_log("WARNING: Gagal menghapus file lama '{$old_file_full_path_physical}'.");
                }
            }

            // Pindahkan file yang diupload dari direktori temp ke tujuan akhir
            if (move_uploaded_file($file_tmp_name, $destination_physical)) {
                $uploaded_file_path = $relative_url_path; // Path yang disimpan di DB (relatif terhadap BASE_URL)
                error_log("SUCCESS: File '{$file_name_original}' berhasil diunggah ke '{$uploaded_file_path}'.");
            } else {
                $upload_errors[$file_input_html_name][] = "Gagal memindahkan file. Ini bisa jadi masalah izin, jalur tujuan, atau file sementara hilang. Error PHP: " . (error_get_last()['message'] ?? 'Tidak diketahui');
                error_log("FAILED: move_uploaded_file failed for {$file_input_html_name}. PHP Error: " . (error_get_last()['message'] ?? 'N/A'));
            }
        }
    }
    error_log("--- Selesai handle_upload untuk: " . $file_input_html_name . ", Final Path: " . ($uploaded_file_path ?? 'NULL') . " ---");
    return $uploaded_file_path;
}


// --- PENANGANAN POST REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_order') {
    global $conn, $order_data, $upload_errors, $data_errors;

    $current_consult_id = (int)sanitize_input($_POST['IdConsult']);
    $customer_id = $order_data['IdCust'] ?? 0;
    $customer_name = $order_data['customer_name'] ?? 'Unknown Customer';

    // --- Ambil data dari form ---
    $customer_name_new = sanitize_input($_POST['customer_name'] ?? '');
    $customer_phone_new = sanitize_input($_POST['customer_phone'] ?? '');
    $customer_email_new = filter_var($_POST['customer_email'] ?? '', FILTER_SANITIZE_EMAIL);
    $nama_usaha_new = sanitize_input($_POST['nama_usaha'] ?? '');
    $bidang_usaha_new = sanitize_input($_POST['bidang_usaha'] ?? '');
    $lokasi_usaha_new = sanitize_input($_POST['lokasi_usaha'] ?? '');
    $jenis_layanan_new = sanitize_input($_POST['jenis_layanan'] ?? '');
    $customer_price_new = filter_var($_POST['customer_price'] ?? 0, FILTER_VALIDATE_FLOAT);
    $catatan_revisi_new = sanitize_input($_POST['catatan_revisi'] ?? '');
    $status_pengerjaan_new = sanitize_input($_POST['status_pengerjaan'] ?? '');
    // Initialize with current DB value or default if not set in POST (important for auto-update logic)
    $status_pembayaran_new = sanitize_input($_POST['status_pembayaran'] ?? $order_data['status_pembayaran_db'] ?? ''); 

    // --- Validasi Data Form (selain file) ---
    if (empty($customer_name_new)) $data_errors['customer_name'] = "Nama Pelanggan wajib diisi.";
    if (empty($customer_phone_new)) $data_errors['customer_phone'] = "Nomor Telepon wajib diisi.";
    if (empty($customer_email_new) || !filter_var($customer_email_new, FILTER_VALIDATE_EMAIL)) $data_errors['customer_email'] = "Email tidak valid.";
    if (empty($nama_usaha_new)) $data_errors['nama_usaha'] = "Nama Usaha wajib diisi.";
    // Bidang Usaha dan Lokasi Usaha tidak required berdasarkan wireframe, jadi tidak perlu validasi empty
    if (empty($jenis_layanan_new)) $data_errors['jenis_layanan'] = "Jenis Layanan wajib diisi.";
    if ($customer_price_new === false || $customer_price_new < 0) $data_errors['customer_price'] = "Harga tidak valid.";


    // --- Proses File Upload (HasilConsult, Revisi1, Revisi2, PembayaranDP, BayarLunas) ---
    // Inisialisasi path yang akan diupdate ke DB dengan nilai yang sudah ada dari DB
    $new_hasil_konsultasi_path = $order_data['HasilConsult'];
    $new_revisi1_path = $order_data['Revisi1'];
    $new_revisi2_path = $order_data['Revisi2'];
    $new_pembayaran_dp_path = $order_data['PembayaranDP'];    
    $new_pembayaran_lunas_path = $order_data['BayarLunas'];    
    // Assuming 'HasilPengerjaan' also has a file, if so, initialize it here
    $new_hasil_pengerjaan_path = $order_data['hasil_pengerjaan_db']; // Assuming this field exists and is for files

    // Handle Hasil Konsultasi
    $uploaded_hasil_konsultasi = handle_upload(
        'hasil_konsultasi_file', 'HasilConsult', 'konsultasi',
        $current_consult_id, $customer_id, $customer_name, $order_data['HasilConsult']
    );
    if ($uploaded_hasil_konsultasi !== null && (isset($_FILES['hasil_konsultasi_file']) && $_FILES['hasil_konsultasi_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        if (empty($upload_errors['hasil_konsultasi_file'])) {    
            $new_hasil_konsultasi_path = $uploaded_hasil_konsultasi;
        }
    } else if (isset($_FILES['hasil_konsultasi_file']) && $_FILES['hasil_konsultasi_file']['error'] == UPLOAD_ERR_NO_FILE) {
        $new_hasil_konsultasi_path = $order_data['HasilConsult'];
    }


    // Handle Revisi 1
    $uploaded_revisi1 = handle_upload(
        'revisi1_file', 'Revisi1', 'revisi1',
        $current_consult_id, $customer_id, $customer_name, $order_data['Revisi1']
    );
    if ($uploaded_revisi1 !== null && (isset($_FILES['revisi1_file']) && $_FILES['revisi1_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        if (empty($upload_errors['revisi1_file'])) {
            $new_revisi1_path = $uploaded_revisi1;
        }
    } else if (isset($_FILES['revisi1_file']) && $_FILES['revisi1_file']['error'] == UPLOAD_ERR_NO_FILE) {
        $new_revisi1_path = $order_data['Revisi1'];
    }


    // Handle Revisi 2
    $uploaded_revisi2 = handle_upload(
        'revisi2_file', 'Revisi2', 'revisi2',
        $current_consult_id, $customer_id, $customer_name, $order_data['Revisi2']
    );
    if ($uploaded_revisi2 !== null && (isset($_FILES['revisi2_file']) && $_FILES['revisi2_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        if (empty($upload_errors['revisi2_file'])) {
            $new_revisi2_path = $uploaded_revisi2;
        }
    } else if (isset($_FILES['revisi2_file']) && $_FILES['revisi2_file']['error'] == UPLOAD_ERR_NO_FILE) {
        $new_revisi2_path = $order_data['Revisi2'];
    }

    // Handle Pembayaran DP (nama folder masih 'BayarDP' agar tidak perlu migrasi folder)
    $uploaded_pembayaran_dp = handle_upload(
        'pembayaran_dp_file', 'BayarDP', 'dp', // Nama folder tetap 'BayarDP'
        $current_consult_id, $customer_id, $customer_name, $order_data['PembayaranDP']
    );
    if ($uploaded_pembayaran_dp !== null && (isset($_FILES['pembayaran_dp_file']) && $_FILES['pembayaran_dp_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        if (empty($upload_errors['pembayaran_dp_file'])) {
            $new_pembayaran_dp_path = $uploaded_pembayaran_dp;
        }
    } else if (isset($_FILES['pembayaran_dp_file']) && $_FILES['pembayaran_dp_file']['error'] == UPLOAD_ERR_NO_FILE) {
        $new_pembayaran_dp_path = $order_data['PembayaranDP'];
    }

    // Handle Pembayaran Lunas (nama folder masih 'BayarLunas' agar tidak perlu migrasi folder)
    $uploaded_pembayaran_lunas = handle_upload(
        'pembayaran_lunas_file', 'BayarLunas', 'lunas', // Nama folder tetap 'BayarLunas'
        $current_consult_id, $customer_id, $customer_name, $order_data['BayarLunas']
    );
    if ($uploaded_pembayaran_lunas !== null && (isset($_FILES['pembayaran_lunas_file']) && $_FILES['pembayaran_lunas_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        if (empty($upload_errors['pembayaran_lunas_file'])) {
            $new_pembayaran_lunas_path = $uploaded_pembayaran_lunas;
        }
    } else if (isset($_FILES['pembayaran_lunas_file']) && $_FILES['pembayaran_lunas_file']['error'] == UPLOAD_ERR_NO_FILE) {
        $new_pembayaran_lunas_path = $order_data['BayarLunas'];
    }

    // Handle Hasil Pengerjaan (assuming there's a file field for this)
    $uploaded_hasil_pengerjaan = handle_upload(
        'hasil_pengerjaan_file', 'HasilPengerjaan', 'pengerjaan', // Assuming a folder 'HasilPengerjaan'
        $current_consult_id, $customer_id, $customer_name, $order_data['hasil_pengerjaan_db']
    );
    if ($uploaded_hasil_pengerjaan !== null && (isset($_FILES['hasil_pengerjaan_file']) && $_FILES['hasil_pengerjaan_file']['error'] != UPLOAD_ERR_NO_FILE)) {
        if (empty($upload_errors['hasil_pengerjaan_file'])) {
            $new_hasil_pengerjaan_path = $uploaded_hasil_pengerjaan;
        }
    } else if (isset($_FILES['hasil_pengerjaan_file']) && $_FILES['hasil_pengerjaan_file']['error'] == UPLOAD_ERR_NO_FILE) {
        $new_hasil_pengerjaan_path = $order_data['hasil_pengerjaan_db'];
    }


    // --- AUTO-UPDATE STATUS PEMBAYARAN JIKA BUKTI LUNAS DIUNGGAH ---
    if (!empty($new_pembayaran_lunas_path) && !isset($upload_errors['pembayaran_lunas_file'])) {
        $status_pembayaran_new = 'Lunas';
    }

    // Cek apakah ada error upload atau validasi data
    if (!empty($upload_errors) || !empty($data_errors)) {
        $full_error_message = '';
        if (!empty($data_errors)) {
            $full_error_message .= "Kesalahan validasi data:<br>" . implode("<br>", $data_errors) . "<br>";
        }
        if (!empty($upload_errors)) {
            $full_error_message .= "Terdapat kesalahan saat mengunggah file:<br>";
            foreach ($upload_errors as $field => $messages) {
                if (!empty($messages)) {
                    $display_field_name = str_replace(['_file'], [''], $field);
                    // Mengganti tampilan nama field untuk pesan error agar sesuai dengan perubahan nama field
                    $display_field_name = str_replace(['bayardp', 'bayarlunas', 'hasilpengerjaan'], ['Pembayaran DP', 'Pembayaran Lunas', 'Hasil Pengerjaan'], $display_field_name);
                    $full_error_message .= "- " . htmlspecialchars(ucfirst(str_replace('_', ' ', $display_field_name))) . ": " . implode("<br>- ", $messages) . "<br>";
                }
            }
        }
        $form_error = $full_error_message;

        // Redirect kembali dengan error
        header('location: ' . BASE_URL . '/admin/orders/edit.php?IdConsult=' . urlencode($current_consult_id) . '&error=' . urlencode($form_error));
        exit;

    } else {
        // Update tabel customer
        $sql_update_customer = "UPDATE `customer` SET `Nama` = ?, `NoTelp` = ?, `Email` = ?, `NamaUsaha` = ?, `BidangUsaha` = ?, `Lokasi` = ?, `JenisLayanan` = ?, `Harga` = ? WHERE `IdCust` = ?";
        if ($stmt_customer = $conn->prepare($sql_update_customer)) {
            $stmt_customer->bind_param(
                'sssssssdi',
                $customer_name_new, $customer_phone_new, $customer_email_new, $nama_usaha_new,
                $bidang_usaha_new, $lokasi_usaha_new, $jenis_layanan_new, $customer_price_new, $customer_id
            );
            $stmt_customer->execute();
            $stmt_customer->close();
        } else {
            $form_error = "Kesalahan persiapan update customer: " . $conn->error;
            header('location: ' . BASE_URL . '/admin/orders/edit.php?IdConsult=' . urlencode($current_consult_id) . '&error=' . urlencode($form_error));
            exit;
        }

        // Update tabel consult
        $sql_update_consult_parts = [];
        $update_params = [];
        $update_types = '';

        // Only add to update if path file changed or is newly set
        if (($new_hasil_konsultasi_path ?? '') !== ($order_data['HasilConsult'] ?? '')) {
            $sql_update_consult_parts[] = "`HasilConsult` = ?";
            $update_params[] = &$new_hasil_konsultasi_path;
            $update_types .= 's';
        }
        if (($new_revisi1_path ?? '') !== ($order_data['Revisi1'] ?? '')) {
            $sql_update_consult_parts[] = "`Revisi1` = ?";
            $update_params[] = &$new_revisi1_path;
            $update_types .= 's';
        }
        if (($new_revisi2_path ?? '') !== ($order_data['Revisi2'] ?? '')) {
            $sql_update_consult_parts[] = "`Revisi2` = ?";
            $update_params[] = &$new_revisi2_path;
            $update_types .= 's';
        }
        if (($new_pembayaran_dp_path ?? '') !== ($order_data['PembayaranDP'] ?? '')) {
            $sql_update_consult_parts[] = "`PembayaranDP` = ?";
            $update_params[] = &$new_pembayaran_dp_path;
            $update_types .= 's';
        }
        if (($new_pembayaran_lunas_path ?? '') !== ($order_data['BayarLunas'] ?? '')) {
            $sql_update_consult_parts[] = "`BayarLunas` = ?";
            $update_params[] = &$new_pembayaran_lunas_path;
            $update_types .= 's';
        }
        if (($new_hasil_pengerjaan_path ?? '') !== ($order_data['hasil_pengerjaan_db'] ?? '')) { // Added for HasilPengerjaan
            $sql_update_consult_parts[] = "`HasilPengerjaan` = ?";
            $update_params[] = &$new_hasil_pengerjaan_path;
            $update_types .= 's';
        }
        
        // Catatan revisi dan status pengerjaan selalu diupdate
        $sql_update_consult_parts[] = "`CatatanRevisi` = ?";
        $update_params[] = &$catatan_revisi_new;
        $update_types .= 's';

        $sql_update_consult_parts[] = "`StatusPengerjaan` = ?";
        $update_params[] = &$status_pengerjaan_new;
        $update_types .= 's';
        
        // Status pembayaran (now includes auto-update logic)
        $sql_update_consult_parts[] = "`StatusPembayaran` = ?";
        $update_params[] = &$status_pembayaran_new;
        $update_types .= 's';


        // Tambahkan updated_at
        $updated_at_value = date('Y-m-d H:i:s');
        $sql_update_consult_parts[] = "`updated_at` = ?";    
        $update_params[] = &$updated_at_value;
        $update_types .= 's';

        if (empty($sql_update_consult_parts)) {
               $form_success = 'Tidak ada perubahan data order untuk diperbarui.';
               header('location: ' . BASE_URL . '/admin/orders/index.php?success=true');
               exit;
        }

        $sql_update_consult_str = "UPDATE `consult` SET " . implode(', ', $sql_update_consult_parts) . " WHERE `IdConsult` = ?";
        $update_types .= 'i'; // For IdConsult
        $update_params[] = &$current_consult_id;

        $stmt_consult = null;
        try {
            if ($stmt_consult = $conn->prepare($sql_update_consult_str)) {
                call_user_func_array([$stmt_consult, 'bind_param'], array_merge([$update_types], $update_params));
                if ($stmt_consult->execute()) {
                    $form_success = 'Data order dan file berhasil diperbarui!';
                    header('location: ' . BASE_URL . '/admin/orders/index.php?success=true');
                    exit;
                } else {
                    throw new Exception('Gagal memperbarui data konsultasi (eksekusi): ' . $stmt_consult->error);
                }
            } else {
                throw new Exception('Kesalahan persiapan query update konsultasi: ' . $conn->error);
            }
        } catch (Exception $e) {
            $form_error = "Terjadi error saat update konsultasi: " . $e->getMessage();
            header('location: ' . BASE_URL . '/admin/orders/edit.php?IdConsult=' . urlencode($current_consult_id) . '&error=' . urlencode($form_error));
            exit;
        } finally {
            if ($stmt_consult instanceof mysqli_stmt) {
                $stmt_consult->close();
            }
        }
    }
}

// Tangani pesan success/error dari redirect GET
if (isset($_GET['success'])) {
    $form_success = 'Data berhasil diperbarui!';
} elseif (isset($_GET['error'])) {
    $form_error = sanitize_input($_GET['error']);
}

// Helper function to get base filename from URL, handling cases where it's null or empty
function get_filename_from_url($url) {
    if (empty($url)) {
        return '';
    }
    return basename(parse_url($url, PHP_URL_PATH));
}

function get_display_file_url($db_url_path) {
    if (empty($db_url_path)) {
        return '';
    }

    // Jika path dari DB sudah dimulai dengan BASE_URL, berarti sudah URL absolut yang benar.
    if (strpos($db_url_path, BASE_URL) === 0) {
        return $db_url_path;
    }
    
    if (strpos($db_url_path, '/') === 0) {
        return rtrim(BASE_URL, '/') . $db_url_path;
    }

    return BASE_URL . '/' . ltrim($db_url_path, '/');
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Order</title>
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
        margin: 20px 10px 10px 10px; 
    }

    /* Gaya untuk input, select, textarea di dalam form-group */
    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group input[type="tel"],
    .form-group input[type="number"],
    .form-group textarea,
    .form-group select {
        width: calc(100% - 20px); 
        padding: 12px 14px;
        margin: 10px; 
        border: 1px solid #e0e0e0;
        border-radius: 10px;
        background-color: #f9f9f9;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        transition: box-shadow 0.3s ease-in, border-color 0.3s ease-in;
        font-size: 13px;
        font-weight: 500; 
        color: #4b5563;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
    }

    /* Label di atas input */
    .form-group label {
        display: block;
        margin-left: 10px; 
        margin-bottom: 5px;
        font-weight: 500; 
        color: #4A5568;
        font-size: 14px; 
    }


    input::placeholder,
    textarea::placeholder {
        color: #808080;
        font-weight: 400;
    }

    form input:focus,
    form select:focus,
    form textarea:focus {
        outline: none;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
        border-color: #3b82f6;
    }

    /* Gaya untuk option di dalam select */
    .form-group select option {
        color: #4b5563;
        background-color: white;
        font-weight: 400; 
    }
    .form-group select option[value=""]:disabled {
        color: #808080;
    }
    .form-group select:invalid {
        color: #808080;
    }
    .form-group select:valid {
        color: #4b5563;
    }

    /* Form Layout Grouping */
    form {
        display: flex;
        flex-direction: column;
    }

    .form-group {
        margin-bottom: 15px; 
    }

    .form-group textarea {
        min-height: 80px;
        height: 100px;
        resize: vertical;
    }

    .flex-row-input {
        display: flex;
        gap: 10px;
        margin-left: 10px;    
        margin-right: 10px;
        margin-bottom: 15px;
    }
    .flex-row-input .form-group {
        flex: 1;
        margin-bottom: 0; 
    }
    /* Override margin untuk input di dalam flex-row-input */
    .flex-row-input .form-group input,
    .flex-row-input .form-group select,
    .flex-row-input .form-group textarea {
        width: 100%;
        margin: 0; 
    }
    .flex-row-input .form-group label {
        margin-left: 0; 
    }

    /* Custom panah untuk select (di dalam form-group dan flex-row-input) */
    .form-group .select-wrapper {
        position: relative;
        width: 100%;
    }
    .form-group .select-wrapper select {
        width: 100%;
        padding-right: 30px;
        margin: 0;
    }
    .form-group .select-wrapper::after {
        content: '\f078';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #808080;
        pointer-events: none;
        font-size: 10px;
    }

    form hr {
        margin: 20px 0px;
        border: none;
        border-top: 1px solid #c7c7c7;
    }

    .button-container {
        display: flex;
        justify-content: center;
        margin-top: 20px;
    }

    button[type="submit"] {
        width: 50%;
        padding: 12px 14px;
        border-radius: 20px;
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
        font-weight: 400; 
    }
    .alert-error, .alert-success {
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 500; 
    }
    .alert-error {
        background-color: #fee2e2;
        border: 1px solid #ef4444;
        color: #dc2626;
    }
    .alert-success {
        background-color: #D1FAE5;
        border: 1px solid #10B981;
        color: #065F46;
    }

    /* File Upload Area */
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
        font-weight: 400;
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
        font-weight: 500; 
    }

    .file-chosen .upload-icon,
    .file-chosen p, 
    .file-chosen .choose-file-btn {
        display: none !important;
    }
    .file-chosen .file-name-display {
        display: block !important;
    }

    .existing-file-info {
        font-size: 0.85rem;
        color: #3B82F6; 
        margin-top: 5px;
        margin-left: 10px;
        display: block;
        font-weight: 500;
        text-decoration: underline;
        word-break: break-all;
    }
    .existing-file-info:hover {
        color: #2563EB; 
    }
    .no-existing-file {
        font-size: 0.85rem;
        color: #6B7280;
        margin-top: 5px;
        margin-left: 10px;
        display: block;
        font-weight: 400;
    }

    @media (max-width: 768px) {
        .form-container {
            max-width: 95%;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: calc(100% - 20px);    
        }
        .flex-row-input {
            flex-direction: column;
            gap: 0;    
            margin-left: 10px;
            margin-right: 10px;
        }
        .flex-row-input .form-group {
            width: 100%;    
            margin-bottom: 15px;
        }
        .flex-row-input .form-group input,
        .flex-row-input .form-group select,
        .flex-row-input .form-group textarea {
            margin: 0; /* Hapus margin internal */
        }
        .form-group label {
            margin-left: 0;
        }
        .form-body h3 {
            margin-left: 10px;
            margin-right: 10px;
        }
        .file-upload-area {
            margin-left: 10px;
            margin-right: 10px;
        }
        .error-message, .existing-file-info, .no-existing-file {
            margin-left: 10px;
        }
    }
</style>
</head>

<body>
    <div class="overlay"></div>
    
    <div class="form-header">
        <a href="<?php echo BASE_URL; ?>/admin/orders/index.php" class="back-arrow"><i class="fas fa-chevron-left"></i></a>
        <div class="logo">
            <img src="<?php echo BASE_URL; ?>/public/Logo/Logo2.png" alt="ExpertUs">
        </div>
    </div>
    
    <div class="wrapper">
        <div class="form-container">
            <div class="form-body">
                <h2>Edit Order</h2>

                <?php if (!empty($form_error)): ?>
                    <div class="alert-error"><?php echo htmlspecialchars($form_error); ?></div>
                <?php endif; ?>
                <?php if (!empty($form_success)): ?>
                    <div class="alert-success"><?php echo htmlspecialchars($form_success); ?></div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?IdConsult=<?php echo urlencode($consult_id_from_url); ?>" method="post" autocomplete="off" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_order">
                    <input type="hidden" name="IdConsult" value="<?php echo htmlspecialchars($consult_id_from_url); ?>">
                    <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($order_data['IdCust'] ?? ''); ?>">

                    <h3>Customer Details</h3>
                    <div class="form-group">
                        <label for="customer_name">Nama Pelanggan</label>
                        <input type="text" name="customer_name" id="customer_name" placeholder="Nama Pelanggan" value="<?php echo htmlspecialchars($order_data['customer_name'] ?? ''); ?>" required>
                        <?php if (isset($data_errors['customer_name'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['customer_name']) . '</p>'; ?>
                    </div>
                    <div class="flex-row-input">
                        <div class="form-group">
                            <label for="customer_phone">No. Telepon</label>
                            <input type="tel" name="customer_phone" id="customer_phone" placeholder="No. Telepon" value="<?php echo htmlspecialchars($order_data['customer_phone'] ?? ''); ?>" required>
                            <?php if (isset($data_errors['customer_phone'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['customer_phone']) . '</p>'; ?>
                        </div>
                        <div class="form-group">
                            <label for="customer_email">Email</label>
                            <input type="email" name="customer_email" id="customer_email" placeholder="Email" value="<?php echo htmlspecialchars($order_data['customer_email'] ?? ''); ?>" required>
                            <?php if (isset($data_errors['customer_email'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['customer_email']) . '</p>'; ?>
                        </div>
                    </div>

                    <h3>Additional Information</h3>
                    <div class="form-group">
                        <label for="nama_usaha">Nama Usaha</label>
                        <input type="text" name="nama_usaha" id="nama_usaha" placeholder="Nama Usaha" value="<?php echo htmlspecialchars($order_data['nama_usaha'] ?? ''); ?>" required>
                        <?php if (isset($data_errors['nama_usaha'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['nama_usaha']) . '</p>'; ?>
                    </div>
                    <div class="flex-row-input">
                        <div class="form-group">
                            <label for="bidang_usaha">Bidang Usaha</label>
                            <input type="text" name="bidang_usaha" id="bidang_usaha" placeholder="Bidang Usaha" value="<?php echo htmlspecialchars($order_data['bidang_usaha'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="lokasi_usaha">Lokasi Usaha</label>
                            <input type="text" name="lokasi_usaha" id="lokasi_usaha" placeholder="Lokasi Usaha" value="<?php echo htmlspecialchars($order_data['lokasi_usaha'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <h3>Service</h3>
                    <div class="flex-row-input">
                        <div class="form-group">
                            <label for="jenis_layanan">Jenis Layanan</label>
                            <div class="select-wrapper">
                                <select name="jenis_layanan" id="jenis_layanan" required>
                                    <option value="" disabled <?php echo empty($order_data['jenis_layanan']) ? 'selected' : ''; ?>>-- Pilih Layanan --</option>
                                    <option value="Pembuatan Logo" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'Pembuatan Logo') ? 'selected' : ''; ?>>Pembuatan Logo</option>
                                    <option value="Pendaftaran Merek Dagang" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'Pendaftaran Merek Dagang') ? 'selected' : ''; ?>>Pendaftaran Merek Dagang</option>
                                    <option value="HAKI & Legalitas" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'HAKI & Legalitas') ? 'selected' : ''; ?>>HAKI & Legalitas</option>
                                    <option value="Social Media Management" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'Social Media Management') ? 'selected' : ''; ?>>Social Media Management</option>
                                    <option value="Commercial Photography" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'Commercial Photography') ? 'selected' : ''; ?>>Commercial Photography</option>
                                    <option value="Visual Branding & Design" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'Visual Branding & Design') ? 'selected' : ''; ?>>Visual Branding & Design</option>
                                    <option value="E-Commerce Utilization" <?php echo (isset($order_data['jenis_layanan']) && $order_data['jenis_layanan'] == 'E-Commerce Utilization') ? 'selected' : ''; ?>>E-Commerce Utilization</option>
                                </select>
                            </div>
                            <?php if (isset($data_errors['jenis_layanan'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['jenis_layanan']) . '</p>'; ?>
                        </div>
                        <div class="form-group">
                            <label for="customer_price">Harga</label>
                            <input type="number" name="customer_price" id="customer_price" placeholder="Harga" value="<?php echo htmlspecialchars($order_data['customer_price'] ?? ''); ?>" step="0.01" required>
                            <?php if (isset($data_errors['customer_price'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['customer_price']) . '</p>'; ?>
                        </div>
                    </div>

                    <h3>File</h3>
                    <div class="form-group">
                        <label for="hasil_konsultasi_file">File Konsultasi</label>
                        <div class="file-upload-area" id="hasil_konsultasi_drop_area">
                            <input type="file" name="hasil_konsultasi_file" id="hasil_konsultasi_file" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="hasil_konsultasi_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="hasil_konsultasi_file_name"
                                data-original-file-name="<?php echo htmlspecialchars(get_filename_from_url($order_data['HasilConsult'])); ?>">
                                <?php echo htmlspecialchars(get_filename_from_url($order_data['HasilConsult'])); ?>
                            </span>
                        </div>
                        <?php if (isset($upload_errors['hasil_konsultasi_file'])): ?>
                            <?php foreach($upload_errors['hasil_konsultasi_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($order_data['HasilConsult'])): ?>
                            <a href="<?php echo htmlspecialchars(get_display_file_url($order_data['HasilConsult'])); ?>" target="_blank" class="existing-file-info">
                                File saat ini: <?php echo htmlspecialchars(get_filename_from_url($order_data['HasilConsult'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-existing-file">Belum ada file konsultasi diunggah.</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="revisi1_file">File Revisi 1</label>
                        <div class="file-upload-area" id="revisi1_drop_area">
                            <input type="file" name="revisi1_file" id="revisi1_file" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="revisi1_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="revisi1_file_name"
                                data-original-file-name="<?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi1'])); ?>">
                                <?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi1'])); ?>
                            </span>
                        </div>
                        <?php if (isset($upload_errors['revisi1_file'])): ?>
                            <?php foreach($upload_errors['revisi1_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($order_data['Revisi1'])): ?>
                            <a href="<?php echo htmlspecialchars(get_display_file_url($order_data['Revisi1'])); ?>" target="_blank" class="existing-file-info">
                                File saat ini: <?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi1'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-existing-file">Belum ada file revisi 1 diunggah.</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="revisi2_file">File Revisi 2</label>
                        <div class="file-upload-area" id="revisi2_drop_area">
                            <input type="file" name="revisi2_file" id="revisi2_file" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="revisi2_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="revisi2_file_name"
                                data-original-file-name="<?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi2'])); ?>">
                                <?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi2'])); ?>
                            </span>
                        </div>
                        <?php if (isset($upload_errors['revisi2_file'])): ?>
                            <?php foreach($upload_errors['revisi2_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($order_data['Revisi2'])): ?>
                            <a href="<?php echo htmlspecialchars(get_display_file_url($order_data['Revisi2'])); ?>" target="_blank" class="existing-file-info">
                                File saat ini: <?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi2'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-existing-file">Belum ada file revisi 2 diunggah.</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="pembayaran_dp_file">Bukti Pembayaran DP</label>
                        <div class="file-upload-area" id="pembayaran_dp_drop_area">
                            <input type="file" name="pembayaran_dp_file" id="pembayaran_dp_file" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="pembayaran_dp_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="pembayaran_dp_file_name"
                                data-original-file-name="<?php echo htmlspecialchars(get_filename_from_url($order_data['PembayaranDP'])); ?>">
                                <?php echo htmlspecialchars(get_filename_from_url($order_data['PembayaranDP'])); ?>
                            </span>
                        </div>
                        <?php if (isset($upload_errors['pembayaran_dp_file'])): ?>
                            <?php foreach($upload_errors['pembayaran_dp_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($order_data['PembayaranDP'])): ?>
                            <a href="<?php echo htmlspecialchars(get_display_file_url($order_data['PembayaranDP'])); ?>" target="_blank" class="existing-file-info">
                                File saat ini: <?php echo htmlspecialchars(get_filename_from_url($order_data['PembayaranDP'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-existing-file">Belum ada bukti pembayaran DP diunggah.</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="pembayaran_lunas_file">Bukti Pembayaran Lunas</label>
                        <div class="file-upload-area" id="pembayaran_lunas_drop_area">
                            <input type="file" name="pembayaran_lunas_file" id="pembayaran_lunas_file" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="pembayaran_lunas_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="pembayaran_lunas_file_name"
                                data-original-file-name="<?php echo htmlspecialchars(get_filename_from_url($order_data['BayarLunas'])); ?>">
                                <?php echo htmlspecialchars(get_filename_from_url($order_data['BayarLunas'])); ?>
                            </span>
                        </div>
                        <?php if (isset($upload_errors['pembayaran_lunas_file'])): ?>
                            <?php foreach($upload_errors['pembayaran_lunas_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($order_data['BayarLunas'])): ?>
                            <a href="<?php echo htmlspecialchars(get_display_file_url($order_data['BayarLunas'])); ?>" target="_blank" class="existing-file-info">
                                File saat ini: <?php echo htmlspecialchars(get_filename_from_url($order_data['BayarLunas'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-existing-file">Belum ada bukti pembayaran Lunas diunggah.</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="hasil_pengerjaan_file">Hasil Pengerjaan</label>
                        <div class="file-upload-area" id="hasil_pengerjaan_drop_area">
                            <input type="file" name="hasil_pengerjaan_file" id="hasil_pengerjaan_file" accept=".pdf,image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p>Drag or Drop your file here</p>
                            <label for="hasil_pengerjaan_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="hasil_pengerjaan_file_name"
                                data-original-file-name="<?php echo htmlspecialchars(get_filename_from_url($order_data['hasil_pengerjaan_db'])); ?>">
                                <?php echo htmlspecialchars(get_filename_from_url($order_data['hasil_pengerjaan_db'])); ?>
                            </span>
                        </div>
                        <?php if (isset($upload_errors['hasil_pengerjaan_file'])): ?>
                            <?php foreach($upload_errors['hasil_pengerjaan_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($order_data['hasil_pengerjaan_db'])): ?>
                            <a href="<?php echo htmlspecialchars(get_display_file_url($order_data['hasil_pengerjaan_db'])); ?>" target="_blank" class="existing-file-info">
                                File saat ini: <?php echo htmlspecialchars(get_filename_from_url($order_data['hasil_pengerjaan_db'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="no-existing-file">Belum ada file hasil pengerjaan diunggah.</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="catatan_revisi">Catatan Revisi</label>
                        <textarea name="catatan_revisi" id="catatan_revisi" placeholder="Keterangan Tambahan (Opsional)"><?php echo htmlspecialchars($order_data['CatatanRevisi'] ?? ''); ?></textarea>
                    </div>

                    <h3>Status</h3>
                    <div class="flex-row-input">
                        <div class="form-group">
                            <label for="status_pengerjaan">Status Pengerjaan</label>
                            <div class="select-wrapper">
                                <select name="status_pengerjaan" id="status_pengerjaan">
                                    <option value="On Progress" <?php echo (isset($order_data['status_pengerjaan_db']) && $order_data['status_pengerjaan_db'] == 'On Progress') ? 'selected' : ''; ?>>On Progress</option>
                                    <option value="Finished" <?php echo (isset($order_data['status_pengerjaan_db']) && $order_data['status_pengerjaan_db'] == 'Finished') ? 'selected' : ''; ?>>Finished</option>
                                    <option value="Pending" <?php echo (isset($order_data['status_pengerjaan_db']) && $order_data['status_pengerjaan_db'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Cancelled" <?php echo (isset($order_data['status_pengerjaan_db']) && $order_data['status_pengerjaan_db'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="status_pembayaran">Status Pembayaran</label>
                            <div class="select-wrapper">
                                <select name="status_pembayaran" id="status_pembayaran">
                                    <option value="Belum Dibayar" <?php echo (isset($status_pembayaran_new) && $status_pembayaran_new == 'Belum Dibayar') ? 'selected' : ''; ?>>Belum Dibayar</option>
                                    <option value="DP Dibayar" <?php echo (isset($status_pembayaran_new) && $status_pembayaran_new == 'DP Dibayar') ? 'selected' : ''; ?>>DP Dibayar</option>
                                    <option value="Lunas" <?php echo (isset($status_pembayaran_new) && $status_pembayaran_new == 'Lunas') ? 'selected' : ''; ?>>Lunas</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="button-container">
                        <button type="submit">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function setupFileUpload(dropAreaId, fileInputId, fileNameDisplayId, initialFileName) {
                const dropArea = document.getElementById(dropAreaId);
                const fileInput = document.getElementById(fileInputId);
                const fileNameDisplay = document.getElementById(fileNameDisplayId);
                const uploadIcon = dropArea.querySelector('.upload-icon');
                const dragDropText = dropArea.querySelector('p:nth-of-type(1)');    
                const chooseFileBtn = dropArea.querySelector('label.choose-file-btn');

                const updateDisplay = (hasFile, fileName = '') => {
                    if (hasFile && fileName && fileName !== 'Tidak ada file') {
                        dropArea.classList.add('file-chosen');
                        if (uploadIcon) uploadIcon.style.display = 'none';
                        if (dragDropText) dragDropText.style.display = 'none';
                        if (chooseFileBtn) chooseFileBtn.style.display = 'none';
                        fileNameDisplay.textContent = fileName;
                        fileNameDisplay.style.display = 'block';
                    } else {
                        dropArea.classList.remove('file-chosen');
                        fileNameDisplay.textContent = 'Tidak ada file';    
                        fileNameDisplay.style.display = 'none';
                        if (uploadIcon) uploadIcon.style.display = 'block';
                        if (dragDropText) dragDropText.style.display = 'block';
                        if (chooseFileBtn) chooseFileBtn.style.display = 'inline-block';
                    }
                };

                updateDisplay(initialFileName && initialFileName !== '', initialFileName);
                
                dropArea.addEventListener('click', (e) => {
                    if (e.target !== fileNameDisplay && e.target !== chooseFileBtn) {
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
                    dropArea.addEventListener(eventName, (e) => {
                        dropArea.classList.remove('highlight');
                        const dt = e.dataTransfer;
                        const files = dt.files;
                        fileInput.files = files; 
                        if (files.length > 0) {
                            updateDisplay(true, files[0].name);
                        } else {
                            const originalFileName = fileNameDisplay.dataset.originalFileName;
                            if (originalFileName && originalFileName !== '') {
                                updateDisplay(true, originalFileName);
                            } else {
                                updateDisplay(false);
                            }
                        }
                    }, false);
                });
            }

            // Setup for each upload area
            setupFileUpload('hasil_konsultasi_drop_area', 'hasil_konsultasi_file', 'hasil_konsultasi_file_name', '<?php echo htmlspecialchars(get_filename_from_url($order_data['HasilConsult'])); ?>');
            setupFileUpload('revisi1_drop_area', 'revisi1_file', 'revisi1_file_name', '<?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi1'])); ?>');
            setupFileUpload('revisi2_drop_area', 'revisi2_file', 'revisi2_file_name', '<?php echo htmlspecialchars(get_filename_from_url($order_data['Revisi2'])); ?>');
            setupFileUpload('pembayaran_dp_drop_area', 'pembayaran_dp_file', 'pembayaran_dp_file_name', '<?php echo htmlspecialchars(get_filename_from_url($order_data['PembayaranDP'])); ?>');
            setupFileUpload('pembayaran_lunas_drop_area', 'pembayaran_lunas_file', 'pembayaran_lunas_file_name', '<?php echo htmlspecialchars(get_filename_from_url($order_data['BayarLunas'])); ?>');
            setupFileUpload('hasil_pengerjaan_drop_area', 'hasil_pengerjaan_file', 'hasil_pengerjaan_file_name', '<?php echo htmlspecialchars(get_filename_from_url($order_data['hasil_pengerjaan_db'])); ?>');
        });
    </script>
</body>
</html>