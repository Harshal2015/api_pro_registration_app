-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 04, 2025 at 02:23 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `prop_propass`
--

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `short_name` varchar(100) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `banner_image` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `code` varchar(20) DEFAULT NULL,
  `location_link` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `short_name`, `full_name`, `banner_image`, `start_date`, `end_date`, `venue`, `address`, `city`, `code`, `location_link`, `created_at`, `modified_at`, `is_deleted`) VALUES
(2, 'indiavalves2025', 'India Valves 2025', 'http://localhost/Pro_Registration/images/India Valves 2025.svg', '2025-07-03', '2025-09-13', 'Jaipur Mariott Hotel', 'Mathura Road, near ITO', 'Rajasthan ', '302015', 'https://maps.google.com/?q=cgasg', '2025-07-04 11:01:36', '2025-07-04 12:18:46', 0),
(3, 'ipci2025', 'iPCI 2025', 'http://localhost/Pro_Registration/images/IPCI-2025.svg', '2025-08-08', '2025-08-10', 'Trident', 'Survey No.64, Hitech City Main Rd, near Cyber Towers, Jubilee Enclave', 'Hyderabad', '560073', 'https://maps.google.com/?q=Bangalore+International+Exhibition+Centre', '2025-07-04 11:01:36', '2025-07-04 11:47:24', 0);

-- --------------------------------------------------------

--
-- Table structure for table `functionalities`
--

CREATE TABLE `functionalities` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `count` int(255) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `functionalities`
--

INSERT INTO `functionalities` (`id`, `name`, `count`, `created_at`, `modified_at`, `is_deleted`) VALUES
(1, 'Badge - Print', 0, '2025-07-03 10:58:40', '2025-07-04 07:18:49', 0),
(2, 'Attendance', 0, '2025-07-03 10:58:40', '2025-07-04 09:02:40', 0),
(3, 'Kit', 0, '2025-07-03 10:58:40', '2025-07-04 07:20:11', 0),
(4, 'Certificate', 0, '2025-07-03 10:58:40', '2025-07-04 07:20:19', 0),
(5, 'Food - Lunch', 0, '2025-07-03 10:58:40', '2025-07-04 07:20:27', 0),
(6, 'Food - Dinner', 0, '2025-07-03 10:58:40', '2025-07-04 09:03:51', 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `access_chips` int(10) DEFAULT 1 COMMENT '1- Qr Scan\r\n2 - Registration\r\n3 - Reports\r\n4 - Qr & Registration\r\n5 - Registartion & Report\r\n6 - Qr scan & Reports\r\n7 - All Access to chips\r\n',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `access_chips`, `is_admin`, `is_deleted`, `created_at`, `modified_at`) VALUES
(1, 'Registration1', '123456', 1, 1, 0, '2025-07-03 10:56:28', '2025-07-04 12:15:47'),
(2, 'Registration2', '123456', 4, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:34:31'),
(3, 'Registration3', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:30:22'),
(4, 'Registration4', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:30:18'),
(5, 'Registration5', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:30:13'),
(6, 'Kit1', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:30:10'),
(7, 'Kit2', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:30:07'),
(8, 'Food1', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:30:01'),
(9, 'Food2', '123456', 1, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:29:58'),
(10, 'Admin', '123456', 7, 0, 0, '2025-07-03 10:56:28', '2025-07-04 10:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `user_access`
--

CREATE TABLE `user_access` (
  `user_id` int(11) NOT NULL,
  `functionality_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_access`
--

INSERT INTO `user_access` (`user_id`, `functionality_id`, `created_at`, `modified_at`, `is_deleted`) VALUES
(1, 1, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(1, 2, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(2, 1, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(2, 2, '2025-07-03 11:00:57', '2025-07-04 09:56:54', 0),
(3, 1, '2025-07-03 11:00:57', '2025-07-04 09:57:31', 0),
(3, 2, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(4, 1, '2025-07-03 11:00:57', '2025-07-04 09:57:46', 0),
(4, 2, '2025-07-03 11:00:57', '2025-07-04 09:57:38', 0),
(5, 1, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(5, 2, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(6, 3, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(7, 3, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(8, 5, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(8, 6, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(9, 5, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(9, 6, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(10, 1, '2025-07-03 11:00:57', '2025-07-04 09:57:46', 0),
(10, 2, '2025-07-03 11:00:57', '2025-07-03 11:00:57', 0),
(10, 3, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(10, 4, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(10, 5, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0),
(10, 6, '2025-07-03 11:00:57', '2025-07-04 09:57:25', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `functionalities`
--
ALTER TABLE `functionalities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_access`
--
ALTER TABLE `user_access`
  ADD PRIMARY KEY (`user_id`,`functionality_id`),
  ADD KEY `functionality_id` (`functionality_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `functionalities`
--
ALTER TABLE `functionalities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_access`
--
ALTER TABLE `user_access`
  ADD CONSTRAINT `user_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_access_ibfk_2` FOREIGN KEY (`functionality_id`) REFERENCES `functionalities` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
