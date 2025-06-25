<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
checkTimLogin(); // Memastikan tim sudah login

require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

// --- PENGATURAN DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$tim_nama = $_SESSION['tim_nama'] ?? 'Tim Pengerjaan';
$tim_email = $_SESSION['tim_email'] ?? '';

$form_success = '';
$form_error = '';
$upload_errors = []; // Untuk error upload file dari tim
$data_errors = []; // Untuk error validasi data form (jika ada input teks baru)

// Ambil ID order dari URL atau dari POST jika itu adalah submission form
$consult_id_from_request = filter_var(isset($_REQUEST['consult_id']) ? $_REQUEST['consult_id'] : null, FILTER_VALIDATE_INT);
$consult_id = $consult_id_from_request; // Gunakan variabel ini untuk seluruh operasi

error_log("DEBUG: Initial consult_id from request: " . var_export($consult_id, true));


// --- DEFINISI GLOBAL UNTUK TIPE FILE DAN UKURAN MAKSIMAL (Untuk Upload Tim) ---
$allowed_file_types_for_team_upload = ['application/pdf', 'image/jpeg', 'image/png', 'application/zip', 'application/x-rar-compressed', 'application/octet-stream']; // Tambah octet-stream sebagai fallback
$max_file_size_for_team_upload = 20 * 1024 * 1024; // 20 MB

// Path dasar untuk upload file.
// UPLOAD_BASE_DIR_PHYSICAL: Jalur fisik di sistem file server.
define('UPLOAD_BASE_DIR_PHYSICAL', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR);

// UPLOAD_BASE_DIR_URL: Jalur URL lengkap yang digunakan untuk *menampilkan* file di browser.
define('UPLOAD_BASE_DIR_URL', BASE_URL . '/public/files/');


// Data order untuk ditampilkan dan di-update
$order_detail = null;
if ($consult_id !== false && $consult_id !== null) { // Pastikan ID valid
    global $conn;
    $sql_get_detail = "SELECT
                            con.IdConsult,
                            c.Nama AS customer_name,
                            c.Email AS customer_email,
                            c.NoTelp AS customer_phone,
                            c.NamaUsaha,
                            c.BidangUsaha,
                            c.Lokasi,
                            c.JenisLayanan,
                            c.Harga,
                            con.HasilConsult,
                            con.HasilPengerjaan,
                            con.CatatanRevisi,
                            con.Revisi1,
                            con.Revisi2,
                            con.StatusPengerjaan,
                            con.StatusPembayaran,
                            con.updated_at
                           FROM `consult` AS con
                           JOIN `customer` AS c ON con.IdCust = c.IdCust
                           WHERE con.IdConsult = ?";
    
    if ($stmt_detail = $conn->prepare($sql_get_detail)) {
        $stmt_detail->bind_param('i', $consult_id);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();
        if ($result_detail->num_rows > 0) {
            $order_detail = $result_detail->fetch_assoc();
            // Konversi path file yang kosong atau '0' menjadi null
            foreach (['HasilConsult', 'HasilPengerjaan', 'Revisi1', 'Revisi2'] as $field) {
                if (isset($order_detail[$field]) && (empty($order_detail[$field]) || $order_detail[$field] === '0' || $order_detail[$field] === '0.00')) {
                    $order_detail[$field] = null;
                }
            }
            error_log("DEBUG: Order data fetched for consult_id: " . $consult_id);
        } else {
            $form_error = "Detail order tidak ditemukan untuk ID: " . $consult_id;
            error_log("ERROR: Detail order tidak ditemukan untuk ID: " . $consult_id . ". This means order_detail is NULL.");
            $consult_id = null; // Set to null agar redirect
        }
        $stmt_detail->close();
    } else {
        $form_error = "Kesalahan persiapan query detail order: " . $conn->error;
        error_log("ERROR: Kesalahan persiapan query detail order: " . $conn->error);
    }
} else {
    $form_error = "ID Order tidak valid dari URL.";
    error_log("DEBUG: ID Order tidak valid dari URL: " . var_export($consult_id, true));
}

// Jika order tidak ditemukan atau ID tidak valid, redirect kembali ke halaman daftar orders
if (!$consult_id || !$order_detail) {
    header('location: ' . BASE_URL . '/tim/orders/index.php?error=' . urlencode($form_error ?? 'Order tidak valid.'));
    exit;
}

/**
 * Fungsi helper untuk mendapatkan kelas CSS dot status (diperluas)
 */
function getStatusDotClass($status_value) {
    switch (strtolower($status_value)) {
        case 'on progress':
            return 'on-progress';
        case 'finished':
        case 'completed':
            return 'completed';
        case 'pending':
            return 'pending';
        case 'cancelled':
            return 'cancelled';
        case 'lunas':
            return 'lunas';
        case 'dp dibayar':
            return 'dp-dibayar';
        case 'belum dibayar': // Asumsi 'Belum Lunas' di DB mungkin 'Belum Dibayar'
        case 'belum lunas':
            return 'belum-lunas';
        default:
            return ''; // Kelas default jika status tidak dikenal
    }
}

/**
 * Fungsi helper untuk mendapatkan ekstensi file dari path.
 * @param string|null $path Path file (bisa URL relatif atau fisik).
 * @return string Ekstensi file dalam lowercase, atau string kosong jika tidak ada path.
 */
function getFileExtension($path) {
    if (empty($path)) return '';
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

/**
 * Fungsi helper untuk mengecek apakah file adalah gambar.
 * @param string $extension Ekstensi file.
 * @return bool True jika gambar, false jika bukan.
 */
function isImageFile($extension) {
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']; // Tambahkan ekstensi gambar lain jika perlu
    return in_array($extension, $image_extensions);
}

/**
 * Fungsi helper untuk mendapatkan icon path atau URL file langsung berdasarkan ekstensi.
 * @param string $extension Ekstensi file.
 * @param string|null $filePath URL path lengkap ke file (hanya digunakan jika itu gambar).
 * @return string URL path ke ikon file atau path file gambar itu sendiri.
 */
function getFileDisplayPath($extension, $filePath = null) {
    global $BASE_URL;
    if (isImageFile($extension) && $filePath) {
        return BASE_URL . htmlspecialchars($filePath); // Return the image file path itself
    }
    switch ($extension) {
        case 'pdf':
            return BASE_URL . '/public/images/icons/logopdf.png';
        case 'zip':
        case 'rar':
        case '7z': // Tambahkan ekstensi arsip lainnya jika perlu
            return BASE_URL . '/public/images/icons/archive-icon.png'; // Asumsi ada ikon arsip
        default:
            if (isImageFile($extension)) {
                return BASE_URL . '/public/images/icons/image-icon.png'; // Fallback to generic image icon if no file path
            }
            return BASE_URL . '/public/images/icons/default-file-icon.png'; // Ikon default untuk file lainnya
    }
}


/**
 * Fungsi helper untuk mendapatkan kelas CSS ikon berdasarkan ekstensi untuk styling.
 * @param string $extension Ekstensi file.
 * @return string Kelas CSS.
 */
function getFileIconCssClass($extension) {
    if (isImageFile($extension)) {
        return 'image-thumbnail-img'; // Class for image thumbnails
    }
    switch ($extension) {
        case 'pdf':
            return 'pdf-icon-img';
        case 'zip':
        case 'rar':
        case '7z':
            return 'archive-icon-img';
        default:
            return 'default-icon-img';
    }
}

/**
 * Fungsi helper untuk mendapatkan nama file dari path.
 * @param string|null $file_path Path file (URL relatif atau fisik).
 * @return string Nama file (basename), atau 'Tidak ada file' jika path kosong.
*/
function getFileNameFromPath($file_path) {
    if (empty($file_path)) return 'Tidak ada file';
    return basename($file_path);
}


/**
 * Fungsi helper untuk menangani proses upload file.
 * Mengembalikan path RELATIF terhadap root aplikasi (misal: /public/files/...) jika berhasil,
 * atau null jika gagal/tidak ada upload.
 * Menambahkan pesan error ke global $upload_errors array.
 *
 * @param string $file_input_html_name Nama atribut 'name' dari input file HTML.
 * @param string $upload_sub_dir Nama sub-direktori di dalam public/files/ (misal: 'HasilPengerjaan').
 * @param string $prefix Prefix untuk nama file yang disimpan (misal: 'hasil').
 * @param int $item_id ID item (consult_id) untuk penamaan file.
 * @param string $customer_name Nama Customer untuk penamaan file.
 * @param string|null $existing_db_path Path file yang sudah ada di database (RELATIF).
 * @return string|null Path file RELATIF (misal: /public/files/...) yang baru diupload atau path lama jika tidak ada upload/gagal.
 */
function handle_upload_single_team($file_input_html_name, $upload_sub_dir, $prefix, $item_id, $customer_name, $existing_db_path) {
    global $upload_errors, $allowed_file_types_for_team_upload, $max_file_size_for_team_upload;

    // Default: pertahankan path yang sudah ada dari DB (yang seharusnya sudah relatif)
    $uploaded_file_path = $existing_db_path; 

    error_log("--- Mulai handle_upload_single_team untuk: " . $file_input_html_name . " ---");
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
        $new_file_name = $prefix . '_' . $item_id . '_' . $clean_customer_name . '.' . $file_ext;
        
        $full_upload_dir_physical = UPLOAD_BASE_DIR_PHYSICAL . $upload_sub_dir . DIRECTORY_SEPARATOR;
        $destination_physical = $full_upload_dir_physical . $new_file_name;
        
        // --- INI BARIS KRUSIAL YANG DIUBAH: Path yang disimpan di database adalah relatif ---
        // Ini akan menyimpan sesuatu seperti: /public/files/HasilPengerjaan/nama_file.pdf
        $relative_path_for_db = '/public/files/' . $upload_sub_dir . '/' . $new_file_name; 
        // --- AKHIR BARIS KRUSIAL YANG DIUBAH ---

        error_log("Target physical directory: " . $full_upload_dir_physical);
        error_log("Destination physical path: " . $destination_physical);
        error_log("Relative path for DB: " . $relative_path_for_db); // Log path yang akan masuk DB

        if (!in_array($file_type, $allowed_file_types_for_team_upload)) {
            $upload_errors[$file_input_html_name][] = "Tipe file '{$file_type}' tidak diizinkan. Hanya " . implode(', ', $allowed_file_types_for_team_upload) . ".";
            error_log("Validation Error: Invalid file type for {$file_input_html_name}.");
        }
        if ($file_size > $max_file_size_for_team_upload) {
            $upload_errors[$file_input_html_name][] = "Ukuran file terlalu besar (maks " . ($max_file_size_for_team_upload / 1024 / 1024) . "MB).";
            error_log("Validation Error: File size too large for {$file_input_html_name}. Size: {$file_size}");
        }

        if (empty($upload_errors[$file_input_html_name])) {
            if (!is_dir($full_upload_dir_physical)) {
                if (!mkdir($full_upload_dir_physical, 0775, true)) {
                    $upload_errors[$file_input_html_name][] = "Gagal membuat direktori unggahan: " . $full_upload_dir_physical . ". Periksa izin folder.";
                    error_log("Directory Creation Error for {$file_input_html_name}: Gagal membuat direktori.");
                    return $uploaded_file_path;
                }
            }
            if (!is_writable($full_upload_dir_physical)) {
                $upload_errors[$file_input_html_name][] = "Direktori unggahan tidak memiliki izin tulis: " . $full_upload_dir_physical . ". Mohon atur izin folder ke 0775 atau 0777.";
                error_log("Permissions Error for {$file_input_html_name}: Direktori tidak writable.");
                return $uploaded_file_path;
            }
            
            // Hapus file lama jika ada dan berbeda dengan yang baru diupload
            // Logika ini diasumsikan existing_db_path sudah relatif, atau mencoba membersihkan BASE_URL jika ada
            $old_file_full_path_physical = null;
            if (!empty($existing_db_path)) {
                // Hapus BASE_URL dari existing_db_path jika ada
                $clean_existing_path_for_unlinking = str_replace(BASE_URL, '', $existing_db_path);
                // Jika path masih mengandung skema http/https (misal dari data lama)
                if (strpos($clean_existing_path_for_unlinking, 'http://') === 0 || strpos($clean_existing_path_for_unlinking, 'https://') === 0) {
                    $parsed_url = parse_url($clean_existing_path_for_unlinking);
                    $path_only = $parsed_url['path'] ?? '';
                    $old_file_full_path_physical = dirname(dirname(__DIR__)) . $path_only; // Menuju root expertus-app
                } else if (strpos($clean_existing_path_for_unlinking, '/public/files/') === 0) {
                    // Jika sudah relatif ke public/files, cukup gabungkan dengan root fisik aplikasi
                    $old_file_full_path_physical = dirname(dirname(__DIR__)) . $clean_existing_path_for_unlinking;
                } else {
                    // Fallback jika format tidak dikenal, coba langsung di bawah UPLOAD_BASE_DIR_PHYSICAL
                    $old_file_full_path_physical = UPLOAD_BASE_DIR_PHYSICAL . $existing_db_path;
                }
            }
            
            if (!empty($old_file_full_path_physical) && file_exists($old_file_full_path_physical) && $old_file_full_path_physical !== $destination_physical) {
                if (unlink($old_file_full_path_physical)) {
                    error_log("SUCCESS: File lama '{$old_file_full_path_physical}' berhasil dihapus.");
                } else {
                    error_log("WARNING: Gagal menghapus file lama '{$old_file_full_path_physical}'. Periksa izin atau path.");
                }
            }

            if (move_uploaded_file($file_tmp_name, $destination_physical)) {
                $uploaded_file_path = $relative_path_for_db; // Path yang disimpan di DB adalah RELATIF
                error_log("SUCCESS: File '{$file_name_original}' berhasil diunggah ke '{$uploaded_file_path}'.");
            } else {
                $upload_errors[$file_input_html_name][] = "Gagal memindahkan file. Ini bisa jadi masalah izin, jalur tujuan, atau file sementara hilang. Error PHP: " . (error_get_last()['message'] ?? 'Tidak diketahui');
                error_log("FAILED: move_uploaded_file failed for {$file_input_html_name}. PHP Error: " . (error_get_last()['message'] ?? 'N/A') . " from tmp: {$file_tmp_name} to dest: {$destination_physical}");
            }
        }
    }
    error_log("--- Selesai handle_upload_single_team untuk: " . $file_input_html_name . ", Final Path: " . ($uploaded_file_path ?? 'NULL') . " ---");
    return $uploaded_file_path;
}


// --- PENANGANAN POST REQUEST (UPLOAD HASIL KERJA/REVISI, UBAH STATUS PENGERJAAN) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_order_by_team') {
    global $conn, $order_detail, $upload_errors, $data_errors;

    // Pastikan $current_consult_id selalu valid dari POST
    $current_consult_id = (int)sanitize_input($_POST['consult_id']);
    $customer_name = $order_detail['customer_name'] ?? 'Unknown Customer';

    $new_status_pengerjaan = sanitize_input($_POST['status_pengerjaan'] ?? $order_detail['StatusPengerjaan']);
    $new_catatan_revisi = sanitize_input($_POST['catatan_revisi'] ?? $order_detail['CatatanRevisi']);

    // Ambil path file yang sudah ada dari DB sebelum mencoba upload yang baru
    $existing_hasil_pengerjaan_file = $order_detail['HasilPengerjaan'];
    $existing_revisi1_file = $order_detail['Revisi1'];
    $existing_revisi2_file = $order_detail['Revisi2'];

    $new_hasil_pengerjaan_path = $existing_hasil_pengerjaan_file;
    $new_revisi1_path = $existing_revisi1_file;
    $new_revisi2_path = $existing_revisi2_file;

    // Handle Hasil Pengerjaan
    $uploaded_hasil = handle_upload_single_team(
        'hasil_pengerjaan_file', 'HasilPengerjaan', 'hasil',
        $current_consult_id, $customer_name, $existing_hasil_pengerjaan_file
    );
    // Important: Only update path if upload was OK AND no specific upload errors were recorded for this field
    if (isset($_FILES['hasil_pengerjaan_file']) && $_FILES['hasil_pengerjaan_file']['error'] == UPLOAD_ERR_OK && !isset($upload_errors['hasil_pengerjaan_file'])) {
        $new_hasil_pengerjaan_path = $uploaded_hasil;
    } else if (isset($_FILES['hasil_pengerjaan_file']) && $_FILES['hasil_pengerjaan_file']['error'] != UPLOAD_ERR_NO_FILE && isset($upload_errors['hasil_pengerjaan_file'])) {
        // Jika user mencoba upload (bukan UPLOAD_ERR_NO_FILE) TAPI ada error upload (misal ukuran/tipe), pertahankan path lama
        $new_hasil_pengerjaan_path = $existing_hasil_pengerjaan_file; 
    }
    // If UPLOAD_ERR_NO_FILE (user didn't select new file), $new_hasil_pengerjaan_path remains $existing_hasil_pengerjaan_file, which is correct.


    // Handle Revisi 1 (jika ada)
    $uploaded_revisi1 = handle_upload_single_team(
        'revisi1_file', 'Revisi1', 'revisi1',
        $current_consult_id, $customer_name, $existing_revisi1_file
    );
    if (isset($_FILES['revisi1_file']) && $_FILES['revisi1_file']['error'] == UPLOAD_ERR_OK && !isset($upload_errors['revisi1_file'])) {
        $new_revisi1_path = $uploaded_revisi1;
    } else if (isset($_FILES['revisi1_file']) && $_FILES['revisi1_file']['error'] != UPLOAD_ERR_NO_FILE && isset($upload_errors['revisi1_file'])) {
        $new_revisi1_path = $existing_revisi1_file;
    }

    // Handle Revisi 2 (jika ada)
    $uploaded_revisi2 = handle_upload_single_team(
        'revisi2_file', 'Revisi2', 'revisi2',
        $current_consult_id, $customer_name, $existing_revisi2_file
    );
    if (isset($_FILES['revisi2_file']) && $_FILES['revisi2_file']['error'] == UPLOAD_ERR_OK && !isset($upload_errors['revisi2_file'])) {
        $new_revisi2_path = $uploaded_revisi2;
    } else if (isset($_FILES['revisi2_file']) && $_FILES['revisi2_file']['error'] != UPLOAD_ERR_NO_FILE && isset($upload_errors['revisi2_file'])) {
        $new_revisi2_path = $existing_revisi2_file;
    }


    if (!empty($upload_errors)) {
        $full_error_message = "Terdapat kesalahan saat mengunggah file:<br>";
        foreach ($upload_errors as $field => $messages) {
            if (!empty($messages)) {
                $display_field_name = str_replace(['_file'], [''], $field);
                $full_error_message .= "- " . htmlspecialchars(ucfirst(str_replace('_', ' ', $display_field_name))) . ": " . implode("<br>- ", $messages) . "<br>";
            }
        }
        $form_error = $full_error_message;
    } else {
        // Lakukan update ke database
        $sql_update_parts = [];
        $update_params = [];
        $update_types = '';

        // Pastikan Anda menangani kasus di mana path lama mungkin null
        // Cek apakah ada perubahan path atau jika path sebelumnya null dan sekarang ada file
        if (($new_hasil_pengerjaan_path ?? '') !== ($existing_hasil_pengerjaan_file ?? '')) {
            $sql_update_parts[] = "`HasilPengerjaan` = ?";
            $update_params[] = &$new_hasil_pengerjaan_path;
            $update_types .= 's';
        }
        if (($new_revisi1_path ?? '') !== ($existing_revisi1_file ?? '')) {
            $sql_update_parts[] = "`Revisi1` = ?";
            $update_params[] = &$new_revisi1_path;
            $update_types .= 's';
        }
        if (($new_revisi2_path ?? '') !== ($existing_revisi2_file ?? '')) {
            $sql_update_parts[] = "`Revisi2` = ?";
            $update_params[] = &$new_revisi2_path;
            $update_types .= 's';
        }

        // Update status pengerjaan dan catatan revisi
        if ($new_status_pengerjaan !== $order_detail['StatusPengerjaan']) {
            $sql_update_parts[] = "`StatusPengerjaan` = ?";
            $update_params[] = &$new_status_pengerjaan;
            $update_types .= 's';
        }
        // Catatan revisi selalu diupdate (karena ini adalah input teks biasa)
        if ($new_catatan_revisi !== ($order_detail['CatatanRevisi'] ?? '')) {
           $sql_update_parts[] = "`CatatanRevisi` = ?";
           $update_params[] = &$new_catatan_revisi;
           $update_types .= 's';
        }
        
        // Tambahkan updated_at
        $updated_at_value = date('Y-m-d H:i:s');
        $sql_update_parts[] = "`updated_at` = ?";
        $update_params[] = &$updated_at_value;
        $update_types .= 's';

        // Hanya jalankan query UPDATE jika ada perubahan data yang perlu disimpan (selain updated_at saja jika tidak ada perubahan lain)
        $is_any_field_changed = (
            (($new_hasil_pengerjaan_path ?? '') !== ($existing_hasil_pengerjaan_file ?? '')) ||
            (($new_revisi1_path ?? '') !== ($existing_revisi1_file ?? '')) ||
            (($new_revisi2_path ?? '') !== ($existing_revisi2_file ?? '')) ||
            $new_status_pengerjaan !== $order_detail['StatusPengerjaan'] ||
            $new_catatan_revisi !== ($order_detail['CatatanRevisi'] ?? '')
        );

        if ($is_any_field_changed) { // Hanya update jika ada perubahan selain updated_at
            $sql_update_str = "UPDATE `consult` SET " . implode(', ', $sql_update_parts) . " WHERE `IdConsult` = ?";
            $update_types .= 'i';
            $update_params[] = &$current_consult_id;

            $stmt = null;
            try {
                if ($stmt = $conn->prepare($sql_update_str)) {
                    call_user_func_array([$stmt, 'bind_param'], array_merge([$update_types], $update_params));
                    if ($stmt->execute()) {
                        $form_success = 'Data order berhasil diperbarui!';
                    } else {
                        throw new Exception('Gagal memperbarui database: ' . $stmt->error);
                    }
                } else {
                    throw new Exception('Kesalahan persiapan query: ' . $conn->error);
                }
            } catch (Exception $e) {
                $form_error = "Terjadi error: " . $e->getMessage();
                error_log("Tim Order Detail Update Error: " . $e->getMessage());
            } finally {
                if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                    $stmt->close();
                }
            }
        } else {
            $form_success = 'Tidak ada perubahan data order untuk diperbarui.';
        }
    }
    
    
    $redirect_url = BASE_URL . '/tim/orders/detail.php?consult_id=' . urlencode($current_consult_id);

    if (!empty($form_error)) {
        $redirect_url .= '&error=' . urlencode($form_error);
    } else {
        $redirect_url .= '&success=' . urlencode($form_success);
    }

    error_log("DEBUG: Before redirect. form_success: " . var_export($form_success, true) . ", form_error: " . var_export($form_error, true));
    error_log("DEBUG: Redirecting to: " . $redirect_url);

    header('location: ' . $redirect_url);
    exit;
    // --- Akhir Bagian Redirect yang Diperbaiki ---
}

if (isset($_GET['success']) && !empty($_GET['success'])) { 
    $form_success = htmlspecialchars(urldecode($_GET['success']));
    $form_error = ''; 
} elseif (isset($_GET['error']) && !empty($_GET['error'])) {
    $form_error = htmlspecialchars(urldecode($_GET['error']));
    $form_success = ''; 
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Order Tim - ExpertUs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* CSS umum (dari sidebar dan orders list) */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F8FAFB;
        }
        @keyframes fadeInMoveUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Sidebar */
        .sidebar {
            width: 250px; background-color: #26474E; background-image: linear-gradient(to bottom, #26474E, #1A365D);
            color: white; padding: 1.5rem 0; position: fixed; height: 100%; display: flex;
            flex-direction: column; border-top-right-radius: 1rem; border-bottom-right-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .sidebar-profile { margin-bottom: 2.5rem; text-align: center; padding: 0 1.5rem; }
        .sidebar-profile .avatar {
            width: 80px; height: 80px; background-color: #6B7280; border-radius: 50%;
            margin: 0 auto 0.75rem; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; color: white;
        }
        .sidebar-profile .team-label { font-size: 0.875rem; color: #D1D5DB; }
        .sidebar-profile .team-name { font-size: 1.125rem; font-weight: 600; color: white; }
        .sidebar-nav { flex-grow: 1; padding: 0 1rem; }
        .sidebar-nav a {
            display: flex; align-items: center; padding: 0.75rem 1rem; margin-bottom: 0.5rem;
            border-radius: 0.5rem; color: #D1D5DB; text-decoration: none;
            transition: background-color 0.2s, color 0.2s; font-weight: 600;
        }
        .sidebar-nav a:hover, a.active { background-color: #3B5F6C; color: white; }
        .sidebar-nav a i { margin-right: 1rem; font-size: 1.35rem; }
        .content {
            margin-left: 250px; padding: 2.5rem; min-height: 100vh; width: calc(100% - 250px);
        }

        /* Order Details Page Specific Styles - Revised */
        .order-detail-card {
            background-color: white; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 2rem; margin-bottom: 2rem; opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.2s forwards;
        }
        .detail-header {
            display: flex; align-items: center; margin-bottom: 2rem; color: #1F2937;
        }
        .detail-header a {
            color: #4B5563; margin-right: 1rem; font-size: 1.5rem; text-decoration: none; transition: color 0.2s;
        }
        .detail-header a:hover { color: #1F2937; }
        .detail-header h2 { font-size: 1.5rem; font-weight: 600; }
        .detail-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;
        }
        .detail-column-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .detail-section-card {
            background-color: white; border-radius: 0.5rem; border: 1px solid #E5E7EB;
            padding: 1.5rem; box-shadow: none; display: flex; flex-direction: column;
        }
        .detail-section-card h3 {
            font-size: 1.125rem; font-weight: 600; color: #1F2937; margin-bottom: 1.25rem;
        }
        .detail-item {
            display: flex; align-items: center; margin-bottom: 0.8rem;
            color: #4B5563; font-size: 0.95rem;
        }
        .detail-item:last-child { margin-bottom: 0; }
        .detail-item i {
            color: #6B7280; width: 24px; text-align: left; margin-right: 0.75rem;
            font-size: 1rem; flex-shrink: 0;
        }
        .detail-item span { flex-grow: 1; }
        .detail-item strong { font-weight: 500; color: #1F2937; }

        /* STATUS FIELDS - REVISI AKHIR UNTUK TITIK SAJA & SELECT FUNGSIONAL */
        .status-fields-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: flex-start;
        }
        .status-field-group {
            flex: 1;
            min-width: 140px;
            position: relative;
        }
        .status-field-group label {
            font-size: 0.85rem;
            color: #6B7280;
            font-weight: 500;
            display: block;
            margin-bottom: 0.5rem;
        }
        .custom-select-wrapper {
            position: relative;
            width: 100%;
            height: 42px;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            background-color: white;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .custom-select-wrapper:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
        }

        .status-field-group select.status-select {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            padding: 0.75rem 1rem;
            padding-left: 2.2rem;
            background-color: transparent;
            color: transparent;
            border: none;
            outline: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            cursor: pointer;
            z-index: 5;

            -webkit-text-fill-color: initial;
            opacity: 1;
        }
        .status-field-group select.status-select option {
            background-color: white;
            color: #4B5563;
            padding: 0.5rem 1rem;
        }


        .select-display {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            padding: 0.75rem 1rem;
            padding-left: 2.2rem;
            display: flex;
            align-items: center;
            background-color: white;
            color: #4B5563;
            font-size: 0.95rem;
            font-weight: 500;
            pointer-events: none;
            z-index: 1;
        }
        .select-display .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
        .custom-select-wrapper .select-arrow {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6B7280;
            font-size: 0.8rem;
            z-index: 6;
            pointer-events: none;
        }

        /* Warna titik status (di dalam select-display) */
        .select-display .status-dot.on-progress { background-color: #3b82f6; }
        .select-display .status-dot.completed { background-color: #10B981; }
        .select-display .status-dot.pending { background-color: #f59e0b; }
        .select-display .status-dot.cancelled { background-color: #ef4444; }
        .select-display .status-dot.lunas { background-color: #10B981; }
        .select-display .status-dot.dp-dibayar { background-color: #3b82f6; }
        .select-display .status-dot.belum-lunas { background-color: #ef4444; }


        .file-card-item {
            background-color: white;
            border: 1px dotted #D1D5DB;
            border-radius: 0.5rem;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: background-color 0.2s;
            cursor: pointer;
            width: 100%;
            box-sizing: border-box;
            min-height: 120px;
            justify-content: center;
            text-decoration: none;
        }
        .file-card-item:hover {
            background-color: #F3F4F6;
        }

        .file-icon-wrapper {
            background-color: #fee2e2;
            border-radius: 0.375rem;
            width: 64px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: 0.75rem;
        }
        /* Menggunakan tag <img> untuk ikon PDF */
        .file-icon-wrapper img.pdf-icon-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 0.5rem;
        }

        .file-icon-wrapper img.image-thumbnail-img {
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            border-radius: 0.375rem; 
        }


        .file-name-text {
            font-size: 0.95rem;
            color: #4b5563;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            display: block;
        }
        .download-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            cursor: pointer;
        }
        .file-card-item:hover .download-overlay {
            opacity: 1;
        }
        .download-overlay i.fas.fa-download {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            font-size: 2.5rem;
            color: white;
            margin: 0;
        }
        .download-overlay i.fas.fa-download::before {
            content: "\f019";
        }


        /* Revision Text */
        .revision-text {
            color: #4B5563; font-size: 0.95rem; line-height: 1.5; font-weight: 400;
        }

        .form-group {
            margin-bottom: 1rem; 
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #4A5568;
        }
        .form-group textarea, .form-group select {
            width: 100%;
            padding: 0.75rem 1rem; 
            border: 1px solid #CBD5E0;
            border-radius: 0.5rem;
            font-size: 1rem;
            color: #2D3748;
            outline: none;
            transition: border-color 0.2s ease;
        }
        .form-group textarea:focus, .form-group select:focus {
            border-color: #91D047;
            box-shadow: 0 0 0 2px rgba(145, 208, 71, 0.2);
        }
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
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
            margin-top: 0.5rem; 
            margin-bottom: 1rem; 
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 120px;
            position: relative;
            overflow: hidden; /* To ensure the inner elements respect its bounds */
        }
        .file-upload-area:hover { background-color: #f3f4f6; border-color: #9CA3AF; }
        .file-upload-area .upload-icon { width: 48px; height: 48px; margin-bottom: 0.75rem; object-fit: contain; }
        .file-upload-area p { font-size: 0.9rem; color: #6B7280; margin-bottom: 0.5rem; }
        .file-upload-area .choose-file-btn {
            background-color: #10B981; color: white; padding: 0.5rem 1rem; border-radius: 9999px;
            font-weight: 600; font-size: 0.85rem; text-decoration: none; display: inline-block; z-index: 10;
        }
        .file-upload-area .choose-file-btn:hover { background-color: #059669; }
        .file-upload-area input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0; /* Make it invisible but clickable */
            cursor: pointer;
            z-index: 10;
        }
        .file-upload-area .file-name-display {
            font-size: 0.85rem; color: #4B5563; margin-top: 0.5rem; word-break: break-all; display: none;
        }
        /* When a file is chosen, hide default upload elements */
        .file-upload-area.file-chosen .upload-icon,
        .file-upload-area.file-chosen p,
        .file-upload-area.file-chosen .choose-file-btn {
            display: none !important;
        }
        .file-upload-area.file-chosen .file-name-display {
            display: block !important;
        }


        .button-main-container {
            display: flex;
            justify-content: flex-end;
            margin-top: 2rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .action-button-main {
            background-color: #10B981;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            justify-content: center;
        }
        .action-button-main:hover {
            background-color: #059669;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
        }


        /* Alert Messages */
        .alert-error {
            background-color: #fee2e2;
            border: 1px solid #ef4444;
            color: #dc2626;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }
        .alert-success {
            background-color: #D1FAE5;
            border: 1px solid #10B981;
            color: #065F46;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }


        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
                align-items: center;
            }
            .sidebar-profile .team-label,
            .sidebar-profile .team-name {
                display: none;
            }
            .sidebar-nav a span {
                display: none;
            }
            .sidebar-nav a i {
                margin-right: 0;
            }
            .content {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
        }

        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .status-fields-container {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
            }
            .status-field-group {
                width: 100%;
            }
            .button-main-container {
                flex-direction: column;
                gap: 0.75rem;
                align-items: center;
            }
            .action-button-main {
                width: 100%;
            }
            .file-card-item {
                flex-direction: column;
                padding: 1rem;
                min-height: auto;
            }
            .file-icon-wrapper {
                margin-bottom: 0.75rem;
                margin-right: 0;
            }
            .file-name-text {
                text-align: center;
            }
        }
    </style>
</head>
<body class="flex">
    <?php
    $current_page_tim = 'orders'; 
    ?>
    <div class="sidebar">
        <div class="sidebar-profile">
            <div class="avatar"><i class="fas fa-user"></i></div>
            <span class="team-label">Tim Pengerjaan</span>
            <div class="team-name"><?php echo htmlspecialchars($tim_nama); ?></div>
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo BASE_URL; ?>/tim/dashboard.php" class="<?php echo ($current_page_tim == 'dashboard') ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tim/orders/index.php" class="<?php echo ($current_page_tim == 'orders') ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> <span>Orders</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/tim/logout.php" class="">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </nav>
    </div>

    <div class="content">
        <?php if (!empty($form_error)): ?>
            <div class="alert-error"><?php echo htmlspecialchars($form_error); ?></div>
        <?php endif; ?>
        <?php if (!empty($form_success)): ?>
            <div class="alert-success"><?php echo htmlspecialchars($form_success); ?></div>
        <?php endif; ?>

        <div class="order-detail-card">
            <div class="detail-header">
                <a href="<?php echo BASE_URL; ?>/tim/orders/index.php" class="back-arrow"><i class="fas fa-chevron-left"></i></a>
                <h2>Detail Order #<?php echo htmlspecialchars($order_detail['IdConsult']); ?></h2>
            </div>

            <div class="detail-grid">
                <div class="detail-column-wrapper">
                    <div class="detail-section-card">
                        <h3>Customer Details</h3>
                        <div class="detail-item">
                            <i class="fas fa-user"></i> <span><strong><?php echo htmlspecialchars($order_detail['customer_name']); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-envelope"></i> <span><?php echo htmlspecialchars($order_detail['customer_email']); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-phone"></i> <span><?php echo htmlspecialchars($order_detail['customer_phone']); ?></span>
                        </div>
                    </div>

                    <div class="detail-section-card">
                        <h3>Status</h3>
                        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?consult_id=<?php echo urlencode($order_detail['IdConsult']); ?>" method="POST">
                            <input type="hidden" name="action" value="update_order_by_team">
                            <input type="hidden" name="consult_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">
                            
                            <div class="status-fields-container">
                                <div class="status-field-group">
                                    <label for="status_pengerjaan">Status Pengerjaan:</label>
                                    <div class="custom-select-wrapper">
                                        <select id="status_pengerjaan" name="status_pengerjaan" class="status-select">
                                            <option value="On Progress" <?php echo ($order_detail['StatusPengerjaan'] == 'On Progress') ? 'selected' : ''; ?>>On Progress</option>
                                            <option value="Finished" <?php echo ($order_detail['StatusPengerjaan'] == 'Finished') ? 'selected' : ''; ?>>Finished</option>
                                            <option value="Pending" <?php echo ($order_detail['StatusPengerjaan'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Cancelled" <?php echo ($order_detail['StatusPengerjaan'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                        <div class="select-display">
                                            <span class="status-dot"></span>
                                            <span class="status-text-content"></span>
                                        </div>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>

                                <div class="status-field-group">
                                    <label for="status_pembayaran_display">Status Pembayaran:</label>
                                    <div class="custom-select-wrapper" style="pointer-events: none; opacity: 0.8;">
                                        <select id="status_pembayaran_display" name="status_pembayaran_display" class="status-select" disabled>
                                            <option value="Belum Dibayar" <?php echo ($order_detail['StatusPembayaran'] == 'Belum Dibayar') ? 'selected' : ''; ?>>Belum Dibayar</option>
                                            <option value="DP Dibayar" <?php echo ($order_detail['StatusPembayaran'] == 'DP Dibayar') ? 'selected' : ''; ?>>DP Dibayar</option>
                                            <option value="Lunas" <?php echo ($order_detail['StatusPembayaran'] == 'Lunas') ? 'selected' : ''; ?>>Lunas</option>
                                        </select>
                                        <div class="select-display">
                                            <span class="status-dot"></span>
                                            <span class="status-text-content"></span>
                                        </div>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" style="margin-top: 1rem;">
                                <label for="catatan_revisi">Catatan Revisi dari Admin/Tim:</label>
                                <textarea name="catatan_revisi" id="catatan_revisi" rows="3"><?php echo htmlspecialchars($order_detail['CatatanRevisi'] ?? ''); ?></textarea>
                            </div>
                            <div class="button-container" style="margin-top: 1.5rem; justify-content: flex-end;">
                                <button type="submit" class="action-button-main">
                                    <i class="fas fa-save"></i> Simpan Perubahan Status & Catatan
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="detail-section-card">
                        <h3>Additional Information</h3>
                        <div class="detail-item">
                            <i class="fas fa-building"></i> <span>Nama Usaha: <strong><?php echo htmlspecialchars($order_detail['NamaUsaha'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-industry"></i> <span>Bidang Usaha: <strong><?php echo htmlspecialchars($order_detail['BidangUsaha'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-map-marker-alt"></i> <span>Lokasi: <strong><?php echo htmlspecialchars($order_detail['Lokasi'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-cogs"></i> <span>Jenis Layanan: <strong><?php echo htmlspecialchars($order_detail['JenisLayanan'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-dollar-sign"></i> <span>Harga: <strong>Rp <?php echo number_format($order_detail['Harga'], 0, ',', '.'); ?></strong></span>
                        </div>
                        
                        <h3 class="mt-4 mb-2">File Konsultasi (dari Customer)</h3>
                        <?php if (!empty($order_detail['HasilConsult'])): ?>
                            <?php
                            $file_path_consult = $order_detail['HasilConsult'];
                            $file_ext_consult = getFileExtension($file_path_consult);
                            $is_image_consult = isImageFile($file_ext_consult);
                            $file_display_src_consult = getFileDisplayPath($file_ext_consult, $file_path_consult);
                            $file_css_class_consult = getFileIconCssClass($file_ext_consult);
                            $file_name_display_consult = getFileNameFromPath($file_path_consult);
                            ?>
                            <a href="<?php echo BASE_URL . htmlspecialchars($file_path_consult); ?>" target="_blank" class="file-card-item">
                                <div class="file-icon-wrapper">
                                    <?php if ($is_image_consult): ?>
                                        <img src="<?php echo htmlspecialchars($file_display_src_consult); ?>" alt="Image Thumbnail" class="<?php echo htmlspecialchars($file_css_class_consult); ?>">
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars($file_display_src_consult); ?>" alt="<?php echo htmlspecialchars($file_ext_consult); ?> Icon" class="<?php echo htmlspecialchars($file_css_class_consult); ?>">
                                    <?php endif; ?>
                                </div>
                                <span class="file-name-text"><?php echo htmlspecialchars($file_name_display_consult); ?></span>
                                <div class="download-overlay"><i class="fas fa-download"></i></div>
                            </a>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm mt-2">Tidak ada file konsultasi.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-column-wrapper">
                    <div class="detail-section-card">
                        <h3>Hasil Pengerjaan</h3>
                        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?consult_id=<?php echo urlencode($order_detail['IdConsult']); ?>" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_order_by_team">
                            <input type="hidden" name="consult_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">
                            
                            <div class="form-group">
                                <label for="hasil_pengerjaan_file">Upload File Hasil Pengerjaan</label>
                                <div class="file-upload-area" id="hasil_pengerjaan_drop_area">
                                    <input type="file" name="hasil_pengerjaan_file" id="hasil_pengerjaan_file" accept=".pdf,image/*,.zip,.rar">
                                    <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                                    <p>Drag or Drop your file here</p>
                                    <label for="hasil_pengerjaan_file" class="choose-file-btn">Choose File</label>
                                    <span class="file-name-display" id="hasil_pengerjaan_file_name"
                                        data-original-file-name="<?php echo htmlspecialchars(getFileNameFromPath($order_detail['HasilPengerjaan'])); ?>">
                                        <?php echo htmlspecialchars(getFileNameFromPath($order_detail['HasilPengerjaan'])); ?>
                                    </span>
                                </div>
                                <?php if (isset($upload_errors['hasil_pengerjaan_file'])): ?>
                                    <?php foreach($upload_errors['hasil_pengerjaan_file'] as $err_msg): ?>
                                        <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <h3 class="mt-4 mb-2">Current Order Result File</h3>
                            <?php if (!empty($order_detail['HasilPengerjaan'])): ?>
                                <?php
                                $file_path_hasil = $order_detail['HasilPengerjaan'];
                                $file_ext_hasil = getFileExtension($file_path_hasil);
                                $is_image_hasil = isImageFile($file_ext_hasil);
                                $file_display_src_hasil = getFileDisplayPath($file_ext_hasil, $file_path_hasil);
                                $file_css_class_hasil = getFileIconCssClass($file_ext_hasil);
                                $file_name_display_hasil = getFileNameFromPath($file_path_hasil);
                                ?>
                                <a href="<?php echo BASE_URL . htmlspecialchars($file_path_hasil); ?>" target="_blank" class="file-card-item">
                                    <div class="file-icon-wrapper">
                                        <?php if ($is_image_hasil): ?>
                                            <img src="<?php echo htmlspecialchars($file_display_src_hasil); ?>" alt="Image Thumbnail" class="<?php echo htmlspecialchars($file_css_class_hasil); ?>">
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($file_display_src_hasil); ?>" alt="<?php echo htmlspecialchars($file_ext_hasil); ?> Icon" class="<?php echo htmlspecialchars($file_css_class_hasil); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <span class="file-name-text"><?php echo htmlspecialchars($file_name_display_hasil); ?></span>
                                    <div class="download-overlay"><i class="fas fa-download"></i></div>
                                </a>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm mt-2">Belum ada hasil pengerjaan diunggah.</p>
                            <?php endif; ?>
                            
                            <div class="button-container" style="margin-top: 1.5rem; justify-content: flex-end;">
                                <button type="submit" class="action-button-main">
                                    <i class="fas fa-upload"></i> Upload Hasil Pengerjaan
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="detail-section-card">
                        <h3>Revisi</h3>
                        <p class="revision-text mb-4">Catatan Revisi dari Admin: <?php echo htmlspecialchars($order_detail['CatatanRevisi'] ?? 'Tidak ada catatan.'); ?></p>
                        
                        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?consult_id=<?php echo urlencode($order_detail['IdConsult']); ?>" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_order_by_team">
                            <input type="hidden" name="consult_id" value="<?php echo htmlspecialchars($order_detail['IdConsult']); ?>">

                            <div class="form-group">
                                <label for="revisi1_file">Upload File Revisi 1</label>
                                <div class="file-upload-area" id="revisi1_drop_area">
                                    <input type="file" name="revisi1_file" id="revisi1_file" accept=".pdf,image/*,.zip,.rar">
                                    <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                                    <p>Drag or Drop your file here</p>
                                    <label for="revisi1_file" class="choose-file-btn">Choose File</label>
                                    <span class="file-name-display" id="revisi1_file_name"
                                        data-original-file-name="<?php echo htmlspecialchars(getFileNameFromPath($order_detail['Revisi1'])); ?>">
                                        <?php echo htmlspecialchars(getFileNameFromPath($order_detail['Revisi1'])); ?>
                                    </span>
                                </div>
                                <?php if (isset($upload_errors['revisi1_file'])): ?>
                                    <?php foreach($upload_errors['revisi1_file'] as $err_msg): ?>
                                        <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="revisi2_file">Upload File Revisi 2</label>
                                <div class="file-upload-area" id="revisi2_drop_area">
                                    <input type="file" name="revisi2_file" id="revisi2_file" accept=".pdf,image/*,.zip,.rar">
                                    <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                                    <p>Drag or Drop your file here</p>
                                    <label for="revisi2_file" class="choose-file-btn">Choose File</label>
                                    <span class="file-name-display" id="revisi2_file_name"
                                        data-original-file-name="<?php echo htmlspecialchars(getFileNameFromPath($order_detail['Revisi2'])); ?>">
                                        <?php echo htmlspecialchars(getFileNameFromPath($order_detail['Revisi2'])); ?>
                                    </span>
                                </div>
                                <?php if (isset($upload_errors['revisi2_file'])): ?>
                                    <?php foreach($upload_errors['revisi2_file'] as $err_msg): ?>
                                        <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="button-container" style="margin-top: 1.5rem; justify-content: flex-end;">
                                <button type="submit" class="action-button-main">
                                    <i class="fas fa-upload"></i> Upload Revisi
                                </button>
                            </div>
                        </form>

                        <h3 class="mt-4 mb-2">Current Revision Files</h3>
                        <div class="grid grid-cols-1 gap-2">
                            <?php if (!empty($order_detail['Revisi1'])): ?>
                                <?php
                                $file_path_rev1_display = $order_detail['Revisi1'];
                                $file_ext_rev1_display = getFileExtension($file_path_rev1_display);
                                $is_image_rev1_display = isImageFile($file_ext_rev1_display);
                                $file_display_src_rev1_display = getFileDisplayPath($file_ext_rev1_display, $file_path_rev1_display);
                                $file_css_class_rev1_display = getFileIconCssClass($file_ext_rev1_display);
                                $file_name_display_rev1_display = getFileNameFromPath($file_path_rev1_display);
                                ?>
                                <a href="<?php echo BASE_URL . htmlspecialchars($file_path_rev1_display); ?>" target="_blank" class="file-card-item">
                                    <div class="file-icon-wrapper">
                                        <?php if ($is_image_rev1_display): ?>
                                            <img src="<?php echo htmlspecialchars($file_display_src_rev1_display); ?>" alt="Image Thumbnail" class="<?php echo htmlspecialchars($file_css_class_rev1_display); ?>">
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($file_display_src_rev1_display); ?>" alt="<?php echo htmlspecialchars($file_ext_rev1_display); ?> Icon" class="<?php echo htmlspecialchars($file_css_class_rev1_display); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <span class="file-name-text"><?php echo htmlspecialchars($file_name_display_rev1_display); ?></span>
                                    <div class="download-overlay"><i class="fas fa-download"></i></div>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($order_detail['Revisi2'])): ?>
                                <?php
                                $file_path_rev2_display = $order_detail['Revisi2'];
                                $file_ext_rev2_display = getFileExtension($file_path_rev2_display);
                                $is_image_rev2_display = isImageFile($file_ext_rev2_display);
                                $file_display_src_rev2_display = getFileDisplayPath($file_ext_rev2_display, $file_path_rev2_display);
                                $file_css_class_rev2_display = getFileIconCssClass($file_ext_rev2_display);
                                $file_name_display_rev2_display = getFileNameFromPath($file_path_rev2_display);
                                ?>
                                <a href="<?php echo BASE_URL . htmlspecialchars($file_path_rev2_display); ?>" target="_blank" class="file-card-item">
                                    <div class="file-icon-wrapper">
                                        <?php if ($is_image_rev2_display): ?>
                                            <img src="<?php echo htmlspecialchars($file_display_src_rev2_display); ?>" alt="Image Thumbnail" class="<?php echo htmlspecialchars($file_css_class_rev2_display); ?>">
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($file_display_src_rev2_display); ?>" alt="<?php echo htmlspecialchars($file_ext_rev2_display); ?> Icon" class="<?php echo htmlspecialchars($file_css_class_rev2_display); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <span class="file-name-text"><?php echo htmlspecialchars($file_name_display_rev2_display); ?></span>
                                    <div class="download-overlay"><i class="fas fa-download"></i></div>
                                </a>
                            <?php endif; ?>
                            <?php if (empty($order_detail['Revisi1']) && empty($order_detail['Revisi2'])): ?>
                                <p class="text-gray-500 text-sm">Belum ada file revisi diunggah.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Fungsi untuk mendapatkan kelas warna berdasarkan status
            function getStatusColorClass(statusText) {
                const normalizedStatus = statusText.toLowerCase().replace(/\s/g, '-');
                const statusMap = {
                    'finished': 'completed', // Map database 'Finished' to CSS 'completed'
                    'on-progress': 'on-progress',
                    'pending': 'pending',
                    'cancelled': 'cancelled',
                    'lunas': 'lunas',
                    'dp-dibayar': 'dp-dibayar',
                    'belum-dibayar': 'belum-lunas' // Map 'Belum Dibayar' to 'belum-lunas'
                };
                return statusMap[normalizedStatus] || 'default-status';
            }

            // Setup File Upload Area
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

                updateDisplay(initialFileName && initialFileName !== 'Tidak ada file', initialFileName);
                
                dropArea.addEventListener('click', (e) => {
                    if (e.target !== fileInput && e.target.tagName.toLowerCase() !== 'label') {
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

            setupFileUpload('hasil_pengerjaan_drop_area', 'hasil_pengerjaan_file', 'hasil_pengerjaan_file_name', '<?php echo htmlspecialchars($order_detail['HasilPengerjaan'] ? basename($order_detail['HasilPengerjaan']) : ''); ?>');
            setupFileUpload('revisi1_drop_area', 'revisi1_file', 'revisi1_file_name', '<?php echo htmlspecialchars($order_detail['Revisi1'] ? basename($order_detail['Revisi1']) : ''); ?>');
            setupFileUpload('revisi2_drop_area', 'revisi2_file', 'revisi2_file_name', '<?php echo htmlspecialchars($order_detail['Revisi2'] ? basename($order_detail['Revisi2']) : ''); ?>');

            document.querySelectorAll('.custom-select-wrapper').forEach(wrapper => {
                const selectElement = wrapper.querySelector('select.status-select');
                const selectDisplay = wrapper.querySelector('.select-display');
                const statusDot = selectDisplay.querySelector('.status-dot');
                const statusTextContent = selectDisplay.querySelector('.status-text-content');

                function updateSelectAppearance(el) {
                    statusTextContent.textContent = el.options[el.selectedIndex].textContent;
                    statusDot.className = 'status-dot'; 
                    statusDot.classList.add(getStatusColorClass(el.value));
                }
                
                updateSelectAppearance(selectElement); 

                selectElement.addEventListener('change', function() {
                    updateSelectAppearance(this);
                    if (this.id === 'status_pengerjaan') {
                        this.form.submit();
                    }
                });

                selectElement.addEventListener('focus', function() { wrapper.classList.add('focused'); });
                selectElement.addEventListener('blur', function() { wrapper.classList.remove('focused'); });
            });
        });
    </script>
</body>
</html>