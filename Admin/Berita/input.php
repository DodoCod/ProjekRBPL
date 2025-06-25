<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

checkAdminLogin(); 

// --- PENGATURAN DEBUGGING ---
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
$allowed_image_types = ['image/jpeg', 'image/png', 'image/gif'];
$max_image_size = 5 * 1024 * 1024; // 5 MB

define('UPLOAD_BASE_DIR_PHYSICAL', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR);
define('UPLOAD_RELATIVE_PATH_FOR_DB', '/public/files/');

// Ambil ID berita dari URL (jika mode edit)
$berita_id = filter_var(isset($_GET['id']) ? $_GET['id'] : null, FILTER_VALIDATE_INT);

// Data berita untuk form (default kosong atau dari DB jika edit)
$berita_data = [
    'IdBerita' => null,
    'Judul' => '',
    'Img' => null, // Ini akan berisi path relatif dari DB
    'Date' => date('Y-m-d'), // Default tanggal hari ini
    'Text' => ''
];

// --- AMBIL DATA BERITA DARI DATABASE (MODE EDIT) ---
if ($berita_id) {
    global $conn;
    $sql_get_berita = "SELECT IdBerita, Judul, Img, Date, Text FROM `berita` WHERE IdBerita = ?";
    if ($stmt_get_berita = $conn->prepare($sql_get_berita)) {
        $stmt_get_berita->bind_param('i', $berita_id);
        $stmt_get_berita->execute();
        $result = $stmt_get_berita->get_result();
        if ($result->num_rows > 0) {
            $berita_data = $result->fetch_assoc();
            // Konversi '0' atau string kosong untuk path gambar menjadi null
            if (empty($berita_data['Img']) || $berita_data['Img'] === '0') {
                $berita_data['Img'] = null;
            }
        } else {
            $form_error = "Berita tidak ditemukan.";
            $berita_id = null; // Set ID ke null agar form dianggap mode tambah baru
        }
        $stmt_get_berita->close();
    } else {
        $form_error = "Kesalahan persiapan query pengambilan berita: " . $conn->error;
    }
}

/**
 * Fungsi helper untuk menangani proses upload file.
 * Mengembalikan path URL relatif dari ROOT WEB SERVER (tanpa BASE_URL) jika berhasil,
 * atau null jika gagal/tidak ada upload.
 * Menambahkan pesan error ke global $upload_errors array.
 *
 * @param string $file_input_html_name Nama atribut 'name' dari input file HTML.
 * @param string $upload_sub_dir Nama sub-direktori di dalam public/files/ (misal: 'Berita').
 * @param string $file_prefix Prefix untuk nama file yang disimpan (misal: 'berita').
 * @param string $suggested_name Nama yang disarankan untuk file (misal: judul berita yang sudah disanitasi).
 * @param string|null $existing_db_path Path file yang sudah ada di database (URL relatif dari root web server).
 * @return string|null Path file URL relatif yang baru diupload atau path lama jika tidak ada upload/gagal.
 */
function handle_upload_single($file_input_html_name, $upload_sub_dir, $file_prefix, $suggested_name, $existing_db_path) {
    global $upload_errors, $allowed_image_types, $max_image_size;

    $uploaded_file_path = $existing_db_path; 

    error_log("--- Mulai handle_upload_single untuk: " . $file_input_html_name . " ---");
    error_log("Existing DB Relative Path: " . ($existing_db_path ?? 'NULL'));

    // Cek apakah ada file yang diunggah
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
            return $uploaded_file_path; // Jika ada error sistem, pertahankan path lama
        }

        $file_tmp_name = $_FILES[$file_input_html_name]['tmp_name'];
        $file_name_original = basename($_FILES[$file_input_html_name]['name']);
        $file_size = $_FILES[$file_input_html_name]['size'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_type = finfo_file($finfo, $file_tmp_name);
        finfo_close($finfo);

        $file_ext = strtolower(pathinfo($file_name_original, PATHINFO_EXTENSION));

        // --- Validasi Konten File ---
        if (!in_array($file_type, $allowed_image_types)) {
            $upload_errors[$file_input_html_name][] = "Tipe file '{$file_type}' tidak diizinkan. Hanya " . implode(', ', $allowed_image_types) . ".";
            error_log("Validation Error: Invalid file type for {$file_input_html_name}.");
        }
        if ($file_size > $max_image_size) {
            $upload_errors[$file_input_html_name][] = "Ukuran file terlalu besar (maks " . ($max_image_size / 1024 / 1024) . "MB).";
            error_log("Validation Error: File size too large for {$file_input_html_name}. Size: {$file_size}");
        }

        // Jika ada validasi gagal, jangan lanjutkan upload
        if (!empty($upload_errors[$file_input_html_name])) {
            return $uploaded_file_path;
        }

        // --- Penamaan File Baru ---
        $safe_suggested_name = preg_replace('/[^a-zA-Z0-9_ -]/', '', $suggested_name); // Hapus karakter tidak diizinkan
        $safe_suggested_name = str_replace(' ', '_', $safe_suggested_name); // Ganti spasi dengan underscore
        $safe_suggested_name = substr($safe_suggested_name, 0, 50); // Batasi panjang nama file

        $new_file_name_base = $file_prefix . '_' . $safe_suggested_name . '_' . time(); // Tambah timestamp untuk keunikan
        $new_file_name = $new_file_name_base . '.' . $file_ext;
        
        $full_upload_dir_physical = UPLOAD_BASE_DIR_PHYSICAL . $upload_sub_dir . DIRECTORY_SEPARATOR;
        $destination_physical = $full_upload_dir_physical . $new_file_name;
        // Ini adalah path yang akan disimpan ke database (relatif terhadap root web server)
        $relative_path_for_db = UPLOAD_RELATIVE_PATH_FOR_DB . $upload_sub_dir . '/' . $new_file_name;

        error_log("Target physical directory: " . $full_upload_dir_physical);
        error_log("Destination physical path: " . $destination_physical);
        error_log("Relative path for DB: " . $relative_path_for_db);

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
        
        // Hapus file lama jika ada dan yang baru adalah file yang berbeda
        $old_file_physical_path = UPLOAD_BASE_DIR_PHYSICAL . substr($existing_db_path, strlen(UPLOAD_RELATIVE_PATH_FOR_DB));
        
        if (!empty($existing_db_path) && file_exists($old_file_physical_path) && $old_file_physical_path !== $destination_physical) {
            if (unlink($old_file_physical_path)) {
                error_log("SUCCESS: File lama '{$old_file_physical_path}' berhasil dihapus.");
            } else {
                error_log("WARNING: Gagal menghapus file lama '{$old_file_physical_path}'.");
            }
        }

        // Pindahkan file yang diupload dari direktori temp ke tujuan akhir
        if (move_uploaded_file($file_tmp_name, $destination_physical)) {
            $uploaded_file_path = $relative_path_for_db;
            error_log("SUCCESS: File '{$file_name_original}' berhasil diunggah ke '{$uploaded_file_path}'.");
        } else {
            $upload_errors[$file_input_html_name][] = "Gagal memindahkan file. Ini bisa jadi masalah izin, jalur tujuan, atau file sementara hilang. Error PHP: " . (error_get_last()['message'] ?? 'Tidak diketahui');
            error_log("FAILED: move_uploaded_file failed for {$file_input_html_name}. PHP Error: " . (error_get_last()['message'] ?? 'N/A'));
        }
    }
    error_log("--- Selesai handle_upload_single untuk: " . $file_input_html_name . ", Final Path: " . ($uploaded_file_path ?? 'NULL') . " ---");
    return $uploaded_file_path;
}


// --- PENANGANAN POST REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_berita') {
    global $conn, $upload_errors, $data_errors;

    $berita_id_post = filter_var($_POST['berita_id'] ?? null, FILTER_VALIDATE_INT);
    $judul = sanitize_input($_POST['judul'] ?? '');
    $text = sanitize_input($_POST['text'] ?? '');
    $date = sanitize_input($_POST['date'] ?? '');
    $admin_id_penulis = $_SESSION['admin_id'] ?? null; // Ambil ID admin dari sesi

    // --- Validasi Data Form ---
    if (empty($judul)) $data_errors['judul'] = "Judul wajib diisi.";
    if (empty($text)) $data_errors['text'] = "Teks berita wajib diisi.";
    if (empty($date)) {
        $data_errors['date'] = "Tanggal wajib diisi.";
    } elseif (!strtotime($date)) { // Cek format tanggal valid
        $data_errors['date'] = "Format tanggal tidak valid.";
    }

    // --- Proses Upload Gambar ---
    $current_img_in_db_path = $berita_data['Img']; 

    // Panggil fungsi handle_upload_single
    $uploaded_img_path = handle_upload_single(
        'image_file', 'Berita', 'berita_img',
        $judul, 
        $current_img_in_db_path 
    );

    // Jika mode tambah baru (IdBerita masih null) dan belum ada gambar terupload
    // DAN tidak ada error upload untuk file ini, maka gambar wajib diunggah.
    if (empty($berita_id_post) && empty($uploaded_img_path) && empty($upload_errors['image_file'])) {
        $data_errors['image_file'] = "Gambar wajib diunggah untuk berita baru.";
    }
    // Jika ada error upload, path gambar yang akan disimpan di DB harus yang LAMA
    if (!empty($upload_errors['image_file'])) {
        $uploaded_img_path = $current_img_in_db_path; 
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
                    $full_error_message .= "- " . htmlspecialchars(ucfirst(str_replace('_', ' ', $display_field_name))) . ": " . implode("<br>- ", $messages) . "<br>";
                }
            }
        }
        $form_error = $full_error_message;

        // Pertahankan data yang diinput untuk mengisi ulang form
        $berita_data['Judul'] = $judul;
        $berita_data['Text'] = $text;
        $berita_data['Date'] = $date;
        $berita_data['Img'] = $uploaded_img_path; // Perbarui dengan path baru (atau path lama jika error)
        
    } else {
        // --- INSERT atau UPDATE ke Database ---
        try {
            $updated_at_value = date('Y-m-d H:i:s');
            $stmt = null;
            $new_berita_id = $berita_id_post; // Untuk mode edit, ID sudah ada

            if ($berita_id_post) { // MODE UPDATE
                $sql_update = "UPDATE `berita` SET `Judul` = ?, `Img` = ?, `Date` = ?, `Text` = ?, `updated_at` = ? WHERE `IdBerita` = ?";
                if ($stmt = $conn->prepare($sql_update)) {
                    $stmt->bind_param('sssssi', $judul, $uploaded_img_path, $date, $text, $updated_at_value, $berita_id_post);
                } else {
                    throw new Exception("Kesalahan persiapan query update berita: " . $conn->error);
                }
            } else { // MODE INSERT
                $sql_insert = "INSERT INTO `berita` (`IdAdmin`, `Judul`, `Img`, `Date`, `Text`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?)";
                if ($stmt = $conn->prepare($sql_insert)) {
                    $stmt->bind_param('isssss', $admin_id_penulis, $judul, $uploaded_img_path, $date, $text, $updated_at_value);
                } else {
                    throw new Exception("Kesalahan persiapan query insert berita: " . $conn->error);
                }
            }

            if ($stmt->execute()) {
                if (!$berita_id_post) { // Jika ini adalah berita baru, ambil ID yang baru di-generate
                    $new_berita_id = $conn->insert_id;
                    // Lakukan UPDATE lagi untuk mengubah nama file gambar dengan IdBerita yang baru
                    if (!empty($uploaded_img_path)) {
                        $old_relative_path_for_db = $uploaded_img_path;
                        $old_physical_path = UPLOAD_BASE_DIR_PHYSICAL . substr($old_relative_path_for_db, strlen(UPLOAD_RELATIVE_PATH_FOR_DB));
                        
                        $file_ext_from_old_path = strtolower(pathinfo($old_physical_path, PATHINFO_EXTENSION));
                        $safe_judul_for_filename = preg_replace('/[^a-zA-Z0-9_ -]/', '', $judul);
                        $safe_judul_for_filename = str_replace(' ', '_', $safe_judul_for_filename);
                        $safe_judul_for_filename = substr($safe_judul_for_filename, 0, 50);

                        $new_filename_with_id = 'berita_img_' . $new_berita_id . '_' . $safe_judul_for_filename . '.' . $file_ext_from_old_path;
                        $new_relative_path_for_db_after_rename = UPLOAD_RELATIVE_PATH_FOR_DB . 'Berita/' . $new_filename_with_id;
                        $new_physical_path_after_rename = UPLOAD_BASE_DIR_PHYSICAL . 'Berita/' . $new_filename_with_id;

                        if (rename($old_physical_path, $new_physical_path_after_rename)) {
                            $sql_update_img = "UPDATE `berita` SET `Img` = ? WHERE `IdBerita` = ?";
                            if ($stmt_update_img = $conn->prepare($sql_update_img)) {
                                $stmt_update_img->bind_param('si', $new_relative_path_for_db_after_rename, $new_berita_id);
                                $stmt_update_img->execute();
                                $stmt_update_img->close();
                                $uploaded_img_path = $new_relative_path_for_db_after_rename; // Update path untuk konfirmasi
                                error_log("SUCCESS: Gambar berita baru di-rename ke: " . $new_relative_path_for_db_after_rename);
                            } else {
                                error_log("WARNING: Gagal menyiapkan query update path gambar setelah rename: " . $conn->error);
                            }
                        } else {
                            error_log("WARNING: Gagal rename file dari '{$old_physical_path}' ke '{$new_physical_path_after_rename}'.");
                        }
                    }
                }
                $form_success = "Berita berhasil " . ($berita_id_post ? "diperbarui" : "ditambahkan") . "!";
                header('location: ' . BASE_URL . '/admin/Berita/index.php?success=' . urlencode($form_success));
                exit;
            } else {
                throw new Exception('Gagal menyimpan berita: ' . $stmt->error);
            }
        } catch (Exception $e) {
            $form_error = "Terjadi error saat menyimpan berita: " . $e->getMessage();
            error_log("Error saving berita: " . $e->getMessage());
            // Pertahankan data yang diinput untuk mengisi ulang form
            $berita_data['Judul'] = $judul;
            $berita_data['Text'] = $text;
            $berita_data['Date'] = $date;
            $berita_data['Img'] = $uploaded_img_path; // Penting: ini sudah berisi path relatif baru atau lama jika ada error
        } finally {
            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }
    }
}

// Tangani pesan success/error dari redirect GET
if (isset($_GET['success'])) {
    $form_success = sanitize_input($_GET['success']);
} elseif (isset($_GET['error'])) {
    $form_error = sanitize_input($_GET['error']);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $berita_id ? 'Edit' : 'Input'; ?> Berita</title>
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
            background-image: url('<?php echo BASE_URL; ?>/public/Background/backgroundbatik1.png'); 
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
        
        /* Gaya untuk input, select, textarea di dalam form-group */
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group input[type="date"],
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

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #808080;
            font-weight: 400;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
            border-color: #3b82f6;
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

        /* Custom panah untuk select (jika ada select tunggal) */
        .select-wrapper {
            position: relative;
            width: calc(100% - 20px);
            margin: 10px;
        }
        .select-wrapper select {
            width: 100%;
            padding-right: 30px;
            margin: 0;
        }
        .select-wrapper::after {
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
        /* Current image preview styling */
        .existing-file-preview {
            margin-top: 10px; 
            font-size: 0.8em; 
            color: #6B7280;
        }
        .existing-file-preview a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
        .existing-file-preview a:hover {
            text-decoration: underline;
        }


        .file-chosen .upload-icon,
        .file-chosen p.default-text, 
        .file-chosen .choose-file-btn {
            display: none !important;
        }
        .file-chosen .file-name-display {
            display: block !important;
        }
        /* Tambahan: pastikan existing-file-preview juga hilang jika ada file baru di-upload */
        .file-chosen .existing-file-preview {
            display: none !important;
        }


        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-container {
                max-width: 95%;
            }
            .form-group input,
            .form-group select,
            .form-group textarea {
                width: calc(100% - 20px); 
            }
            .form-body h3 {
                margin-left: 10px;
                margin-right: 10px;
            }
            .file-upload-area {
                margin-left: 10px;
                margin-right: 10px;
            }
            .error-message {
                margin-left: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="overlay"></div>
    
    <div class="form-header">
        <a href="<?php echo BASE_URL; ?>/admin/Berita/index.php" class="back-arrow"><i class="fas fa-chevron-left"></i></a> <div class="logo">
            <img src="<?php echo BASE_URL; ?>/public/Logo/Logo2.png" alt="ExpertUs">
        </div>
    </div>
    
    <div class="wrapper">
        <div class="form-container">
            <div class="form-body">
                <h2><?php echo $berita_id ? 'Edit' : 'Input'; ?> Berita</h2>

                <?php if (!empty($form_error)): ?>
                    <div class="alert-error"><?php echo $form_error; ?></div>
                <?php endif; ?>
                <?php if (!empty($form_success)): ?>
                    <div class="alert-success"><?php echo $form_success; ?></div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?><?php echo $berita_id ? '?id=' . urlencode($berita_id) : ''; ?>" method="post" autocomplete="off" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_berita">
                    <input type="hidden" name="berita_id" value="<?php echo htmlspecialchars($berita_data['IdBerita'] ?? ''); ?>">

                    <div class="form-group">
                        <label for="image_file">Image</label>
                        <div class="file-upload-area" id="image_file_drop_area">
                            <input type="file" name="image_file" id="image_file" accept="image/*">
                            <img src="<?php echo BASE_URL; ?>/public/Logo/upload.png" alt="Upload Icon" class="upload-icon">
                            <p class="default-text">Drag or Drop your file here</p> <label for="image_file" class="choose-file-btn">Choose File</label>
                            <span class="file-name-display" id="image_file_name"
                                data-original-file-name="<?php echo htmlspecialchars($berita_data['Img'] ? basename($berita_data['Img']) : ''); ?>">
                                <?php echo htmlspecialchars($berita_data['Img'] ? basename($berita_data['Img']) : 'Tidak ada file'); ?>
                            </span>
                            <?php if ($berita_data['Img']): // Tampilkan gambar yang sudah ada ?>
                                <p class="existing-file-preview" style="margin-top: 10px; font-size: 0.8em;">
                                    Current Image: <a href="<?php echo htmlspecialchars(BASE_URL . $berita_data['Img']); ?>" target="_blank"><?php echo htmlspecialchars(basename($berita_data['Img'])); ?></a>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($upload_errors['image_file'])): ?>
                            <?php foreach($upload_errors['image_file'] as $err_msg): ?>
                                <p class="error-message"><?php echo htmlspecialchars($err_msg); ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (isset($data_errors['image_file'])): ?>
                            <p class="error-message"><?php echo htmlspecialchars($data_errors['image_file']); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="judul">Title</label>
                        <input type="text" name="judul" id="judul" placeholder="Workshop Foto Katalog" value="<?php echo htmlspecialchars($berita_data['Judul'] ?? ''); ?>" required>
                        <?php if (isset($data_errors['judul'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['judul']) . '</p>'; ?>
                    </div>

                    <div class="form-group">
                        <label for="text">Text</label>
                        <textarea name="text" id="text" placeholder="Lorem ipsum dolor sit amet..." required><?php echo htmlspecialchars($berita_data['Text'] ?? ''); ?></textarea>
                        <?php if (isset($data_errors['text'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['text']) . '</p>'; ?>
                    </div>

                    <div class="form-group">
                        <label for="date">Date</label>
                        <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($berita_data['Date'] ?? ''); ?>" required>
                        <?php if (isset($data_errors['date'])) echo '<p class="error-message">' . htmlspecialchars($data_errors['date']) . '</p>'; ?>
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
                const dragDropText = dropArea.querySelector('.default-text'); // Gunakan class default-text
                const chooseFileBtn = dropArea.querySelector('label.choose-file-btn');
                const existingFilePreview = dropArea.querySelector('.existing-file-preview'); // Ambil elemen preview gambar lama

                const updateDisplay = (hasFile, fileName = '') => {
                    if (hasFile && fileName && fileName !== 'Tidak ada file') {
                        dropArea.classList.add('file-chosen');
                        if (uploadIcon) uploadIcon.style.display = 'none';
                        if (dragDropText) dragDropText.style.display = 'none';
                        if (chooseFileBtn) chooseFileBtn.style.display = 'none';
                        if (existingFilePreview) existingFilePreview.style.display = 'none'; // Sembunyikan preview gambar lama
                        fileNameDisplay.textContent = fileName;
                        fileNameDisplay.style.display = 'block';
                    } else {
                        dropArea.classList.remove('file-chosen');
                        fileNameDisplay.textContent = 'Tidak ada file'; 
                        fileNameDisplay.style.display = 'none';
                        if (uploadIcon) uploadIcon.style.display = 'block';
                        if (dragDropText) dragDropText.style.display = 'block';
                        if (chooseFileBtn) chooseFileBtn.style.display = 'inline-block';
                        if (existingFilePreview && existingFilePreview.dataset.initialDisplay !== 'hidden') { // Tampilkan kembali jika ada dan bukan hidden awal
                             existingFilePreview.style.display = 'block';
                        }
                    }
                };

                // Initialize display based on existing file from DB
                // Jika ada file lama, inisialisasi tampilan dengan nama file lama.
                // Jika tidak ada, panggil updateDisplay(false)
                if (initialFileName && initialFileName !== '') {
                    updateDisplay(true, initialFileName);
                } else {
                    updateDisplay(false); // Pastikan tampil "Drag or Drop" jika tidak ada file awal
                    if (existingFilePreview) existingFilePreview.dataset.initialDisplay = 'hidden'; // Tandai bahwa ini tidak ada di awal
                }
                
                // Simulate click on hidden file input when drop area is clicked
                dropArea.addEventListener('click', (e) => {
                    if (e.target.tagName !== 'A' && e.target !== fileNameDisplay && e.target !== chooseFileBtn) {
                           fileInput.click();
                    }
                });

                // Display file name when chosen via input
                fileInput.addEventListener('change', (event) => {
                    if (event.target.files.length > 0) {
                        updateDisplay(true, event.target.files[0].name);
                    } else {
                        // Jika input file dibersihkan, kembali ke tampilan awal (dengan atau tanpa file lama)
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
                    fileInput.files = files; // Set files to hidden input
                    if (files.length > 0) {
                        updateDisplay(true, files[0].name);
                    } else {
                        // Jika drop area tidak ada file, kembali ke tampilan awal
                        const originalFileName = fileNameDisplay.dataset.originalFileName;
                        if (originalFileName && originalFileName !== '') {
                            updateDisplay(true, originalFileName);
                        } else {
                            updateDisplay(false);
                        }
                    }
                }, false);
            }

            // Setup for image upload area
            setupFileUpload(
                'image_file_drop_area', 
                'image_file', 
                'image_file_name', 
                '<?php echo htmlspecialchars($berita_data['Img'] ? basename($berita_data['Img']) : ''); ?>'
            );

            const judulInput = document.getElementById('judul');
            const imageFileNameDisplay = document.getElementById('image_file_name');

            if (judulInput && imageFileNameDisplay) {
                judulInput.addEventListener('input', function() {
                    const currentFileName = imageFileNameDisplay.dataset.originalFileName;
                    if (currentFileName && currentFileName.includes('berita_img_')) {
                        const parts = currentFileName.split('_');
                        if (parts.length > 2) {
                            const newBaseName = 'berita_img_' + parts[2] + '_' +
                                                this.value.toLowerCase().replace(/[^a-z0-9_ -]/g, '').replace(/ /g, '_').substring(0, 50);
                            const ext = currentFileName.split('.').pop();
                            const newPreviewName = newBaseName + '.' + ext;
                            imageFileNameDisplay.textContent = newPreviewName;
                        } else {
                            imageFileNameDisplay.textContent = this.value.toLowerCase().replace(/[^a-z0-9_ -]/g, '').replace(/ /g, '_').substring(0, 50) + '.' + currentFileName.split('.').pop();
                        }
                    } else {
                        // Jika tidak ada file atau nama file tidak mengikuti pola, cukup gunakan judul baru
                        imageFileNameDisplay.textContent = this.value.toLowerCase().replace(/[^a-z0-9_ -]/g, '').replace(/ /g, '_').substring(0, 50) + '.jpg'; // Asumsi .jpg atau sesuaikan
                    }
                });
            }
        });
    </script>
</body>
</html>