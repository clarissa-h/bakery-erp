-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 17, 2026 at 11:54 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bakery-erp`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_complete_order` (IN `p_order_id` INT)   BEGIN
  DECLARE v_cid INT DEFAULT NULL;
  SELECT customer_id INTO v_cid FROM orders WHERE id=p_order_id;
  UPDATE orders SET status='completed', updated_at=NOW() WHERE id=p_order_id;
  IF v_cid IS NOT NULL THEN UPDATE customers SET total_orders=total_orders+1, updated_at=NOW() WHERE id=v_cid; END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_next_order_ref` (OUT `p_ref` VARCHAR(20))   BEGIN
  DECLARE v_num INT DEFAULT 1;
  SELECT COALESCE(MAX(CAST(SUBSTRING(order_ref,5) AS UNSIGNED)),0)+1 INTO v_num FROM orders;
  SET p_ref = CONCAT('MOC-', LPAD(v_num,3,'0'));
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_restock_ingredient` (IN `p_inventory_id` INT, IN `p_qty` DECIMAL(10,2), IN `p_cost_total` DECIMAL(12,2), IN `p_notes` TEXT)   BEGIN
  UPDATE inventory SET stock=stock+p_qty WHERE id=p_inventory_id;
  INSERT INTO inventory_restock_log (inventory_id,qty_added,cost_total,notes) VALUES (p_inventory_id,p_qty,p_cost_total,p_notes);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(30) NOT NULL DEFAULT '',
  `instagram` varchar(60) NOT NULL DEFAULT '',
  `birthday` date DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_orders` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `instagram`, `birthday`, `allergies`, `notes`, `total_orders`, `created_at`, `updated_at`) VALUES
(1, 'Ibu Rina Hartono', '+62 812-0001-0001', '@rinahartono', '1985-03-15', 'Kacang', 'Suka manis', 19, '2026-06-09 02:28:58', '2026-06-17 07:03:58'),
(2, 'Pak Dedi Santoso', '+62 813-0002-0002', '', NULL, '', '', 4, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(3, 'Kak Sari Putri', '+62 857-0003-0003', '@sari.putri', '1992-07-22', '', 'Kurang manis', 22, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(4, 'Nadia Kusuma', '+62 878-0004-0004', '@nadia.k', '1997-11-08', '', '', 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(5, 'Bu Lestari', '+62 811-0005-0005', '', '1975-05-30', 'Gluten', 'Perlu GF option', 7, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(6, 'Andi Wijaya', '+62 812-1111-1111', '@andiwijaya', '1990-01-10', '', 'Pelanggan tetap', 15, '2026-06-09 05:20:44', '2026-06-09 05:20:44'),
(7, 'Cindy Tan', '+62 813-2222-2222', '@cindytan', '1995-06-21', 'Kacang', 'Tanpa topping kacang', 8, '2026-06-09 05:20:44', '2026-06-09 05:20:44'),
(8, 'Budi Setiawan', '+62 815-3333-3333', '', '1988-09-12', '', 'Pesan untuk kantor', 5, '2026-06-09 05:20:44', '2026-06-09 05:20:44'),
(9, 'Felicia Lie', '+62 878-4444-4444', '@felicialie', '1997-11-18', '', 'Suka pastry', 11, '2026-06-09 05:20:44', '2026-06-09 05:20:44'),
(10, 'Michael Gunawan', '+62 811-5555-5555', '@michaelg', '1993-04-03', 'Laktosa', 'Minta opsi dairy-free', 6, '2026-06-09 05:20:44', '2026-06-09 05:20:44'),
(11, 'Lina Hartati', '+62 857-6666-6666', '', '1984-08-28', '', 'Pelanggan loyal', 19, '2026-06-09 05:20:44', '2026-06-09 05:20:44'),
(12, 'Yohanes Halim', '+62 812-1200-1200', '@yohaneshalim', '1987-04-11', '', 'Sering membeli roti sourdough', 12, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(13, 'Melissa Kurnia', '+62 813-1300-1300', '@melissakurnia', '1994-09-23', '', 'Menyukai cupcake', 7, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(14, 'Richard Tjandra', '+62 814-1400-1400', '', '1989-12-05', 'Kacang', 'Meminta informasi bahan', 9, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(15, 'Veronica Gunadi', '+62 815-1500-1500', '@veronicagunadi', '1998-06-17', '', 'Pelanggan reguler akhir pekan', 5, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(16, 'Albert Hidayat', '+62 816-1600-1600', '', '1985-02-28', '', 'Sering memesan untuk kantor', 14, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(17, 'Jessica Pranata', '+62 817-1700-1700', '@jessicapranata', '1996-11-09', '', 'Suka produk seasonal', 4, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(18, 'Samuel Hartono', '+62 818-1800-1800', '', '1991-07-15', 'Gluten', 'Mencari opsi gluten-free', 3, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(19, 'Natalie Kusnadi', '+62 819-1900-1900', '@nataliekusnadi', '1999-05-27', '', 'Pelanggan baru', 1, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(20, 'Vincent Wijaksana', '+62 821-2000-2000', '@vincentwijaksana', '1988-08-19', '', 'Penggemar pastry', 10, '2026-06-09 05:24:14', '2026-06-09 05:24:14'),
(21, 'Caroline Santika', '+62 822-2100-2100', '@carolinesantika', '1993-01-31', '', 'Sering preorder cake ulang tahun', 16, '2026-06-09 05:24:14', '2026-06-09 05:24:14');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(10) UNSIGNED NOT NULL,
  `category` enum('Ingredients','Packaging','Utilities','Equipment','Marketing','Other') NOT NULL DEFAULT 'Other',
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expense_date` date NOT NULL,
  `reference` varchar(120) NOT NULL DEFAULT '',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `category`, `description`, `amount`, `expense_date`, `reference`, `notes`, `created_at`) VALUES
(1, 'Ingredients', 'Tepung terigu 25kg — Toko Makmur', 350000.00, '2025-06-28', '', NULL, '2026-06-09 02:28:59'),
(2, 'Packaging', 'Kotak kue 50pcs — Tokopedia', 250000.00, '2025-06-28', '', NULL, '2026-06-09 02:28:59'),
(3, 'Utilities', 'Isi ulang gas 12kg', 190000.00, '2025-06-30', '', NULL, '2026-06-09 02:28:59'),
(4, 'Ingredients', 'Mentega tawar 2kg — Superindo', 240000.00, '2025-07-01', '', NULL, '2026-06-09 02:28:59'),
(5, 'Marketing', 'Foto produk IG — studio lokal', 350000.00, '2025-07-01', '', NULL, '2026-06-09 02:28:59'),
(6, 'Packaging', 'Pita & stiker branding Mocardi', 175000.00, '2025-07-02', '', NULL, '2026-06-09 02:28:59'),
(7, 'Ingredients', 'Terigu, Ny.Liem', 20000.00, '2026-06-09', '', '', '2026-06-09 02:34:07'),
(8, 'Equipment', 'Loyang 22 * 22', 20000.00, '2026-06-09', '', '', '2026-06-09 02:34:23');

-- --------------------------------------------------------

--
-- Table structure for table `ingredient_categories`
--

CREATE TABLE `ingredient_categories` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredient_categories`
--

INSERT INTO `ingredient_categories` (`id`, `name`) VALUES
(8, 'Kemasan'),
(9, 'Lain-lain'),
(4, 'Lemak & Minyak'),
(3, 'Pemanis'),
(6, 'Perisa & Bumbu'),
(2, 'Susu & Dairy'),
(5, 'Telur'),
(1, 'Tepung & Biji-bijian'),
(7, 'Topping');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` enum('g','kg','ml','L','pcs','sachet','tbsp','tsp') NOT NULL DEFAULT 'g',
  `min_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_per_unit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `supplier` varchar(120) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `category_id`, `name`, `stock`, `unit`, `min_stock`, `cost_per_unit`, `supplier`, `created_at`, `updated_at`) VALUES
(1, 1, 'Tepung Terigu Protein Tinggi', 4850.00, 'g', 1000.00, 14.00, 'Toko Makmur', '2026-06-09 02:28:58', '2026-06-17 06:29:27'),
(2, 6, 'Ragi Instan', 50.00, 'g', 100.00, 250.00, 'Superindo', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(3, 4, 'Mentega Tawar', 620.00, 'g', 500.00, 120.00, 'Superindo', '2026-06-09 02:28:58', '2026-06-17 06:29:27'),
(4, 5, 'Telur Ayam', 21.00, 'pcs', 12.00, 3000.00, 'Pasar Gedebage', '2026-06-09 02:28:58', '2026-06-17 06:29:27'),
(5, 3, 'Gula Pasir', 1850.00, 'g', 500.00, 16.00, 'Toko Makmur', '2026-06-09 02:28:58', '2026-06-17 06:29:27'),
(6, 2, 'Susu UHT Full Cream', 2000.00, 'ml', 1000.00, 20.00, 'Indomaret', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(7, 6, 'Coklat Bubuk Premium', 80.00, 'g', 200.00, 120.00, 'Shopee', '2026-06-09 02:28:58', '2026-06-17 06:29:27'),
(8, 8, 'Kotak Kue (M)', 15.00, 'pcs', 20.00, 5000.00, 'Tokopedia', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(9, 1, 'Tepung Kue', 3000.00, 'g', 500.00, 18.00, 'Toko Makmur', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(10, 3, 'Gula Halus', 500.00, 'g', 200.00, 22.00, 'Superindo', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(11, 4, 'Minyak Goreng', 1000.00, 'ml', 300.00, 25.00, 'Indomaret', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(12, 6, 'Vanilla Ekstrak', 50.00, 'ml', 20.00, 350.00, 'Shopee', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(13, 7, 'Chip Coklat', 400.00, 'g', 100.00, 90.00, 'Shopee', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(14, 6, 'Kayu Manis Bubuk', 80.00, 'g', 30.00, 150.00, 'Shopee', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(15, 2, 'Heavy Cream', 500.00, 'ml', 200.00, 45.00, 'Superindo', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(16, 7, 'Stroberi Segar', 300.00, 'g', 150.00, 85.00, 'Pasar Gedebage', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(17, 3, 'Madu Murni', 250.00, 'ml', 100.00, 95.00, 'Shopee', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(18, 8, 'Kotak Kue (L)', 10.00, 'pcs', 15.00, 8000.00, 'Tokopedia', '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(19, 7, 'Walnut', 500.00, 'g', 100.00, 150.00, 'Shopee', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(20, 2, 'Cream Cheese', 1000.00, 'g', 300.00, 120.00, 'Superindo', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(21, 7, 'Ham Slice', 500.00, 'g', 100.00, 85.00, 'Superindo', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(22, 7, 'Keju Cheddar', 1000.00, 'g', 200.00, 95.00, 'Superindo', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(23, 6, 'Baking Soda', 250.00, 'g', 50.00, 40.00, 'Toko Makmur', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(24, 7, 'Puree Stroberi', 1000.00, 'ml', 200.00, 70.00, 'Shopee', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(25, 6, 'Garam', 1000.00, 'g', 200.00, 5.00, 'Toko Makmur', '2026-06-09 05:26:48', '2026-06-09 05:26:48'),
(26, 9, 'Air', 50000.00, 'ml', 1000.00, 0.50, 'PDAM', '2026-06-09 05:26:48', '2026-06-09 05:26:48');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_restock_log`
--

CREATE TABLE `inventory_restock_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `inventory_id` int(10) UNSIGNED NOT NULL,
  `qty_added` decimal(10,2) NOT NULL,
  `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `restocked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_ref` varchar(20) NOT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `guest_name` varchar(120) NOT NULL DEFAULT '',
  `guest_phone` varchar(30) NOT NULL DEFAULT '',
  `type` enum('walkin','preorder') NOT NULL DEFAULT 'walkin',
  `pickup_date` date DEFAULT NULL,
  `pickup_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deposit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','in-progress','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(40) NOT NULL DEFAULT 'Cash',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_ref`, `customer_id`, `guest_name`, `guest_phone`, `type`, `pickup_date`, `pickup_time`, `notes`, `subtotal`, `discount`, `tax_amount`, `total`, `deposit`, `status`, `payment_method`, `created_at`, `updated_at`) VALUES
(1, 'MOC-001', 1, '', '', 'preorder', '2026-07-05', '10:00:00', 'Tanpa kacang', 234000.00, 0.00, 23400.00, 257400.00, 100000.00, 'pending', 'Cash', '2026-06-08 19:28:59', '2026-06-08 20:25:53'),
(2, 'MOC-002', 2, '', '', 'preorder', '2026-07-07', '09:00:00', 'Krim merah muda', 280000.00, 0.00, 28000.00, 308000.00, 154000.00, 'in-progress', 'Cash', '2026-06-08 19:28:59', '2026-06-08 20:25:59'),
(3, 'MOC-003', 3, '', '', 'preorder', '2026-07-06', '08:00:00', 'Event ulang tahun', 1008000.00, 0.00, 100800.00, 1108800.00, 600000.00, 'cancelled', 'Cash', '2026-06-08 19:28:59', '2026-06-08 21:42:01'),
(4, 'MOC-004', 2, '', '', 'walkin', NULL, NULL, '', 270000.00, 0.00, 27000.00, 297000.00, 297000.00, 'completed', 'Cash', '2026-06-08 19:32:28', '2026-06-08 19:32:28'),
(5, 'MOC-005', 1, '', '', 'walkin', NULL, NULL, '', 22000.00, 10000.00, 2200.00, 14200.00, 14200.00, 'in-progress', 'Cash', '2026-06-08 21:40:27', '2026-06-08 21:41:41'),
(6, 'MOC-006', NULL, 'Walk-in', '', 'walkin', NULL, NULL, '', 32000.00, 0.00, 3840.00, 35840.00, 35840.00, 'completed', 'Cash', '2026-06-08 21:55:39', '2026-06-08 21:55:39'),
(7, 'MOC-007', 4, '', '', 'preorder', '2026-03-02', '09:00:00', 'Pesanan kantor', 320000.00, 0.00, 38400.00, 358400.00, 150000.00, 'completed', 'Transfer', '2026-03-01 02:00:00', '2026-03-02 02:00:00'),
(8, 'MOC-008', 5, '', '', 'walkin', NULL, NULL, '', 88000.00, 0.00, 10560.00, 98560.00, 98560.00, 'completed', 'Cash', '2026-03-04 03:15:00', '2026-03-04 03:15:00'),
(9, 'MOC-009', 6, '', '', 'preorder', '2026-03-06', '14:00:00', 'Birthday cake', 550000.00, 0.00, 66000.00, 616000.00, 300000.00, 'completed', 'Transfer', '2026-03-05 04:00:00', '2026-03-06 07:00:00'),
(10, 'MOC-010', 7, '', '', 'walkin', NULL, NULL, '', 124000.00, 0.00, 14880.00, 138880.00, 138880.00, 'completed', 'QRIS', '2026-03-09 01:30:00', '2026-03-09 01:30:00'),
(11, 'MOC-011', 8, '', '', 'preorder', '2026-03-12', '10:00:00', 'Family gathering', 720000.00, 0.00, 86400.00, 806400.00, 350000.00, 'completed', 'Transfer', '2026-03-10 06:00:00', '2026-03-12 03:00:00'),
(12, 'MOC-012', 9, '', '', 'walkin', NULL, NULL, '', 45000.00, 0.00, 5400.00, 50400.00, 50400.00, 'completed', 'Cash', '2026-03-15 08:20:00', '2026-03-15 08:20:00'),
(13, 'MOC-013', 10, '', '', 'preorder', '2026-03-18', '11:00:00', 'Meeting komunitas', 410000.00, 0.00, 49200.00, 459200.00, 200000.00, 'completed', 'Transfer', '2026-03-16 03:00:00', '2026-03-18 04:00:00'),
(14, 'MOC-014', 11, '', '', 'walkin', NULL, NULL, '', 99000.00, 0.00, 11880.00, 110880.00, 110880.00, 'completed', 'QRIS', '2026-04-01 02:15:00', '2026-04-01 02:15:00'),
(15, 'MOC-015', 12, '', '', 'preorder', '2026-04-05', '09:00:00', 'Acara sekolah', 850000.00, 0.00, 102000.00, 952000.00, 400000.00, 'completed', 'Transfer', '2026-04-03 05:00:00', '2026-04-05 02:00:00'),
(16, 'MOC-016', 13, '', '', 'walkin', NULL, NULL, '', 78000.00, 0.00, 9360.00, 87360.00, 87360.00, 'completed', 'Cash', '2026-04-08 09:00:00', '2026-04-08 09:00:00'),
(17, 'MOC-017', 14, '', '', 'preorder', '2026-04-10', '15:00:00', 'Dessert table', 1250000.00, 0.00, 150000.00, 1400000.00, 700000.00, 'completed', 'Transfer', '2026-04-07 03:00:00', '2026-04-10 08:00:00'),
(18, 'MOC-018', 15, '', '', 'walkin', NULL, NULL, '', 156000.00, 0.00, 18720.00, 174720.00, 174720.00, 'completed', 'QRIS', '2026-04-13 01:45:00', '2026-04-13 01:45:00'),
(19, 'MOC-019', 1, '', '', 'preorder', '2026-04-17', '10:00:00', 'Pesanan kantor', 620000.00, 0.00, 74400.00, 694400.00, 300000.00, 'completed', 'Transfer', '2026-04-15 02:00:00', '2026-04-17 03:00:00'),
(20, 'MOC-020', 2, '', '', 'walkin', NULL, NULL, '', 67000.00, 0.00, 8040.00, 75040.00, 75040.00, 'completed', 'Cash', '2026-04-21 06:10:00', '2026-04-21 06:10:00'),
(21, 'MOC-021', 3, '', '', 'preorder', '2026-05-03', '08:00:00', 'Birthday party', 930000.00, 0.00, 111600.00, 1041600.00, 500000.00, 'completed', 'Transfer', '2026-05-01 04:00:00', '2026-05-03 01:00:00'),
(22, 'MOC-022', 4, '', '', 'walkin', NULL, NULL, '', 105000.00, 0.00, 12600.00, 117600.00, 117600.00, 'completed', 'QRIS', '2026-05-06 03:20:00', '2026-05-06 03:20:00'),
(23, 'MOC-023', 5, '', '', 'preorder', '2026-05-09', '14:00:00', 'Acara keluarga', 760000.00, 0.00, 91200.00, 851200.00, 350000.00, 'completed', 'Transfer', '2026-05-07 05:00:00', '2026-05-09 07:00:00'),
(24, 'MOC-024', 6, '', '', 'walkin', NULL, NULL, '', 132000.00, 0.00, 15840.00, 147840.00, 147840.00, 'completed', 'Cash', '2026-05-12 08:00:00', '2026-05-12 08:00:00'),
(25, 'MOC-025', 7, '', '', 'preorder', '2026-05-18', '09:00:00', 'Wedding dessert box', 1450000.00, 0.00, 174000.00, 1624000.00, 800000.00, 'completed', 'Transfer', '2026-05-15 02:00:00', '2026-05-18 02:00:00'),
(26, 'MOC-026', 8, '', '', 'walkin', NULL, NULL, '', 210000.00, 0.00, 25200.00, 235200.00, 235200.00, 'completed', 'QRIS', '2026-05-25 10:30:00', '2026-05-25 10:30:00'),
(38, 'MOC-027', 1, '', '', 'preorder', '2026-06-18', '14:30:00', '', 63000.00, 0.00, 7560.00, 70560.00, 50000.00, 'pending', 'Cash', '2026-06-17 07:03:53', '2026-06-17 07:04:55');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `item_name` varchar(120) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `qty` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `item_name`, `image_url`, `qty`, `unit_price`, `line_total`) VALUES
(1, 1, 2, 'Sourdough Signature', 'images/sourdough_signature.png', 2, 75000.00, 150000.00),
(2, 1, 3, 'Pink Velvet Cupcake', 'images/pink_velvet_cupcake.png', 3, 28000.00, 84000.00),
(3, 2, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 1, 185000.00, 185000.00),
(4, 2, 12, 'Valentine Meringue Box', 'images/valentine_meringue_box.png', 1, 95000.00, 95000.00),
(5, 3, 1, 'Mocardi Croissant', 'images/mocardi_croissant.png', 24, 22000.00, 528000.00),
(6, 3, 6, 'Butter Cookies (6pcs)', 'images/butter_cookies.png', 12, 40000.00, 480000.00),
(7, 4, 14, 'Basque Burnt Cheesecake', '', 2, 135000.00, 270000.00),
(8, 5, 1, 'Mocardi Croissant', '', 1, 22000.00, 22000.00),
(9, 6, 5, 'Cinnamon Rose Roll', '', 1, 32000.00, 32000.00),
(11, 7, 2, 'Sourdough Signature', 'images/sourdough_signature.png', 2, 75000.00, 150000.00),
(12, 7, 1, 'Mocardi Croissant', 'images/mocardi_croissant.png', 5, 22000.00, 110000.00),
(13, 7, 6, 'Butter Cookies (6pcs)', 'images/butter_cookies.png', 1, 60000.00, 60000.00),
(14, 8, 1, 'Mocardi Croissant', 'images/mocardi_croissant.png', 4, 22000.00, 88000.00),
(15, 9, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 1, 250000.00, 250000.00),
(16, 9, 3, 'Pink Velvet Cupcake', 'images/pink_velvet_cupcake.png', 5, 30000.00, 150000.00),
(17, 9, 7, 'Strawberry Tart', 'images/strawberry_tart.png', 3, 50000.00, 150000.00),
(18, 10, 10, 'Ham & Cheese Scroll', 'images/ham_cheese_scroll.png', 2, 62000.00, 124000.00),
(19, 11, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 1, 250000.00, 250000.00),
(20, 11, 7, 'Strawberry Tart', 'images/strawberry_tart.png', 4, 50000.00, 200000.00),
(21, 11, 3, 'Pink Velvet Cupcake', 'images/pink_velvet_cupcake.png', 9, 30000.00, 270000.00),
(22, 12, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 3, 15000.00, 45000.00),
(23, 13, 2, 'Sourdough Signature', 'images/sourdough_signature.png', 2, 75000.00, 150000.00),
(24, 13, 9, 'Cheese Pretzel', 'images/cheese_pretzel.png', 5, 32000.00, 160000.00),
(25, 13, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 4, 25000.00, 100000.00),
(26, 14, 6, 'Butter Cookies (6pcs)', 'images/butter_cookies.png', 1, 60000.00, 60000.00),
(27, 14, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 3, 13000.00, 39000.00),
(28, 15, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 2, 250000.00, 500000.00),
(29, 15, 3, 'Pink Velvet Cupcake', 'images/pink_velvet_cupcake.png', 5, 30000.00, 150000.00),
(30, 15, 7, 'Strawberry Tart', 'images/strawberry_tart.png', 4, 50000.00, 200000.00),
(31, 16, 8, 'Dark Chocolate Brownie', 'images/dark_chocolate_brownie.png', 2, 39000.00, 78000.00),
(32, 17, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 2, 250000.00, 500000.00),
(33, 17, 7, 'Strawberry Tart', 'images/strawberry_tart.png', 5, 50000.00, 250000.00),
(34, 17, 14, 'Basque Burnt Cheesecake', 'images/basque_burnt_cheesecake.png', 2, 250000.00, 500000.00),
(35, 18, 1, 'Mocardi Croissant', 'images/mocardi_croissant.png', 3, 22000.00, 66000.00),
(36, 18, 6, 'Butter Cookies (6pcs)', 'images/butter_cookies.png', 1, 60000.00, 60000.00),
(37, 18, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 2, 15000.00, 30000.00),
(38, 19, 2, 'Sourdough Signature', 'images/sourdough_signature.png', 4, 75000.00, 300000.00),
(39, 19, 10, 'Ham & Cheese Scroll', 'images/ham_cheese_scroll.png', 5, 64000.00, 320000.00),
(40, 20, 4, 'Banana Walnut Bread', 'images/banana_walnut_bread.png', 1, 67000.00, 67000.00),
(41, 21, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 2, 250000.00, 500000.00),
(42, 21, 3, 'Pink Velvet Cupcake', 'images/pink_velvet_cupcake.png', 8, 30000.00, 240000.00),
(43, 21, 12, 'Valentine Meringue Box', 'images/valentine_meringue_box.png', 2, 95000.00, 190000.00),
(44, 22, 4, 'Banana Walnut Bread', 'images/banana_walnut_bread.png', 1, 70000.00, 70000.00),
(45, 22, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 1, 15000.00, 15000.00),
(46, 22, 6, 'Butter Cookies (6pcs)', 'images/butter_cookies.png', 1, 20000.00, 20000.00),
(47, 23, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 1, 250000.00, 250000.00),
(48, 23, 7, 'Strawberry Tart', 'images/strawberry_tart.png', 4, 50000.00, 200000.00),
(49, 23, 14, 'Basque Burnt Cheesecake', 'images/basque_burnt_cheesecake.png', 1, 250000.00, 250000.00),
(50, 23, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 4, 15000.00, 60000.00),
(51, 24, 1, 'Mocardi Croissant', 'images/mocardi_croissant.png', 6, 22000.00, 132000.00),
(52, 25, 11, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 3, 250000.00, 750000.00),
(53, 25, 14, 'Basque Burnt Cheesecake', 'images/basque_burnt_cheesecake.png', 2, 250000.00, 500000.00),
(54, 25, 12, 'Valentine Meringue Box', 'images/valentine_meringue_box.png', 2, 100000.00, 200000.00),
(55, 26, 2, 'Sourdough Signature', 'images/sourdough_signature.png', 2, 75000.00, 150000.00),
(56, 26, 13, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 4, 15000.00, 60000.00);

-- --------------------------------------------------------

--
-- Table structure for table `production_batches`
--

CREATE TABLE `production_batches` (
  `id` int(10) UNSIGNED NOT NULL,
  `recipe_id` int(10) UNSIGNED DEFAULT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `product_name` varchar(120) NOT NULL,
  `qty_produced` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `baker` varchar(100) NOT NULL DEFAULT '',
  `batch_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('planned','in-progress','completed','discarded') NOT NULL DEFAULT 'planned',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `batches_count` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `production_batches`
--

INSERT INTO `production_batches` (`id`, `recipe_id`, `product_id`, `product_name`, `qty_produced`, `baker`, `batch_date`, `start_time`, `end_time`, `status`, `notes`, `created_at`, `updated_at`, `batches_count`) VALUES
(1, 2, 1, 'Mocardi Croissant', 24, 'Monika Cahyadi', '2025-07-01', NULL, NULL, 'completed', NULL, '2026-06-09 02:28:59', '2026-06-09 02:28:59', 1),
(2, 1, 2, 'Sourdough Signature', 8, 'Monika Cahyadi', '2025-07-01', NULL, NULL, 'completed', NULL, '2026-06-09 02:28:59', '2026-06-09 02:28:59', 1),
(3, 3, 3, 'Pink Velvet Cupcake', 24, 'Monika Cahyadi', '2025-07-02', NULL, NULL, 'completed', '', '2026-06-09 02:28:59', '2026-06-09 02:32:06', 1),
(8, 8, 8, 'Dark Chocolate Brownie', 12, 'Monika', '2026-06-18', NULL, NULL, 'in-progress', '', '2026-06-17 06:29:27', '2026-06-17 06:29:27', 1);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `name`, `image_url`, `price`, `stock`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, 'Mocardi Croissant', 'images/mocardi_croissant.png', 22000.00, 14, 1, '2026-06-09 02:28:58', '2026-06-09 04:40:28'),
(2, 1, 'Sourdough Signature', 'images/sourdough_signature.png', 75000.00, 8, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(3, 3, 'Pink Velvet Cupcake', 'images/pink_velvet_cupcake.png', 28000.00, 20, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(4, 1, 'Banana Walnut Bread', 'images/banana_walnut_bread.png', 52000.00, 5, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(5, 2, 'Cinnamon Rose Roll', 'images/cinnamon_rose_roll.png', 32000.00, 11, 1, '2026-06-09 02:28:58', '2026-06-09 04:55:39'),
(6, 4, 'Butter Cookies (6pcs)', 'images/butter_cookies.png', 40000.00, 18, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(7, 2, 'Strawberry Tart', 'images/strawberry_tart.png', 38000.00, 6, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(8, 3, 'Dark Chocolate Brownie', 'images/dark_chocolate_brownie.png', 45000.00, 19, 1, '2026-06-09 02:28:58', '2026-06-17 07:03:53'),
(9, 5, 'Cheese Pretzel', 'images/cheese_pretzel.png', 18000.00, 8, 1, '2026-06-09 02:28:58', '2026-06-17 07:03:53'),
(10, 5, 'Ham & Cheese Scroll', 'images/ham_cheese_scroll.png', 25000.00, 14, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(11, 3, 'Mocardi Birthday Cake 6\"', 'images/mocardi_birthday_cake.png', 185000.00, 2, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(12, 7, 'Valentine Meringue Box', 'images/valentine_meringue_box.png', 95000.00, 4, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(13, 6, 'Strawberry Milk Latte', 'images/strawberry_milk_latte.png', 32000.00, 8, 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(14, 3, 'Basque Burnt Cheesecake', 'images/basque_burnt_cheesecake.png', 135000.00, 1, 1, '2026-06-09 02:28:58', '2026-06-09 02:32:28');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_categories`
--

INSERT INTO `product_categories` (`id`, `name`) VALUES
(4, 'Cookies'),
(5, 'Gurih'),
(3, 'Kue'),
(6, 'Minuman'),
(2, 'Pastri'),
(1, 'Roti'),
(7, 'Seasonal');

-- --------------------------------------------------------

--
-- Table structure for table `recipes`
--

CREATE TABLE `recipes` (
  `id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `category_id` tinyint(3) UNSIGNED DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `yield_qty` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `yield_unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `prep_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `bake_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `instructions` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `recipes`
--

INSERT INTO `recipes` (`id`, `product_id`, `category_id`, `image_url`, `name`, `yield_qty`, `yield_unit`, `prep_minutes`, `bake_minutes`, `instructions`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 'images/sourdough_signature.png', 'Sourdough Signature', 1, 'loaf', 60, 45, '1. Campur tepung dan air, istirahat 1 jam\n2. Tambahkan starter dan garam\n3. Lipat setiap 30 mnt x4\n4. Bentuk, cold proof semalaman\n5. Panggang 230C dengan uap', 'Gunakan Dutch oven', 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(2, 1, 2, 'images/mocardi_croissant.png', 'Mocardi Croissant', 12, 'pcs', 120, 20, '1. Buat adonan, dinginkan 1 jam\n2. Laminasi mentega (3 lipatan)\n3. Istirahat 30 mnt per lipatan\n4. Bentuk, proof 2 jam\n5. Olesi telur, panggang 190C', 'Jaga mentega tetap dingin', 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(3, 3, 3, 'images/pink_velvet_cupcake.png', 'Pink Velvet Cupcake', 12, 'pcs', 20, 18, '1. Campur bahan kering\n2. Kocok bahan basah terpisah\n3. Gabungkan, isi cetakan 2/3\n4. Panggang 175C 18 menit\n5. Hias dengan cream cheese frosting', 'Tambah pewarna merah muda Mocardi', 1, '2026-06-09 02:28:58', '2026-06-09 02:28:58'),
(4, 4, 1, NULL, 'Banana Walnut Bread', 1, 'loaf', 20, 50, 'Campur bahan kering dan basah. Tambahkan pisang matang dan walnut. Panggang hingga matang.', 'Gunakan pisang yang sangat matang', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(5, 5, 2, NULL, 'Cinnamon Rose Roll', 12, 'pcs', 90, 20, 'Buat adonan manis. Isi campuran gula dan kayu manis. Bentuk mawar lalu panggang.', 'Sajikan hangat', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(6, 6, 4, NULL, 'Butter Cookies (6pcs)', 24, 'pcs', 20, 15, 'Kocok mentega dan gula hingga lembut. Tambahkan tepung lalu cetak dan panggang.', 'Gunakan mentega kualitas tinggi', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(7, 7, 2, NULL, 'Strawberry Tart', 6, 'pcs', 40, 20, 'Panggang kulit tart. Isi pastry cream dan hias dengan stroberi segar.', 'Simpan dingin', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(8, 8, 3, NULL, 'Dark Chocolate Brownie', 12, 'pcs', 15, 30, 'Lelehkan coklat dan mentega. Campur bahan lalu panggang.', 'Jangan overbake', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(9, 9, 5, NULL, 'Cheese Pretzel', 12, 'pcs', 60, 18, 'Bentuk pretzel. Rendam larutan baking soda lalu panggang dengan topping keju.', 'Gunakan keju cheddar', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(10, 10, 5, NULL, 'Ham & Cheese Scroll', 12, 'pcs', 60, 20, 'Gulung adonan dengan ham dan keju. Iris dan panggang.', 'Dapat dibekukan', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(11, 11, 3, NULL, 'Mocardi Birthday Cake 6\"', 1, 'cake', 45, 35, 'Panggang sponge cake. Isi buttercream dan dekorasi sesuai pesanan.', 'Resep dasar birthday cake', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(12, 12, 7, NULL, 'Valentine Meringue Box', 20, 'pcs', 20, 90, 'Kocok putih telur dan gula hingga stiff peak. Panggang suhu rendah.', 'Simpan dalam wadah kedap udara', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(13, 13, 6, NULL, 'Strawberry Milk Latte', 1, 'cup', 5, 0, 'Campur puree stroberi, susu dan es batu.', 'Disajikan dingin', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01'),
(14, 14, 3, NULL, 'Basque Burnt Cheesecake', 1, 'cake', 20, 40, 'Campur cream cheese, telur, gula dan cream. Panggang suhu tinggi hingga bagian atas gosong.', 'Ciri khas bagian atas terbakar', 1, '2026-06-09 05:26:01', '2026-06-09 05:26:01');

-- --------------------------------------------------------

--
-- Table structure for table `recipe_ingredients`
--

CREATE TABLE `recipe_ingredients` (
  `id` int(10) UNSIGNED NOT NULL,
  `recipe_id` int(10) UNSIGNED NOT NULL,
  `inventory_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'g',
  `notes` varchar(120) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `recipe_ingredients`
--

INSERT INTO `recipe_ingredients` (`id`, `recipe_id`, `inventory_id`, `quantity`, `unit`, `notes`) VALUES
(1, 1, 1, 500.000, 'g', ''),
(2, 1, 2, 7.000, 'g', ''),
(3, 1, 5, 10.000, 'g', ''),
(4, 2, 1, 500.000, 'g', ''),
(5, 2, 2, 10.000, 'g', ''),
(6, 2, 3, 250.000, 'g', ''),
(7, 2, 5, 60.000, 'g', ''),
(8, 2, 6, 300.000, 'ml', ''),
(9, 2, 4, 1.000, 'pcs', ''),
(10, 3, 9, 200.000, 'g', ''),
(11, 3, 7, 50.000, 'g', ''),
(12, 3, 5, 200.000, 'g', ''),
(13, 3, 4, 2.000, 'pcs', ''),
(14, 3, 11, 120.000, 'ml', ''),
(15, 3, 6, 120.000, 'ml', ''),
(16, 3, 12, 5.000, 'ml', ''),
(17, 4, 1, 350.000, 'g', ''),
(18, 4, 5, 80.000, 'g', ''),
(19, 4, 4, 2.000, 'pcs', ''),
(20, 4, 19, 80.000, 'g', ''),
(21, 4, 25, 5.000, 'g', ''),
(22, 5, 1, 500.000, 'g', ''),
(23, 5, 2, 8.000, 'g', ''),
(24, 5, 5, 60.000, 'g', ''),
(25, 5, 3, 80.000, 'g', ''),
(26, 5, 14, 15.000, 'g', ''),
(27, 5, 4, 1.000, 'pcs', ''),
(28, 5, 6, 250.000, 'ml', ''),
(29, 6, 3, 250.000, 'g', ''),
(30, 6, 10, 120.000, 'g', ''),
(31, 6, 9, 300.000, 'g', ''),
(32, 6, 4, 2.000, 'pcs', ''),
(33, 7, 9, 250.000, 'g', ''),
(34, 7, 3, 120.000, 'g', ''),
(35, 7, 4, 2.000, 'pcs', ''),
(36, 7, 20, 200.000, 'g', ''),
(37, 7, 16, 150.000, 'g', ''),
(38, 8, 7, 120.000, 'g', ''),
(39, 8, 3, 180.000, 'g', ''),
(40, 8, 5, 150.000, 'g', ''),
(41, 8, 4, 3.000, 'pcs', ''),
(42, 8, 1, 150.000, 'g', ''),
(43, 9, 1, 500.000, 'g', ''),
(44, 9, 2, 8.000, 'g', ''),
(45, 9, 6, 250.000, 'ml', ''),
(46, 9, 23, 20.000, 'g', ''),
(47, 9, 22, 120.000, 'g', '');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `bakery_name` varchar(120) NOT NULL DEFAULT 'Mocardi',
  `owner_name` varchar(100) NOT NULL DEFAULT '',
  `phone` varchar(30) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 10.00,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `receipt_message` text DEFAULT NULL,
  `social_info` varchar(255) NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `bakery_name`, `owner_name`, `phone`, `address`, `tax_rate`, `currency`, `receipt_message`, `social_info`, `updated_at`) VALUES
(1, 'Mocardi', 'Monika Cahyadi', '+62 821-5678-9000', 'Jl. Anggrek Raya No. 7, Bandung', 12.00, 'IDR', 'Terima kasih telah memilih Mocardi — dipanggang dengan cinta setiap hari', 'IG: @mocardi.bakery  ·  WA: 0821-5678-9000', '2026-06-09 04:55:25');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_daily_production`
-- (See below for the actual view)
--
CREATE TABLE `v_daily_production` (
`batch_date` date
,`product_name` varchar(120)
,`total_qty` decimal(32,0)
,`bakers` mediumtext
,`status` enum('planned','in-progress','completed','discarded')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_low_stock`
-- (See below for the actual view)
--
CREATE TABLE `v_low_stock` (
`id` int(10) unsigned
,`category` varchar(60)
,`name` varchar(120)
,`stock` decimal(10,2)
,`min_stock` decimal(10,2)
,`unit` enum('g','kg','ml','L','pcs','sachet','tbsp','tsp')
,`supplier` varchar(120)
,`deficit` decimal(11,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_monthly_expenses`
-- (See below for the actual view)
--
CREATE TABLE `v_monthly_expenses` (
`month` varchar(7)
,`category` enum('Ingredients','Packaging','Utilities','Equipment','Marketing','Other')
,`total_amount` decimal(34,2)
,`num_entries` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_order_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_order_summary` (
`id` int(10) unsigned
,`order_ref` varchar(20)
,`customer_name` varchar(120)
,`customer_phone` varchar(30)
,`type` enum('walkin','preorder')
,`pickup_date` date
,`pickup_time` time
,`total` decimal(12,2)
,`deposit` decimal(12,2)
,`balance_due` decimal(13,2)
,`status` enum('pending','in-progress','ready','completed','cancelled')
,`payment_method` varchar(40)
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `v_daily_production`
--
DROP TABLE IF EXISTS `v_daily_production`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_daily_production`  AS SELECT `pb`.`batch_date` AS `batch_date`, `pb`.`product_name` AS `product_name`, sum(`pb`.`qty_produced`) AS `total_qty`, group_concat(`pb`.`baker` order by `pb`.`id` ASC separator ', ') AS `bakers`, `pb`.`status` AS `status` FROM `production_batches` AS `pb` GROUP BY `pb`.`batch_date`, `pb`.`product_name`, `pb`.`status` ORDER BY `pb`.`batch_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_low_stock`
--
DROP TABLE IF EXISTS `v_low_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_low_stock`  AS SELECT `i`.`id` AS `id`, `ic`.`name` AS `category`, `i`.`name` AS `name`, `i`.`stock` AS `stock`, `i`.`min_stock` AS `min_stock`, `i`.`unit` AS `unit`, `i`.`supplier` AS `supplier`, `i`.`min_stock`- `i`.`stock` AS `deficit` FROM (`inventory` `i` join `ingredient_categories` `ic` on(`ic`.`id` = `i`.`category_id`)) WHERE `i`.`stock` < `i`.`min_stock` ORDER BY `i`.`min_stock`- `i`.`stock` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_monthly_expenses`
--
DROP TABLE IF EXISTS `v_monthly_expenses`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_monthly_expenses`  AS SELECT date_format(`expenses`.`expense_date`,'%Y-%m') AS `month`, `expenses`.`category` AS `category`, sum(`expenses`.`amount`) AS `total_amount`, count(0) AS `num_entries` FROM `expenses` GROUP BY date_format(`expenses`.`expense_date`,'%Y-%m'), `expenses`.`category` ORDER BY date_format(`expenses`.`expense_date`,'%Y-%m') DESC, sum(`expenses`.`amount`) DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_order_summary`
--
DROP TABLE IF EXISTS `v_order_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_order_summary`  AS SELECT `o`.`id` AS `id`, `o`.`order_ref` AS `order_ref`, coalesce(`c`.`name`,`o`.`guest_name`) AS `customer_name`, coalesce(`c`.`phone`,`o`.`guest_phone`) AS `customer_phone`, `o`.`type` AS `type`, `o`.`pickup_date` AS `pickup_date`, `o`.`pickup_time` AS `pickup_time`, `o`.`total` AS `total`, `o`.`deposit` AS `deposit`, `o`.`total`- `o`.`deposit` AS `balance_due`, `o`.`status` AS `status`, `o`.`payment_method` AS `payment_method`, `o`.`created_at` AS `created_at` FROM (`orders` `o` left join `customers` `c` on(`c`.`id` = `o`.`customer_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cust_name` (`name`),
  ADD KEY `idx_cust_phone` (`phone`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exp_date` (`expense_date`),
  ADD KEY `idx_exp_cat` (`category`);

--
-- Indexes for table `ingredient_categories`
--
ALTER TABLE `ingredient_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ingcat` (`name`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inv_cat` (`category_id`);

--
-- Indexes for table `inventory_restock_log`
--
ALTER TABLE `inventory_restock_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_restock` (`inventory_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_ref` (`order_ref`),
  ADD KEY `idx_ord_cust` (`customer_id`),
  ADD KEY `idx_ord_status` (`status`),
  ADD KEY `idx_ord_pickup` (`pickup_date`),
  ADD KEY `idx_ord_created` (`created_at`);

--
-- Indexes for table `production_batches`
--
ALTER TABLE `production_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pb_date` (`batch_date`),
  ADD KEY `fk_pb_recipe` (`recipe_id`),
  ADD KEY `fk_pb_product` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prod_cat` (`category_id`),
  ADD KEY `idx_prod_active` (`is_active`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pcat` (`name`);

--
-- Indexes for table `recipes`
--
ALTER TABLE `recipes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rec_prod` (`product_id`),
  ADD KEY `fk_rec_cat` (`category_id`);

--
-- Indexes for table `recipe_ingredients`
--
ALTER TABLE `recipe_ingredients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ri` (`recipe_id`,`inventory_id`),
  ADD KEY `fk_ri_inv` (`inventory_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `ingredient_categories`
--
ALTER TABLE `ingredient_categories`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `inventory_restock_log`
--
ALTER TABLE `inventory_restock_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `production_batches`
--
ALTER TABLE `production_batches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `recipes`
--
ALTER TABLE `recipes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `recipe_ingredients`
--
ALTER TABLE `recipe_ingredients`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_inv_cat` FOREIGN KEY (`category_id`) REFERENCES `ingredient_categories` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `inventory_restock_log`
--
ALTER TABLE `inventory_restock_log`
  ADD CONSTRAINT `fk_restock` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_ord_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `production_batches`
--
ALTER TABLE `production_batches`
  ADD CONSTRAINT `fk_pb_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pb_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_prod_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `recipes`
--
ALTER TABLE `recipes`
  ADD CONSTRAINT `fk_rec_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rec_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `recipe_ingredients`
--
ALTER TABLE `recipe_ingredients`
  ADD CONSTRAINT `fk_ri_inv` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ri_rec` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
