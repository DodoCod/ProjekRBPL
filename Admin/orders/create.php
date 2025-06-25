<?php
require_once dirname(dirname(__DIR__)) . '/includes/auth_check.php';
checkAdminLogin(); 

require_once dirname(dirname(__DIR__)) . '/includes/db_config.php'; 

$admin_nama = $_SESSION['admin_nama'] ?? 'Admin'; 

// Inisialisasi variabel untuk form dan pesan error/sukses
$nama = $no_telp = $email = $nama_usaha = $bidang_usaha = $lokasi = $layanan = $harga = $keterangan = '';
$nama_err = $no_telp_err = $email_err = $nama_usaha_err = $bidang_usaha_err = $lokasi_err = $layanan_err = $harga_err = '';
$form_success = '';
$form_error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    global $conn; // Mengakses koneksi database global

    // 1. Ambil dan Sanitasi Input
    $nama           = sanitize_input($_POST['nama']);
    $no_telp        = sanitize_input($_POST['no_telp']);
    $email          = sanitize_input($_POST['email']);
    $nama_usaha     = sanitize_input($_POST['nama_usaha']);
    $bidang_usaha   = sanitize_input($_POST['bidang_usaha']); 
    $lokasi         = sanitize_input($_POST['lokasi']);       
    $layanan        = sanitize_input($_POST['layanan']);
    $harga          = sanitize_input($_POST['harga']); // Ini akan jadi string, validasi sebagai numerik
    $keterangan     = sanitize_input($_POST['keterangan'] ?? ''); // Keterangan opsional

    // 2. Validasi Input (sesuai SRS dan RAT)
    if (empty($nama)) { $nama_err = 'Nama Pemesan wajib diisi.'; }
    if (empty($no_telp)) { $no_telp_err = 'Nomor Telepon wajib diisi.'; }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email_err = 'Email tidak valid.'; }
    if (empty($nama_usaha)) { $nama_usaha_err = 'Nama Usaha wajib diisi.'; }
    if (empty($bidang_usaha)) { $bidang_usaha_err = 'Bidang Usaha wajib diisi.'; } // Validasi untuk input teks
    if (empty($lokasi)) { $lokasi_err = 'Lokasi wajib diisi.'; } // Validasi untuk input teks
    if (empty($layanan)) { $layanan_err = 'Jenis Layanan wajib dipilih.'; }
    
    // Validasi harga sebagai numerik
    if (empty($harga)) {
        $harga_err = 'Harga wajib diisi.';
    } elseif (!is_numeric(str_replace(['Rp', '.', ','], '', $harga))) { // Hapus Rp, titik, koma untuk cek numerik
        $harga_err = 'Harga harus berupa angka.';
    } else {
        $harga_clean = (float)str_replace(['Rp', '.', ','], '', $harga); // Konversi ke float untuk penyimpanan
    }

    // Cek apakah ada error validasi
    if (empty($nama_err) && empty($no_telp_err) && empty($email_err) && empty($nama_usaha_err) &&
        empty($bidang_usaha_err) && empty($lokasi_err) && empty($layanan_err) && empty($harga_err)) {

        // 3. Simpan data ke Database (tabel Customer dan Consult)
        $conn->begin_transaction(); // Mulai transaksi untuk integritas data

        try {
            // Insert data Customer (jika belum ada atau selalu buat baru)
            // Asumsi: Kita selalu membuat customer baru untuk setiap order ini
            $sql_insert_customer = "INSERT INTO customer (Nama, Email, NoTelp, NamaUsaha, BidangUsaha, JenisLayanan, Lokasi, Harga, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            if ($stmt_cust = $conn->prepare($sql_insert_customer)) {
                $stmt_cust->bind_param('sssssssd', $nama, $email, $no_telp, $nama_usaha, $bidang_usaha, $layanan, $lokasi, $harga_clean); // 'd' untuk double/float
                if (!$stmt_cust->execute()) {
                    throw new Exception("Gagal menyimpan data pelanggan: " . $stmt_cust->error);
                }
                $customer_id = $stmt_cust->insert_id; // Dapatkan ID customer yang baru di-insert
                $stmt_cust->close();
            } else {
                throw new Exception("Kesalahan persiapan query pelanggan: " . $conn->error);
            }

            // Insert data Consult
            $sql_insert_consult = "INSERT INTO consult (IdCust, HasilConsult, PembayaranDP, BayarLunas, StatusPembayaran, StatusPengerjaan, HasilPengerjaan, CatatanRevisi, Revisi1, Revisi2, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            if ($stmt_consult = $conn->prepare($sql_insert_consult)) {
                // Untuk awal, banyak kolom bisa NULL/kosong
                $default_hasil_consult = $keterangan; // Keterangan tambahan bisa jadi hasil consult awal
                $default_pembayaran_dp = 0.00;
                $default_bayar_lunas = 0.00;
                $default_status_pembayaran = 'Belum Lunas';
                $default_status_pengerjaan = 'Pending';
                $default_hasil_pengerjaan = NULL;
                $default_catatan_revisi = NULL;
                $default_revisi1 = NULL;
                $default_revisi2 = NULL;

                $stmt_consult->bind_param('idddssssss', 
                    $customer_id, 
                    $default_hasil_consult, 
                    $default_pembayaran_dp, 
                    $default_bayar_lunas, 
                    $default_status_pembayaran, 
                    $default_status_pengerjaan, 
                    $default_hasil_pengerjaan, 
                    $default_catatan_revisi, 
                    $default_revisi1, 
                    $default_revisi2
                );
                if (!$stmt_consult->execute()) {
                    throw new Exception("Gagal menyimpan data konsultasi: " . $stmt_consult->error);
                }
                $consult_id_from_db = $stmt_consult->insert_id; // Dapatkan ID konsultasi yang baru di-insert
                $stmt_consult->close();
            } else {
                throw new Exception("Kesalahan persiapan query konsultasi: " . $conn->error);
            }

            $conn->commit(); // Commit transaksi jika semua berhasil
            $form_success = 'Pesanan berhasil ditambahkan!';
            // Kosongkan form setelah sukses submit
            $nama = $no_telp = $email = $nama_usaha = $bidang_usaha = $lokasi = $layanan = $harga = $keterangan = '';
            
            // --- PENTING: Redirect ke halaman upload_payment.php dengan consult_id yang baru dibuat ---
            header('location: ' . BASE_URL . '/admin/orders/upload_payment.php?IdConsult=' . $consult_id_from_db); 
            exit;

        } catch (Exception $e) {
            $conn->rollback(); 
            $form_error = 'Gagal menambahkan pesanan: ' . $e->getMessage();
        }

        $conn->close(); // Tutup koneksi setelah selesai
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Form ExpertUs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
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

        form input,
        form select,
        form textarea {
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

        input::placeholder,
        textarea::placeholder {
            color: #808080;
        }

        form input:focus,
        form select:focus,
        form textarea:focus {
            outline: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
            border-color: #3b82f6;
        }

        /* Gaya untuk option di dalam select */
        form select option {
            color: #4b5563; 
            background-color: white; 
        }
        form select option[value=""]:disabled {
            color: #808080; 
        }
        form select:invalid {
            color: #808080; 
        }
        form select:valid {
            color: #4b5563; 
        }


        .flex-row {
            display: flex;
            gap: 10px;
        }

        .flex-row input,
        .flex-row select {
            flex: 1;
            box-sizing: border-box;
            width: auto;
        }

        form textarea {
            height: 100px;
            resize: vertical; 
        }

        form hr {
            margin: 20px 0px;
            border: none; 
            border-top: 1px solid #c7c7c7; 
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
        
        /* Custom panah untuk select (seperti di referensi) */
        .select-wrapper {
            position: relative;
            flex: 1;
            box-sizing: border-box;
            margin: 10px;
        }
        .select-wrapper select {
            width: 100%;
            padding: 12px 14px;
            margin-left: 0;
            padding-right: 30px; 
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background-color: #f9f9f9;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            font-size: 13px;
            color: #4b5563; 
            font-weight: 500;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            cursor: pointer;
            outline: none;
            transition: box-shadow 0.3s ease-in, border-color 0.3s ease-in;
        }
        .select-wrapper select:focus {
            outline: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
            border-color: #3b82f6;
        }
        /* Panah custom */
        .select-wrapper::after {
            content: '\f078'; /* Font Awesome chevron-down */
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #808080;
            pointer-events: none;
            font-size: 10px;
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

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" autocomplete="off">
                    <input type="text" name="nama" placeholder="Nama Pemesan" value="<?php echo htmlspecialchars($nama); ?>" required>
                    <?php if (!empty($nama_err)): ?><p class="error-message"><?php echo htmlspecialchars($nama_err); ?></p><?php endif; ?>

                    <div class="flex-row">
                        <input type="text" name="no_telp" placeholder="Nomor Telp" value="<?php echo htmlspecialchars($no_telp); ?>" required>
                        <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>
                    <?php if (!empty($no_telp_err)): ?><p class="error-message"><?php echo htmlspecialchars($no_telp_err); ?></p><?php endif; ?>
                    <?php if (!empty($email_err)): ?><p class="error-message"><?php echo htmlspecialchars($email_err); ?></p><?php endif; ?>

                    <hr>

                    <input type="text" name="nama_usaha" placeholder="Nama Usaha" value="<?php echo htmlspecialchars($nama_usaha); ?>" required>
                    <?php if (!empty($nama_usaha_err)): ?><p class="error-message"><?php echo htmlspecialchars($nama_usaha_err); ?></p><?php endif; ?>

                    <div class="flex-row">
                        <input type="text" name="bidang_usaha" placeholder="Bidang Usaha" value="<?php echo htmlspecialchars($bidang_usaha); ?>" required>
                        <input type="text" name="lokasi" placeholder="Lokasi" value="<?php echo htmlspecialchars($lokasi); ?>" required>
                    </div>
                    <?php if (!empty($bidang_usaha_err)): ?><p class="error-message"><?php echo htmlspecialchars($bidang_usaha_err); ?></p><?php endif; ?>
                    <?php if (!empty($lokasi_err)): ?><p class="error-message"><?php echo htmlspecialchars($lokasi_err); ?></p><?php endif; ?>

                    <hr>

                    <div class="select-wrapper">
                        <select name="layanan" required>
                            <option value="" disabled <?php echo empty($layanan) ? 'selected' : ''; ?>>Jenis Layanan</option>
                            <option value="Desain Logo" <?php echo ($layanan == 'Desain Logo') ? 'selected' : ''; ?>>Desain Logo</option>
                            <option value="Pendaftaran HAKI" <?php echo ($layanan == 'Pendaftaran HAKI') ? 'selected' : ''; ?>>Pendaftaran HAKI</option>
                            <option value="Konsultasi Bisnis" <?php echo ($layanan == 'Konsultasi Bisnis') ? 'selected' : ''; ?>>Konsultasi Bisnis</option>
                            <option value="Social Media Management" <?php echo ($layanan == 'Social Media Management') ? 'selected' : ''; ?>>Social Media Management</option>
                        </select>
                    </div>
                    <?php if (!empty($layanan_err)): ?><p class="error-message"><?php echo htmlspecialchars($layanan_err); ?></p><?php endif; ?>
                    
                    <input type="text" name="harga" placeholder="Harga" value="<?php echo htmlspecialchars($harga); ?>" required>
                    <?php if (!empty($harga_err)): ?><p class="error-message"><?php echo htmlspecialchars($harga_err); ?></p><?php endif; ?>

                    <textarea name="keterangan" placeholder="Keterangan Tambahan (Opsional)"><?php echo htmlspecialchars($keterangan); ?></textarea>

                    <div class="button-container">
                        <button type="submit">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>