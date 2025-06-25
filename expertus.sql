-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 23 Jun 2025 pada 15.55
-- Versi server: 10.4.28-MariaDB
-- Versi PHP: 8.1.17

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `expertus`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `admin`
--

CREATE TABLE `admin` (
  `IdAdmin` int(11) NOT NULL,
  `Nama` varchar(100) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `NoTelp` varchar(20) DEFAULT NULL,
  `Password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `admin`
--

INSERT INTO `admin` (`IdAdmin`, `Nama`, `Email`, `NoTelp`, `Password`) VALUES
(1, 'Admin Expertus', 'admin@expertus.com', '081234567890', 'Password123');

-- --------------------------------------------------------

--
-- Struktur dari tabel `berita`
--

CREATE TABLE `berita` (
  `IdBerita` int(11) NOT NULL,
  `IdAdmin` int(11) DEFAULT NULL,
  `Judul` varchar(200) DEFAULT NULL,
  `Img` varchar(255) DEFAULT NULL,
  `Date` date DEFAULT NULL,
  `Text` text DEFAULT NULL,
  `updated_at` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `berita`
--

INSERT INTO `berita` (`IdBerita`, `IdAdmin`, `Judul`, `Img`, `Date`, `Text`, `updated_at`) VALUES
(7, 1, 'Workshop Foto Katalog', '/public/files/Berita/berita_img_7_Workshop_Foto_Katalog.png', '2025-06-19', '&amp;quot;Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.&amp;quot;\r\n\r\nSection 1.10.32 of &amp;quot;de Finibus Bonorum et Malorum&amp;quot;, written by Cicero in 45 BC\r\n&amp;quot;Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?&amp;quot;', '2025-06-19'),
(8, 1, 'Workshop Foto Katalog', '/public/files/Berita/berita_img_8_Workshop_Foto_Katalog.png', '2025-06-23', '&amp;amp;quot;Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.&amp;amp;quot;\r\n\r\nSection 1.10.32 of &amp;amp;quot;de Finibus Bonorum et Malorum&amp;amp;quot;, written by Cicero in 45 BC\r\n&amp;amp;quot;Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?&amp;amp;quot;', '2025-06-23');

-- --------------------------------------------------------

--
-- Struktur dari tabel `consult`
--

CREATE TABLE `consult` (
  `IdConsult` int(11) NOT NULL,
  `IdCust` int(11) DEFAULT NULL,
  `HasilConsult` text DEFAULT NULL,
  `PembayaranDP` text DEFAULT NULL,
  `BayarLunas` text DEFAULT NULL,
  `StatusPembayaran` varchar(50) DEFAULT NULL,
  `StatusPengerjaan` varchar(50) DEFAULT NULL,
  `HasilPengerjaan` text DEFAULT NULL,
  `CatatanRevisi` text DEFAULT NULL,
  `Revisi1` text DEFAULT NULL,
  `Revisi2` text DEFAULT NULL,
  `created_at` date NOT NULL,
  `updated_at` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `consult`
--

INSERT INTO `consult` (`IdConsult`, `IdCust`, `HasilConsult`, `PembayaranDP`, `BayarLunas`, `StatusPembayaran`, `StatusPengerjaan`, `HasilPengerjaan`, `CatatanRevisi`, `Revisi1`, `Revisi2`, `created_at`, `updated_at`) VALUES
(14, 24, '/public/files/HasilConsult/hasil_konsultasi_14_24_Fernando_Hosea_Sihaloho.pdf', '/public/files/BayarDp/dp_14_24_Fernando_Hosea_Sihaloho.png', '/public/files/BayarLunas/lunas_14_24_Fernando_Hosea_Sihaloho.png', 'Lunas', 'Finished', '/public/files/HasilPengerjaan/hasil_14_Fernando_Hosea_Sihaloho.pdf', 'logonya kurang mencerminkan usaha', '/public/files/Revisi1/revisi1_14_Fernando_Hosea_Sihaloho.png', NULL, '2025-06-23', '2025-06-23');

-- --------------------------------------------------------

--
-- Struktur dari tabel `customer`
--

CREATE TABLE `customer` (
  `IdCust` int(11) NOT NULL,
  `Nama` varchar(100) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `NoTelp` varchar(20) DEFAULT NULL,
  `NamaUsaha` varchar(100) DEFAULT NULL,
  `BidangUsaha` varchar(100) DEFAULT NULL,
  `JenisLayanan` varchar(100) DEFAULT NULL,
  `Lokasi` varchar(100) DEFAULT NULL,
  `Harga` decimal(15,2) DEFAULT NULL,
  `created_at` date NOT NULL,
  `updated_at` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `customer`
--

INSERT INTO `customer` (`IdCust`, `Nama`, `Email`, `NoTelp`, `NamaUsaha`, `BidangUsaha`, `JenisLayanan`, `Lokasi`, `Harga`, `created_at`, `updated_at`) VALUES
(24, 'Fernando Hosea Sihaloho', '124230125@student.upnyk.ac.id', '082220595895', 'Kulinare Makyuse', 'Kuliner', 'Pembuatan Logo', 'Banguntapan', 150000.00, '2025-06-23', '2025-06-23');

-- --------------------------------------------------------

--
-- Struktur dari tabel `divisi`
--

CREATE TABLE `divisi` (
  `IdDivisi` int(11) NOT NULL,
  `NamaDivisi` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `divisi`
--

INSERT INTO `divisi` (`IdDivisi`, `NamaDivisi`) VALUES
(1, 'Desain'),
(2, 'Legal');

-- --------------------------------------------------------

--
-- Struktur dari tabel `testimoni`
--

CREATE TABLE `testimoni` (
  `IdTest` int(11) NOT NULL,
  `IdCust` int(11) NOT NULL,
  `JenisLayanan` varchar(255) NOT NULL,
  `Text` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `timpengerjaan`
--

CREATE TABLE `timpengerjaan` (
  `IdEmployee` int(11) NOT NULL,
  `IdDivisi` int(11) DEFAULT NULL,
  `Nama` varchar(100) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `NoTelp` varchar(20) DEFAULT NULL,
  `Password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `timpengerjaan`
--

INSERT INTO `timpengerjaan` (`IdEmployee`, `IdDivisi`, `Nama`, `Email`, `NoTelp`, `Password`) VALUES
(2, 1, 'Desainer Expertus', 'desainer@expertus.com', '089876543210', 'password123');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`IdAdmin`);

--
-- Indeks untuk tabel `berita`
--
ALTER TABLE `berita`
  ADD PRIMARY KEY (`IdBerita`),
  ADD KEY `IdAdmin` (`IdAdmin`);

--
-- Indeks untuk tabel `consult`
--
ALTER TABLE `consult`
  ADD PRIMARY KEY (`IdConsult`),
  ADD KEY `IdCust` (`IdCust`);

--
-- Indeks untuk tabel `customer`
--
ALTER TABLE `customer`
  ADD PRIMARY KEY (`IdCust`);

--
-- Indeks untuk tabel `divisi`
--
ALTER TABLE `divisi`
  ADD PRIMARY KEY (`IdDivisi`);

--
-- Indeks untuk tabel `testimoni`
--
ALTER TABLE `testimoni`
  ADD PRIMARY KEY (`IdTest`),
  ADD KEY `IdCust` (`IdCust`);

--
-- Indeks untuk tabel `timpengerjaan`
--
ALTER TABLE `timpengerjaan`
  ADD PRIMARY KEY (`IdEmployee`),
  ADD KEY `IdDivisi` (`IdDivisi`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `admin`
--
ALTER TABLE `admin`
  MODIFY `IdAdmin` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `berita`
--
ALTER TABLE `berita`
  MODIFY `IdBerita` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT untuk tabel `consult`
--
ALTER TABLE `consult`
  MODIFY `IdConsult` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT untuk tabel `customer`
--
ALTER TABLE `customer`
  MODIFY `IdCust` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT untuk tabel `divisi`
--
ALTER TABLE `divisi`
  MODIFY `IdDivisi` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `testimoni`
--
ALTER TABLE `testimoni`
  MODIFY `IdTest` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT untuk tabel `timpengerjaan`
--
ALTER TABLE `timpengerjaan`
  MODIFY `IdEmployee` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `berita`
--
ALTER TABLE `berita`
  ADD CONSTRAINT `berita_ibfk_1` FOREIGN KEY (`IdAdmin`) REFERENCES `admin` (`IdAdmin`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `consult`
--
ALTER TABLE `consult`
  ADD CONSTRAINT `consult_ibfk_1` FOREIGN KEY (`IdCust`) REFERENCES `customer` (`IdCust`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `testimoni`
--
ALTER TABLE `testimoni`
  ADD CONSTRAINT `testimoni_ibfk_1` FOREIGN KEY (`IdCust`) REFERENCES `customer` (`IdCust`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `timpengerjaan`
--
ALTER TABLE `timpengerjaan`
  ADD CONSTRAINT `timpengerjaan_ibfk_1` FOREIGN KEY (`IdDivisi`) REFERENCES `divisi` (`IdDivisi`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
