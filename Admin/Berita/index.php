<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
checkAdminLogin(); // Memeriksa status login admin

require_once dirname(dirname(__DIR__)) . '/includes/db_config.php'; 

// --- PENGATURAN DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$admin_email = $_SESSION['admin_email'] ?? '';

$all_information = [];
$form_success = '';
$form_error = '';

define('UPLOAD_BASE_DIR_PHYSICAL', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR);
define('UPLOAD_RELATIVE_PATH_FOR_DB', '/public/files/');


// Tangani pesan success/error dari redirect GET
if (isset($_GET['success'])) {
    $form_success = sanitize_input($_GET['success']);
} elseif (isset($_GET['error'])) {
    $form_error = sanitize_input($_GET['error']);
}

// --- AMBIL DATA INFORMASI/BERITA DARI DATABASE ---
global $conn;
$sql_get_info = "SELECT IdBerita, Img, Judul, Text, Date FROM `berita` ORDER BY Date DESC, IdBerita DESC";
if ($result = $conn->query($sql_get_info)) {
    while ($row = $result->fetch_assoc()) {
        if (empty($row['Img']) || $row['Img'] === '0') {
            $row['Img_Display_Path'] = BASE_URL . '/public/images/default_placeholder.jpg'; 
        } else {
            $row['Img_Display_Path'] = BASE_URL . $row['Img']; 
        }
        $all_information[] = $row;
    }
    $result->free();
} else {
    $form_error = "Gagal mengambil data berita dari database: " . $conn->error;
}

// --- LOGIKA HAPUS BERITA (POST request) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_berita') {
    $berita_id_to_delete = filter_var($_POST['berita_id'] ?? null, FILTER_VALIDATE_INT);

    if ($berita_id_to_delete) {
        $sql_get_img_path = "SELECT Img FROM `berita` WHERE IdBerita = ?";
        if ($stmt_get_img = $conn->prepare($sql_get_img_path)) {
            $stmt_get_img->bind_param('i', $berita_id_to_delete);
            $stmt_get_img->execute();
            $result_img = $stmt_get_img->get_result();
            $img_path_from_db = $result_img->fetch_assoc()['Img'] ?? null;
            $stmt_get_img->close();
        }

        $conn->begin_transaction();
        try {
            // Hapus dari database
            $sql_delete = "DELETE FROM `berita` WHERE IdBerita = ?";
            if ($stmt_delete = $conn->prepare($sql_delete)) {
                $stmt_delete->bind_param('i', $berita_id_to_delete);
                if ($stmt_delete->execute()) {
                    // Jika berhasil dihapus dari DB, hapus file fisik
                    if (!empty($img_path_from_db) && $img_path_from_db !== '0') {                       
                        $sub_path_from_db = substr($img_path_from_db, strlen(UPLOAD_RELATIVE_PATH_FOR_DB)); // Mendapatkan "Berita/gambar.jpg"
                        $physical_path_to_delete = UPLOAD_BASE_DIR_PHYSICAL . $sub_path_from_db;

                        if (file_exists($physical_path_to_delete)) {
                            if (unlink($physical_path_to_delete)) {
                                error_log("SUCCESS: File gambar berita '{$physical_path_to_delete}' berhasil dihapus.");
                            } else {
                                error_log("WARNING: Gagal menghapus file gambar berita '{$physical_path_to_delete}'.");
                            }
                        } else {
                            error_log("WARNING: File gambar berita '{$physical_path_to_delete}' tidak ditemukan, mungkin sudah dihapus atau path salah.");
                        }
                    }
                    $conn->commit();
                    header('location: ' . BASE_URL . '/admin/Berita/index.php?success=' . urlencode('Berita berhasil dihapus.'));
                    exit();
                } else {
                    throw new Exception("Gagal menghapus berita dari database: " . $stmt_delete->error);
                }
            } else {
                throw new Exception("Kesalahan persiapan query hapus berita: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            header('location: ' . BASE_URL . '/admin/Berita/index.php?error=' . urlencode('Terjadi error saat menghapus berita: ' . $e->getMessage()));
            exit();
        }
    } else {
        header('location: ' . BASE_URL . '/admin/Berita/index.php?error=' . urlencode('ID Berita tidak valid untuk dihapus.'));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Information ExpertUs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F8FAFB;
        }
        @keyframes fadeInMoveUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Styling Sidebar (dari kode sebelumnya, pastikan konsisten) */
        .sidebar {
            width: 250px; background-color: #26474E; background-image: linear-gradient(to bottom, #26474E, #1A365D);
            color: white; padding: 1.5rem 0; position: fixed; height: 100%; display: flex;
            flex-direction: column; border-top-right-radius: 1rem; border-bottom-right-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            opacity: 0; animation: fadeInMoveUp 0.6s ease-out 0.1s forwards;
        }
        .sidebar-profile { margin-bottom: 2.5rem; text-align: center; padding: 0 1.5rem; }
        .sidebar-profile .avatar {
            width: 80px; height: 80px; background-color: #6B7280; border-radius: 50%;
            margin: 0 auto 0.75rem; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; color: white;
        }
        .sidebar-profile .admin-label { font-size: 0.875rem; color: #D1D5DB; }
        .sidebar-profile .admin-name { font-size: 1.125rem; font-weight: 600; color: white; }
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

        /* Styles for the Information Table */
        .info-container {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.2s forwards;
        }
        .info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .info-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1F2937;
        }
        .add-btn {
            background-color: #10B981; 
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
            text-decoration: none; 
        }
        .add-btn:hover {
            background-color: #059669; 
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table thead th {
            padding: 1rem;
            text-align: left;
            background-color: #F9FAFB; 
            font-size: 0.75rem; 
            text-transform: uppercase;
            color: #6B7280; 
            font-weight: 600;
        }
        .info-table tbody td {
            padding: 1rem;
            vertical-align: top;
            border-bottom: 1px solid #E5E7EB;
            font-size: 0.875rem;
            color: #4B5563;
        }
        .info-table tbody tr:last-child td {
            border-bottom: none;
        }
        .info-table img {
            width: 100px; 
            height: 70px;
            object-fit: cover;
            border-radius: 0.25rem;
        }
        .action-buttons {
            display: flex;
            gap: 0.75rem;
        }
        .action-buttons a {
            background-color: #EEF2FF; 
            color: #4F46E5; 
            padding: 0.6rem 0.75rem;
            border-radius: 0.375rem;
            text-decoration: none;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s, color 0.2s;
        }
        .action-buttons a:hover {
            background-color: #E0E7FF; 
        }
        .action-buttons button { 
            background-color: #FEF2F2; 
            color: #DC2626;
            padding: 0.6rem 0.75rem;
            border-radius: 0.375rem;
            text-decoration: none;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s, color 0.2s;
            border: none;
            cursor: pointer;
        }
        .action-buttons button.delete:hover { 
            background-color: #FEE2E2; 
        }

        /* Alert Messages (dari form sebelumnya) */
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
    </style>
</head>
<body class="flex">
    <?php include dirname(__DIR__) . '/includes/sidebar.php'; ?>

    <div class="content">
        <?php if (!empty($form_error)): ?>
            <div class="alert-error"><?php echo htmlspecialchars($form_error); ?></div>
        <?php endif; ?>
        <?php if (!empty($form_success)): ?>
            <div class="alert-success"><?php echo htmlspecialchars($form_success); ?></div>
        <?php endif; ?>

        <div class="info-container">
            <div class="info-header">
                <h2>Information</h2>
                <a href="<?php echo BASE_URL; ?>/admin/Berita/input.php" class="add-btn">
                    <i class="fas fa-plus-circle"></i> Add
                </a>
            </div>

            <table class="info-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Text</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_information)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-gray-500">Tidak ada berita ditemukan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($all_information as $info): ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($info['Img_Display_Path']); ?>" alt="<?php echo htmlspecialchars($info['Judul']); ?>"></td>
                                <td><?php echo htmlspecialchars($info['Judul']); ?></td>
                                <td><?php echo htmlspecialchars(mb_strimwidth($info['Text'], 0, 100, '...')); ?></td>
                                <td><?php echo htmlspecialchars($info['Date']); ?></td>
                                <td class="action-buttons">
                                    <a href="<?php echo BASE_URL; ?>/admin/Berita/input.php?id=<?php echo urlencode($info['IdBerita']); ?>" class="edit"><i class="fas fa-pencil-alt"></i></a>
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin menghapus berita ini?');">
                                        <input type="hidden" name="action" value="delete_berita">
                                        <input type="hidden" name="berita_id" value="<?php echo htmlspecialchars($info['IdBerita']); ?>">
                                        <button type="submit" class="delete"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>