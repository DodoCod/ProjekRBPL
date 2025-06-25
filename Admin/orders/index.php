<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
checkAdminLogin();

require_once dirname(dirname(__DIR__)) . '/includes/db_config.php';

// --- PENGATURAN DEBUGGING (AKTIFKAN SAAT PENGEMBANGAN, NONAKTIFKAN DI PRODUKSI) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_nama = $_SESSION['admin_nama'] ?? 'Admin';
$admin_email = $_SESSION['admin_email'] ?? '';

// --- Inisialisasi variabel pesan (dari redirect) ---
$form_success = '';
$form_error = '';

if (isset($_GET['success'])) {
    $form_success = htmlspecialchars(urldecode($_GET['success'])); // Decode URL untuk pesan sukses
    if ($form_success === 'true') { // Jika hanya string 'true', berikan pesan default
        $form_success = 'Operasi berhasil!';
    }
} elseif (isset($_GET['error'])) {
    $form_error = htmlspecialchars(urldecode($_GET['error'])); // Decode URL untuk pesan error
}


// --- Ambil data orders dari database ---
$all_orders = []; 

global $conn; 

$sql_get_all_orders = "SELECT
                            con.IdConsult,
                            c.Nama AS customer_name,
                            c.Email AS customer_email,
                            c.NoTelp AS customer_phone,
                            c.NamaUsaha AS details_brand,
                            c.Lokasi AS details_location,
                            con.StatusPengerjaan AS status_pengerjaan,
                            c.JenisLayanan AS service_type
                       FROM `consult` AS con
                       JOIN `customer` AS c ON con.IdCust = c.IdCust
                       ORDER BY con.created_at DESC"; // Urutkan berdasarkan tanggal terbaru

if ($stmt = $conn->prepare($sql_get_all_orders)) {
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $all_orders[] = [
                'id' => '#' . $row['IdConsult'],
                'raw_id_consult' => $row['IdConsult'], // Simpan ID asli untuk link edit/detail
                'customer' => $row['customer_name'],
                'email' => $row['customer_email'],
                'phone' => $row['customer_phone'],
                'details_brand' => $row['details_brand'],
                'details_location' => $row['details_location'],
                'status' => $row['status_pengerjaan'],
                'service' => $row['service_type']
            ];
        }
    } else {
        // Log jika tidak ada data orders ditemukan
        error_log("DEBUG: Tidak ada data orders ditemukan di database.");
    }
    $stmt->close();
} else {
    // Tangani kesalahan persiapan query
    $form_error = "Kesalahan persiapan query pengambilan data order: " . $conn->error;
    error_log("ERROR: Kesalahan persiapan query di index.php: " . $conn->error);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Orders ExpertUs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #F8FAFB;
        }
        /* Definisi Keyframes untuk Fade In */
        @keyframes fadeInMoveUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Styling Sidebar */
        .sidebar {
            width: 250px;
            background-color: #26474E;
            background-image: linear-gradient(to bottom, #26474E, #1A365D);
            color: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            border-top-right-radius: 1rem;
            border-bottom-right-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            opacity: 0;
            animation: fadeInMoveUp 0.6s ease-out 0.1s forwards;
        }
        .sidebar-profile {
            margin-bottom: 2.5rem; text-align: center; padding: 0 1.5rem;
        }
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
        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background-color: #3B5F6C;
            color: white;
        }
        .sidebar-nav a i { margin-right: 1rem; font-size: 1.35rem; }
        .content {
            margin-left: 250px;
            padding: 2.5rem;
            min-height: 100vh;
            width: calc(100% - 250px);
        }

        /* Styles for the Orders List/Table */
        .orders-container {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            padding: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0; 
            animation: fadeInMoveUp 0.6s ease-out 0.2s forwards;
        }
        .orders-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .orders-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1F2937;
        }
        .add-order-btn {
            background-color: #10B981; 
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
        }
        .add-order-btn:hover {
            background-color: #059669; 
        }

        .order-list-item {
            display: grid;
            grid-template-columns: 80px 1.5fr 2fr 120px 1.2fr;
            align-items: center;
            padding: 0.75rem 0; 
            border-bottom: 1px solid #E5E7EB; 
            gap: 1rem;
            font-size: 0.9rem; 
            color: #4B5563; 
            transition: background-color 0.2s;
        }
        .order-list-item:last-child {
            border-bottom: none;
        }
        /* Hover effect on order item */
        .order-list-item:hover {
            background-color: #F3F4F6; 
        }

        .order-id {
            font-weight: 600;
            color: #6B7280; 
            font-size: 0.85rem;
        }
        .customer-name {
            font-weight: 600;
            color: #1F2937;
        }
        .customer-contact {
            font-size: 0.8rem;
            color: #6B7280;
        }
        .details-brand {
            font-weight: 600;
            color: #4B5563; 
        }
        .details-location {
            font-size: 0.8rem;
            color: #6B7280;
        }
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0; 
        }
        .status-dot.on-progress {
            background-color: #3B82F6; 
        }
        .status-dot.complete {
            background-color: #10B981; 
        }
        /* Menambahkan style untuk status 'Finished' agar sesuai dengan 'Complete' */
        .status-dot.finished {
            background-color: #10B981; 
        }
        /* Menambahkan style untuk status 'Pending' */
        .status-dot.pending {
            background-color: #FBBF24; 
        }
        /* Menambahkan style untuk status 'Cancelled' */
        .status-dot.cancelled {
            background-color: #EF4444;
        }

        .service-tag {
            background-color: #D1FAE5; 
            color: #065F46; 
            padding: 0.35rem 1rem;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            text-align: center;
        }
        /* Style untuk alert messages */
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


        /* Responsive adjustments */
        @media (max-width: 768px) {
            .orders-container {
                margin: 1.5rem; 
                padding: 1rem;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                border-radius: 0;
                padding-bottom: 0.5rem;
            }
            .sidebar-profile {
                margin-bottom: 1rem;
                padding-bottom: 0.5rem;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            .sidebar-nav {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.5rem;
                padding: 0 0.5rem;
            }
            .sidebar-nav a {
                padding: 0.5rem 0.75rem;
                margin-bottom: 0;
                font-size: 0.875rem;
            }
            .sidebar-nav a i {
                margin-right: 0.5rem;
                font-size: 1rem;
            }
            .content {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem 1rem; 
            }
            .orders-header {
                flex-direction: column;
                align-items: flex-start;
                margin-bottom: 1rem;
            }
            .add-order-btn {
                width: 100%;
                justify-content: center;
                margin-top: 1rem;
            }
            .order-list-item {
                grid-template-columns: 1fr;
                gap: 0.5rem;
                padding: 1rem 0.5rem;
                font-size: 0.9rem;
            }
            .order-id, .customer-name, .details-brand, .status-indicator, .service-tag {
                width: 100%;
                text-align: left;
            }
            .status-indicator {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="flex flex-col md:flex-row">
    <?php include dirname(__DIR__) . '/includes/sidebar.php';  ?>

    <div class="content flex-grow">
        <?php if (!empty($form_success)): ?>
            <div class="alert-success"><?php echo htmlspecialchars($form_success); ?></div>
        <?php endif; ?>
        <?php if (!empty($form_error)): ?>
            <div class="alert-error"><?php echo htmlspecialchars($form_error); ?></div>
        <?php endif; ?>

        <div class="orders-container">
            <div class="orders-header">
                <h2>Orders</h2>
                <a href="create.php" class="add-order-btn">
                    <i class="fas fa-plus-circle"></i> Add Order
                </a>
            </div>

            <?php if (empty($all_orders)): ?>
                <p class="text-center text-gray-500 py-8">Tidak ada order ditemukan.</p>
            <?php else: ?>
                <?php foreach ($all_orders as $order): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/orders/detail.php?IdConsult=<?php echo urlencode($order['raw_id_consult']); ?>" class="order-list-item block">
                        <div class="order-id"><?php echo htmlspecialchars($order['id']); ?></div>
                        <div>
                            <div class="customer-name"><?php echo htmlspecialchars($order['customer']); ?></div>
                            <div class="customer-contact"><?php echo htmlspecialchars($order['email']); ?> | <?php echo htmlspecialchars($order['phone']); ?></div>
                        </div>
                        <div>
                            <div class="details-brand"><?php echo htmlspecialchars($order['details_brand']); ?></div>
                            <div class="details-location"><?php echo htmlspecialchars($order['details_location']); ?></div>
                        </div>
                        <div class="status-indicator">
                            <?php
                                $status_class = '';
                                switch ($order['status']) {
                                    case 'On Progress':
                                        $status_class = 'on-progress';
                                        break;
                                    case 'Finished':
                                    case 'Complete': 
                                        $status_class = 'finished';
                                        break;
                                    case 'Pending':
                                        $status_class = 'pending';
                                        break;
                                    case 'Cancelled':
                                        $status_class = 'cancelled';
                                        break;
                                    default:
                                        $status_class = 'on-progress'; 
                                }
                            ?>
                            <div class="status-dot <?php echo $status_class; ?>"></div>
                            <span><?php echo htmlspecialchars($order['status']); ?></span>
                        </div>
                        <div class="service-tag"><?php echo htmlspecialchars($order['service']); ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>