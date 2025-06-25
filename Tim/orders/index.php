<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
checkTimLogin(); // Menggunakan fungsi checkTimLogin()

require_once dirname(dirname(__DIR__)) . '/includes/db_config.php'; // Sertakan db_config untuk BASE_URL dan $conn

// --- PENGATURAN DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Inisialisasi variabel untuk tampilan sidebar
$tim_nama = $_SESSION['tim_nama'] ?? 'Tim Pengerjaan';
$tim_email = $_SESSION['tim_email'] ?? '';

$all_orders = [];
$form_success = '';
$form_error = '';

// Tangani pesan success/error dari redirect GET
if (isset($_GET['success'])) {
    $form_success = sanitize_input($_GET['success']);
} elseif (isset($_GET['error'])) {
    $form_error = sanitize_input($_GET['error']);
}

// Filter status order dari URL
$filter_status = isset($_GET['status']) ? sanitize_input($_GET['status']) : 'all';

// --- AMBIL DATA ORDER DARI DATABASE ---
global $conn; 

$sql_get_orders = "SELECT
                    con.IdConsult,
                    c.Nama AS customer_name,
                    c.Email AS customer_email,
                    c.NoTelp AS customer_phone,
                    c.NamaUsaha,
                    c.BidangUsaha,
                    c.Lokasi,
                    c.JenisLayanan,
                    con.StatusPengerjaan,
                    con.StatusPembayaran
                   FROM `consult` AS con
                   JOIN `customer` AS c ON con.IdCust = c.IdCust";

// Tambahkan kondisi WHERE jika filter status aktif
$where_clauses = [];
$bind_params = '';
$bind_values = [];

// Filter berdasarkan Status Pengerjaan yang lebih luas
if ($filter_status != 'all') {
    if ($filter_status == 'on_progress') {
        $where_clauses[] = "con.StatusPengerjaan = ?";
        $bind_params .= 's';
        $status_val = 'On Progress';
        $bind_values[] = &$status_val;
    } elseif ($filter_status == 'completed') {
        $where_clauses[] = "con.StatusPengerjaan = ?";
        $bind_params .= 's';
        $status_val = 'Completed'; // Nilai di DB untuk Completed
        $bind_values[] = &$status_val;
    } elseif ($filter_status == 'pending') {
        $where_clauses[] = "con.StatusPengerjaan = ?";
        $bind_params .= 's';
        $status_val = 'Pending';
        $bind_values[] = &$status_val;
    } elseif ($filter_status == 'cancelled') { // Tambahan filter untuk Cancelled
        $where_clauses[] = "con.StatusPengerjaan = ?";
        $bind_params .= 's';
        $status_val = 'Cancelled';
        $bind_values[] = &$status_val;
    }
}


if (!empty($where_clauses)) {
    $sql_get_orders .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql_get_orders .= " ORDER BY con.IdConsult DESC";

// Jalankan query menggunakan prepared statement jika ada parameter
if (!empty($bind_values)) {
    if ($stmt = $conn->prepare($sql_get_orders)) {
        $tmp_bind_values = [];
        foreach ($bind_values as $key => $value) {
            $tmp_bind_values[$key] = &$bind_values[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], array_merge([$bind_params], $tmp_bind_values));
        
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $all_orders[] = $row;
        }
        $stmt->close();
    } else {
        $form_error = "Gagal menyiapkan query order: " . $conn->error;
    }
} else {
    // Jalankan query tanpa prepared statement jika tidak ada parameter yang diikat
    if ($result = $conn->query($sql_get_orders)) {
        while ($row = $result->fetch_assoc()) {
            $all_orders[] = $row;
        }
        $result->free();
    } else {
        $form_error = "Gagal mengambil data order dari database: " . $conn->error;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tim Orders - ExpertUs</title>
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

        /* Styling Sidebar */
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

        /* Styles for the Orders List */
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
        .order-filter {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .order-filter a {
            color: #6B7280;
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: background-color 0.2s, color 0.2s;
        }
        .order-filter a.active, .order-filter a:hover {
            background-color: #E5E7EB; /* Gray-200 */
            color: #1F2937;
        }

        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .order-card {
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 0.75rem;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center; 
            justify-content: space-between; 
            gap: 1rem; 
            transition: box-shadow 0.2s;
            flex-wrap: nowrap; 
            min-height: 100px; 
        }
        .order-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            cursor: pointer;
        }
        
        /* Container for ID, Customer, and Description */
        .order-info-main {
            display: flex;
            align-items: center;
            flex-grow: 1; 
            gap: 1rem; 
            flex-wrap: nowrap; 
            flex-basis: 70%; 
            min-width: 0; 
        }

        .order-id {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1F2937;
            flex: 0 0 80px; 
            text-align: left;
        }
        .customer-details {
            display: flex;
            flex-direction: column;
            font-size: 0.875rem;
            color: #4B5563;
            flex: 1 1 200px; 
            word-break: break-word;
            line-height: 1.3; 
        }
        .customer-details .name { font-weight: 600; color: #1F2937; }
        .customer-details .contact { color: #6B7280; word-break: break-all; }

        .order-description {
            display: flex;
            flex-direction: column;
            justify-content: center; 
            font-size: 0.875rem;
            color: #4B5563;
            flex: 1 1 250px;
            word-break: break-word;
            line-height: 1.3; 
        }
        .order-description .company-info { font-weight: 600; color: #1F2937; }
        
        /* Container for Status and Service Badge */
        .order-status-and-service { 
            display: flex;
            flex-direction: column; 
            align-items: flex-end; 
            gap: 0.5rem; 
            flex: 0 0 auto;
            min-width: 150px;
            max-width: 180px; 
            text-align: right; 
        }
        .order-status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            display: block;
            width: fit-content;
            margin-left: auto; 
        }
        .status-on-progress { background-color: #FFEDD5; color: #F97316; }
        .status-complete { background-color: #D1FAE5; color: #10B981; }
        .status-pending { background-color: #DBEAFE; color: #3B82F6; }
        .status-cancelled { background-color: #FEE2E2; color: #EF4444; }

        .service-badge {
            background-color: #D1FAE5;
            color: #10B981;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            white-space: nowrap;
            display: block; 
            width: fit-content;
            margin-left: auto;
        }
        .arrow-icon {
            font-size: 1.25rem;
            color: #9CA3AF;
            flex-shrink: 0;
            margin-left: auto;  
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
            .order-card {
                flex-wrap: wrap; 
                justify-content: flex-start;
                gap: 1rem; 
            }
            .order-info-main { 
                flex-wrap: wrap;
                gap: 0.5rem;
                width: 100%; 
                flex-basis: auto; 
                justify-content: flex-start; 
            }
            .order-id, .customer-details, .order-description {
                flex-basis: auto; 
                max-width: none;
                min-width: 0; 
                width: 100%; 
            }
            .order-id {
                width: 80px;
                flex: 0 0 80px; 
            }
            .order-status-and-service {
                flex-grow: 1;
                justify-content: flex-start;
                min-width: 0; 
                flex-direction: row;
                align-items: center;
                gap: 0.75rem;
                width: 100%; 
                margin-top: 0.5rem;
            }
            .order-status-badge, .service-badge {
                margin-left: 0; 
            }
            .arrow-icon {
                margin-left: 0; 
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-radius: 0;
                box-shadow: none;
                flex-direction: row;
                justify-content: space-around;
                padding: 1rem 0;
            }
            .sidebar-profile {
                display: none;
            }
            .sidebar-nav {
                display: flex;
                flex-direction: row;
                padding: 0;
                flex-wrap: wrap;
                justify-content: center;
            }
            .sidebar-nav a {
                padding: 0.5rem 0.75rem;
                margin: 0.25rem;
            }
            .content {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
            }
            .orders-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .order-card {
                flex-direction: column; 
                align-items: flex-start; 
                gap: 1rem;
                flex-wrap: wrap;
            }
            .order-info-main { 
                flex-direction: column; 
                align-items: flex-start;
                width: 100%; 
                gap: 0.5rem;
                flex-wrap: nowrap;
            }
            .order-id {
                width: 100%; 
                text-align: left;
            }
            .customer-details, .order-description {
                width: 100%; 
                min-width: auto;
                max-width: none;
            }
            .order-status-and-service {
                flex-direction: row; 
                align-items: center;
                gap: 0.5rem;
                width: 100%;
                min-width: auto;
                justify-content: flex-start;
            }
            .order-status-badge, .service-badge, .arrow-icon {
                margin-left: 0; 
                margin-right: 0;
            }
            .service-badge {
                width: fit-content;
            }
        }

        @media (max-width: 480px) {
            .content {
                padding: 1rem;
            }
            .orders-container {
                padding: 1rem;
            }
        }
    </style>
</head>
<body class="flex">
    <?php
    $current_page_tim = 'orders'; // Tandai halaman aktif untuk sidebar
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

        <div class="orders-container">
            <div class="orders-header">
                <h2>Orders</h2>
                <div class="order-filter">
                    <a href="<?php echo BASE_URL; ?>/tim/orders/index.php?status=all" class="<?php echo (!isset($_GET['status']) || $_GET['status'] == 'all') ? 'active' : ''; ?>">All</a>
                    <a href="<?php echo BASE_URL; ?>/tim/orders/index.php?status=on_progress" class="<?php echo (isset($_GET['status']) && $_GET['status'] == 'on_progress') ? 'active' : ''; ?>">On Progress</a>
                    <a href="<?php echo BASE_URL; ?>/tim/orders/index.php?status=pending" class="<?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'active' : ''; ?>">Pending</a>
                    <a href="<?php echo BASE_URL; ?>/tim/orders/index.php?status=completed" class="<?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'active' : ''; ?>">Completed</a>
                    <a href="<?php echo BASE_URL; ?>/tim/orders/index.php?status=cancelled" class="<?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'active' : ''; ?>">Cancelled</a> </div>
            </div>

            <div class="orders-list">
                <?php if (empty($all_orders)): ?>
                    <div class="text-center py-4 text-gray-500">Tidak ada order ditemukan.</div>
                <?php else: ?>
                    <?php foreach ($all_orders as $order): ?>
                        <?php
                        // Tentukan kelas CSS berdasarkan StatusPengerjaan
                        $status_class = '';
                        switch ($order['StatusPengerjaan']) {
                            case 'On Progress':
                                $status_class = 'status-on-progress';
                                break;
                            case 'Finished': // Menggunakan 'Finished' sesuai DB Anda untuk Completed
                                $status_class = 'status-complete';
                                break;
                            case 'Pending':
                                $status_class = 'status-pending';
                                break;
                            case 'Cancelled': // Menambahkan kasus untuk Cancelled
                                $status_class = 'status-cancelled';
                                break;
                            default:
                                $status_class = '';
                        }
                        ?>
                        <a href="<?php echo BASE_URL; ?>/tim/orders/detail.php?consult_id=<?php echo urlencode($order['IdConsult']); ?>" class="order-card">
                            <div class="order-info-main">
                                <div class="order-id">#<?php echo htmlspecialchars($order['IdConsult']); ?></div>
                                <div class="customer-details">
                                    <span class="name"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                    <span class="contact"><?php echo htmlspecialchars($order['customer_email']); ?> | <?php echo htmlspecialchars($order['customer_phone']); ?></span>
                                </div>
                                <div class="order-description">
                                    <span class="company-info"><?php echo htmlspecialchars($order['NamaUsaha']); ?></span><br>
                                    <span><?php echo htmlspecialchars($order['BidangUsaha']); ?>, <?php echo htmlspecialchars($order['Lokasi']); ?></span>
                                </div>
                            </div>
                            <div class="order-status-and-service">
                                <span class="order-status-badge <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($order['StatusPengerjaan']); ?>
                                </span>
                                <span class="service-badge">
                                    <?php echo htmlspecialchars($order['JenisLayanan']); ?>
                                </span>
                                <i class="fas fa-chevron-right arrow-icon"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>