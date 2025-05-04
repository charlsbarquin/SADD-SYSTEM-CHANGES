-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3308
-- Generation Time: May 04, 2025 at 02:25 PM
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
-- Database: `attendance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `login_attempts` int(11) DEFAULT 0,
  `last_attempt` datetime DEFAULT NULL,
  `account_locked` tinyint(1) DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `last_failed_attempt_ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `email`, `created_at`, `login_attempts`, `last_attempt`, `account_locked`, `lock_until`, `last_failed_attempt_ip`) VALUES
(3, 'admin', '$2y$10$jl/sAqn1Faery8LpnSYWqerEP76ah6/YZiBl2uTSva1LZfP99AgrG', '', '2025-03-31 08:09:18', 0, NULL, 0, NULL, '::1');

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

CREATE TABLE `admin_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `professor_id` int(11) NOT NULL,
  `am_check_in` datetime DEFAULT NULL,
  `am_check_out` datetime DEFAULT NULL,
  `pm_check_in` datetime DEFAULT NULL,
  `pm_check_out` datetime DEFAULT NULL,
  `status` enum('present','absent','half-day') DEFAULT 'absent',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `checkin_date` date NOT NULL DEFAULT curdate(),
  `date` date DEFAULT NULL,
  `work_duration` time DEFAULT NULL,
  `is_late` tinyint(1) DEFAULT 0,
  `schedule_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `professor_id`, `am_check_in`, `am_check_out`, `pm_check_in`, `pm_check_out`, `status`, `recorded_at`, `checkin_date`, `date`, `work_duration`, `is_late`, `schedule_id`) VALUES
(124, 60, NULL, NULL, '2025-05-03 15:44:07', '2025-05-03 17:28:24', 'present', '2025-05-03 07:44:07', '2025-05-03', '2025-05-03', '01:44:17', 0, NULL),
(125, 61, NULL, NULL, '2025-05-03 15:49:07', '2025-05-03 17:28:05', 'present', '2025-05-03 07:49:07', '2025-05-03', '2025-05-03', '01:38:58', 0, NULL),
(126, 62, NULL, NULL, '2025-05-03 22:16:59', '2025-05-03 23:10:24', 'present', '2025-05-03 14:16:59', '2025-05-03', '2025-05-03', '00:53:25', 0, NULL),
(127, 60, '2025-05-04 07:03:14', '2025-05-04 09:40:55', NULL, '2025-05-04 12:45:02', 'present', '2025-05-03 23:03:14', '2025-05-04', '2025-05-04', '05:41:48', 0, NULL),
(128, 60, NULL, NULL, '2025-05-04 12:00:17', '2025-05-04 12:45:02', 'present', '2025-05-04 04:00:17', '2025-05-04', '2025-05-04', '00:44:45', 0, NULL),
(129, 62, NULL, NULL, '2025-05-04 13:25:42', '2025-05-04 13:27:40', 'present', '2025-05-04 05:25:42', '2025-05-04', '2025-05-04', '00:01:58', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `user` varchar(100) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logs`
--

INSERT INTO `logs` (`id`, `action`, `user`, `timestamp`, `is_read`) VALUES
(515, 'New professor registered: Charls Emil C. Barquin', 'system', '2025-05-03 15:43:15', 0),
(516, 'Professor timed in for PM session', 'Charls Emil C. Barquin', '2025-05-03 15:44:07', 0),
(517, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-03 15:46:04', 0),
(518, 'New professor registered: Asher Caubang', 'system', '2025-05-03 15:49:00', 0),
(519, 'Professor timed in for PM session', 'Asher Caubang', '2025-05-03 15:49:07', 0),
(520, 'Professor updated schedule', 'Asher Caubang', '2025-05-03 15:51:04', 0),
(521, 'Professor timed out from PM session', 'Asher Caubang', '2025-05-03 17:28:05', 0),
(522, 'Professor timed out from PM session', 'Charls Emil C. Barquin', '2025-05-03 17:28:24', 0),
(523, 'New professor registered: Mark Guillermo', 'system', '2025-05-03 22:16:40', 0),
(524, 'Professor timed in for PM session', 'Mark Guillermo', '2025-05-03 22:16:59', 0),
(525, 'Professor updated schedule', 'Mark Guillermo', '2025-05-03 22:20:20', 0),
(526, 'Professor timed out from PM session', 'Mark Guillermo', '2025-05-03 23:10:24', 0),
(527, 'Professor updated schedule', 'Mark Guillermo', '2025-05-03 23:17:12', 0),
(528, 'Professor updated schedule', 'Mark Guillermo', '2025-05-04 00:06:58', 0),
(529, 'Professor updated schedule', 'Mark Guillermo', '2025-05-04 00:07:04', 0),
(530, 'Professor updated schedule', 'Mark Guillermo', '2025-05-04 00:07:25', 0),
(531, 'Professor updated schedule', 'Mark Guillermo', '2025-05-04 00:07:48', 0),
(532, 'Professor timed in for AM session', 'Charls Emil C. Barquin', '2025-05-04 07:03:14', 0),
(533, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 07:17:26', 0),
(534, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 07:29:49', 0),
(535, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 07:30:16', 0),
(536, 'Professor timed out from AM session', 'Charls Emil C. Barquin', '2025-05-04 09:40:55', 0),
(537, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:44:06', 0),
(538, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:44:31', 0),
(539, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:45:41', 0),
(540, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:46:02', 0),
(541, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:53:41', 0),
(542, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:54:04', 0),
(543, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:54:13', 0),
(544, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:54:26', 0),
(545, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 09:54:40', 0),
(546, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:03:28', 0),
(547, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:07:52', 0),
(548, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:18:00', 0),
(549, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:20:41', 0),
(550, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:24:11', 0),
(551, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:24:29', 0),
(552, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:25:12', 0),
(553, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:36:11', 0),
(554, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:36:20', 0),
(555, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 10:36:29', 0),
(556, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 10:58:07', 0),
(557, 'Chatbot interaction: what room is my class in?', 'Charls Emil C. Barquin', '2025-05-04 10:58:10', 0),
(558, 'Chatbot interaction: how do i time out?', 'Charls Emil C. Barquin', '2025-05-04 10:58:15', 0),
(559, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:10:46', 0),
(560, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:12:02', 0),
(561, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:12:50', 0),
(562, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:13:01', 0),
(563, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:13:13', 0),
(564, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:13:17', 0),
(565, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:13:33', 0),
(566, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:23:49', 0),
(567, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:24:25', 0),
(568, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:24:36', 0),
(569, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:24:42', 0),
(570, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:24:56', 0),
(571, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:25:03', 0),
(572, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:25:13', 0),
(573, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:25:14', 0),
(574, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:25:33', 0),
(575, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:25:58', 0),
(576, 'Professor updated schedule', 'Charls Emil C. Barquin', '2025-05-04 11:41:43', 0),
(577, 'Professor timed in for PM session', 'Charls Emil C. Barquin', '2025-05-04 12:00:17', 0),
(578, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 12:09:56', 0),
(579, 'Chatbot interaction: what\'s my next class?', 'Charls Emil C. Barquin', '2025-05-04 12:09:59', 0),
(580, 'Chatbot interaction: what\'s my current status?', 'Charls Emil C. Barquin', '2025-05-04 12:10:02', 0),
(581, 'Chatbot interaction: how do i time in?', 'Charls Emil C. Barquin', '2025-05-04 12:10:10', 0),
(582, 'Chatbot interaction: how do i time out?', 'Charls Emil C. Barquin', '2025-05-04 12:10:17', 0),
(583, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 12:36:35', 0),
(584, 'Chatbot interaction: how to time in?', 'Charls Emil C. Barquin', '2025-05-04 12:38:58', 0),
(585, 'Chatbot interaction: what is the use of this system?', 'Charls Emil C. Barquin', '2025-05-04 12:39:16', 0),
(586, 'Chatbot interaction: wheres my attendance?', 'Charls Emil C. Barquin', '2025-05-04 12:39:29', 0),
(587, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 12:44:45', 0),
(588, 'Chatbot interaction: what room is my class in?', 'Charls Emil C. Barquin', '2025-05-04 12:44:48', 0),
(589, 'Chatbot interaction: how do i time out?', 'Charls Emil C. Barquin', '2025-05-04 12:44:51', 0),
(590, 'Professor timed out from PM session', 'Charls Emil C. Barquin', '2025-05-04 12:45:02', 0),
(591, 'Professor timed in for PM session', 'Mark Guillermo', '2025-05-04 13:25:42', 0),
(592, 'Professor timed out from PM session', 'Mark Guillermo', '2025-05-04 13:27:40', 0),
(593, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:11:38', 0),
(594, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 17:11:45', 0),
(595, 'Chatbot interaction: when is my next class?', 'Charls Emil C. Barquin', '2025-05-04 17:11:51', 0),
(596, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:12:01', 0),
(597, 'Chatbot interaction: am i late for my next class?', 'Charls Emil C. Barquin', '2025-05-04 17:12:03', 0),
(598, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:12:06', 0),
(599, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:12:28', 0),
(600, 'Chatbot interaction: how do i time out?', 'Charls Emil C. Barquin', '2025-05-04 17:12:31', 0),
(601, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:12:34', 0),
(602, 'Chatbot interaction: what\'s my current status?', 'Charls Emil C. Barquin', '2025-05-04 17:12:50', 0),
(603, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:14:11', 0),
(604, 'Chatbot interaction: how to time in?', 'Charls Emil C. Barquin', '2025-05-04 17:14:19', 0),
(605, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:17:14', 0),
(606, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:20:23', 0),
(607, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:26:13', 0),
(608, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:27:05', 0),
(609, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:32:00', 0),
(610, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:32:03', 0),
(611, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:32:28', 0),
(612, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:32:30', 0),
(613, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:40:43', 0),
(614, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:40:45', 0),
(615, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:41:11', 0),
(616, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 17:44:13', 0),
(617, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:44:14', 0),
(618, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:48:18', 0),
(619, 'Chatbot interaction: how do i time out?', 'Charls Emil C. Barquin', '2025-05-04 17:48:23', 0),
(620, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 17:48:29', 0),
(621, 'Chatbot interaction: what', 'Charls Emil C. Barquin', '2025-05-04 17:48:37', 0),
(622, 'Chatbot interaction: next class', 'Charls Emil C. Barquin', '2025-05-04 17:50:10', 0),
(623, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 18:19:14', 0),
(624, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 18:20:19', 0),
(625, 'Chatbot interaction: what', 'Charls Emil C. Barquin', '2025-05-04 18:21:10', 0),
(626, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 18:21:13', 0),
(627, 'Chatbot interaction: department attendance stats', 'Charls Emil C. Barquin', '2025-05-04 18:23:12', 0),
(628, 'Chatbot interaction: what\'s my schedule today?', 'Mark Guillermo', '2025-05-04 18:25:16', 0),
(629, 'Chatbot interaction: what room is my class in?', 'Mark Guillermo', '2025-05-04 18:25:18', 0),
(630, 'Chatbot interaction: am i late for any classes?', 'Mark Guillermo', '2025-05-04 18:25:21', 0),
(631, 'Chatbot interaction: what', 'Mark Guillermo', '2025-05-04 18:25:28', 0),
(632, 'Chatbot interaction: department attendance stats', 'Mark Guillermo', '2025-05-04 18:25:31', 0),
(633, 'Chatbot interaction: department attendance stats', 'Mark Guillermo', '2025-05-04 18:25:45', 0),
(634, 'Chatbot interaction: what\'s my next class?', 'Mark Guillermo', '2025-05-04 18:25:54', 0),
(635, 'Chatbot interaction: what', 'Mark Guillermo', '2025-05-04 18:26:18', 0),
(636, 'Chatbot interaction: what', 'Mark Guillermo', '2025-05-04 18:27:57', 0),
(637, 'Chatbot interaction: what', 'Mark Guillermo', '2025-05-04 18:28:00', 0),
(638, 'Chatbot interaction: next class', 'Mark Guillermo', '2025-05-04 18:28:19', 0),
(639, 'Chatbot interaction: when is my next class?', 'Mark Guillermo', '2025-05-04 18:28:22', 0),
(640, 'Chatbot interaction: what room is my class in?', 'Mark Guillermo', '2025-05-04 18:28:25', 0),
(641, 'Chatbot interaction: what', 'Charls Emil C. Barquin', '2025-05-04 20:23:36', 0),
(642, 'Chatbot interaction: what\'s my schedule today?', 'Charls Emil C. Barquin', '2025-05-04 20:23:41', 0),
(643, 'Chatbot interaction: what\'s my next class?', 'Charls Emil C. Barquin', '2025-05-04 20:23:48', 0),
(644, 'Chatbot interaction: what\'s my current status?', 'Charls Emil C. Barquin', '2025-05-04 20:23:53', 0);

--
-- Triggers `logs`
--
DELIMITER $$
CREATE TRIGGER `enforce_log_terms` BEFORE INSERT ON `logs` FOR EACH ROW BEGIN
    SET NEW.action = REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(NEW.action, 'check-in', 'time-in'),
                'check-out', 'time-out'),
            'checked in', 'timed in'),
        'checked out', 'timed out');
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'time-in or time-out',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `message`, `type`, `created_at`, `is_read`) VALUES
(186, 'Charls Emil C. Barquin has timed in for PM session at 03:44 PM', 'time-in', '2025-05-03 15:44:07', 1),
(187, 'Asher Caubang has timed in for PM session at 03:49 PM', 'time-in', '2025-05-03 15:49:07', 1),
(188, 'Asher Caubang has timed out from PM session at 05:28 PM', 'time-out', '2025-05-03 17:28:05', 1),
(189, 'Charls Emil C. Barquin has timed out from PM session at 05:28 PM', 'time-out', '2025-05-03 17:28:24', 1),
(190, 'Mark Guillermo has timed in for PM session at 10:16 PM', 'time-in', '2025-05-03 22:16:59', 1),
(191, 'Mark Guillermo has timed out from PM session at 11:10 PM', 'time-out', '2025-05-03 23:10:24', 1),
(192, 'Charls Emil C. Barquin has timed in for AM session at 07:03 AM', 'time-in', '2025-05-04 07:03:14', 1),
(193, 'Charls Emil C. Barquin has timed out from AM session at 09:40 AM', 'time-out', '2025-05-04 09:40:55', 1),
(194, 'Charls Emil C. Barquin has timed in for PM session at 12:00 PM', 'time-in', '2025-05-04 12:00:17', 1),
(195, 'Charls Emil C. Barquin has timed out from PM session at 12:45 PM', 'time-out', '2025-05-04 12:45:02', 1),
(196, 'Mark Guillermo has timed in for PM session at 01:25 PM', 'time-in', '2025-05-04 13:25:42', 1),
(197, 'Mark Guillermo has timed out from PM session at 01:27 PM', 'time-out', '2025-05-04 13:27:40', 1);

--
-- Triggers `notifications`
--
DELIMITER $$
CREATE TRIGGER `enforce_time_terms` BEFORE INSERT ON `notifications` FOR EACH ROW BEGIN
    SET NEW.message = REPLACE(
        REPLACE(NEW.message, 'checked in', 'timed in'),
        'checked out',
        'timed out'
    );
    SET NEW.type = REPLACE(
        REPLACE(NEW.type, 'check-in', 'time-in'),
        'check-out',
        'time-out'
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `professors`
--

CREATE TABLE `professors` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `designation` varchar(50) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','pending') DEFAULT 'active',
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `department` varchar(100) NOT NULL DEFAULT 'Computer Studies Department',
  `phone` varchar(20) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `last_attempt` datetime DEFAULT NULL,
  `account_locked` tinyint(1) DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `last_failed_attempt_ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `professors`
--

INSERT INTO `professors` (`id`, `name`, `email`, `designation`, `profile_image`, `created_at`, `status`, `approved_at`, `approved_by`, `department`, `phone`, `username`, `password`, `login_attempts`, `last_attempt`, `account_locked`, `lock_until`, `last_failed_attempt_ip`) VALUES
(1, 'Arnold B. Platon', 'johndoe@bup.edu.ph', 'Department Head', '', '2025-02-08 05:22:23', 'active', '2025-04-01 15:55:30', 3, 'Computer Studies Department', '0917283016', NULL, NULL, 0, NULL, 0, NULL, NULL),
(2, 'Vince Angelo E. Naz ', 'janesmith@bup.edu.ph', 'BSIT Program Coordinator', '', '2025-02-08 05:22:23', 'active', '2025-04-01 18:20:07', 3, 'Computer Studies Department', '0950475140', NULL, NULL, 0, NULL, 0, NULL, NULL),
(3, 'Jerry B. Agsunod', 'markdelacruz@bup.edu.ph', 'BSCS Program Coordinator', '', '2025-02-08 05:22:23', 'active', '2025-04-01 18:38:31', 3, 'Computer Studies Department', '0900526661', NULL, NULL, 0, NULL, 0, NULL, NULL),
(4, 'Paulo L. Perete', 'mariasantos@bup.edu.ph', 'BSIT-Animation Program Coordinator', '', '2025-02-08 06:17:30', 'active', '2025-04-03 14:10:31', 3, 'Computer Studies Department', '0951207890', NULL, NULL, 0, NULL, 0, NULL, NULL),
(5, 'Guillermo V. Red, Jr.', 'rafaelcruz@bup.edu.ph', 'BSIS Program Coordinator', '', '2025-02-08 06:17:30', 'active', '2025-04-03 14:41:12', 3, 'Computer Studies Department', '0954459468', NULL, NULL, 0, NULL, 0, NULL, NULL),
(6, 'Maria Charmy A. Arispe', 'angelareyes@bup.edu.ph', 'College IMO Coordinator', '', '2025-02-08 06:17:30', 'active', '2025-04-12 13:26:20', 3, 'Computer Studies Department', '0918673672', NULL, NULL, 0, NULL, 0, NULL, NULL),
(7, 'Blessica B. Dorosan', 'michaeltan@bup.edu.ph', 'Professor', '', '2025-02-08 06:17:30', 'active', '2025-04-17 10:31:34', 3, 'Computer Studies Department', '0929989963', NULL, NULL, 0, NULL, 0, NULL, NULL),
(8, 'Suzanne S. Causapin', 'sophiagomez@bup.edu.ph', 'Professor', '', '2025-02-08 06:17:30', 'active', '2025-04-26 10:09:16', 3, 'Computer Studies Department', '0993928801', NULL, NULL, 0, NULL, 0, NULL, NULL),
(9, 'Khristine A. Botin', 'carlosvillanueva@bup.edu.ph', 'Professor', '', '2025-02-08 06:17:30', 'active', '2025-04-26 10:09:46', 3, 'Computer Studies Department', '0979674122', NULL, NULL, 0, NULL, 0, NULL, NULL),
(10, 'Jorge Sulipicio S. Aganan', 'jessicalim@bup.edu.ph', 'Professor', '', '2025-02-08 06:17:30', 'active', '2025-04-26 10:20:54', 3, 'Computer Studies Department', '0916584207', NULL, NULL, 0, NULL, 0, NULL, NULL),
(11, 'Joseph L. Carinan', 'benedictchua@bup.edu.ph', 'College Document Custodian', '', '2025-02-08 06:17:30', 'active', '2025-04-26 10:31:41', 3, 'Computer Studies Department', '0943898675', NULL, NULL, 0, NULL, 0, NULL, NULL),
(12, 'Mary Antoniette S. Ariño', 'oliviamendoza@bup.edu.ph', 'College SIP Coordinator', '', '2025-02-08 06:17:30', 'active', '2025-04-26 10:56:41', 3, 'Computer Studies Department', '0969740758', NULL, NULL, 0, NULL, 0, NULL, NULL),
(60, 'Charls Emil C. Barquin', 'charls@gmail.com', 'Professor', NULL, '2025-05-03 07:43:15', 'active', NULL, NULL, 'Computer Studies Department', '09386090970', 'Charls', '$2y$10$4kd/HlvXsb33ytHPJKPiuuQ1IXDHqzWHPaUNLysaA.nH0Kz9beO2m', 0, NULL, 0, NULL, NULL),
(61, 'Asher Caubang', 'ash@gmail.com', 'Professor', NULL, '2025-05-03 07:49:00', 'active', NULL, NULL, 'Computer Studies Department', '0938293719373', 'Ash', '$2y$10$TQ33.8fgufN6TYUQhZvZBeiwCyzPHwL0UwgFPzLgNJKsqjvS8QVzq', 0, NULL, 0, NULL, NULL),
(62, 'Mark Guillermo', 'mark@gmail.com', 'Professor', NULL, '2025-05-03 14:16:40', 'active', NULL, NULL, 'Computer Studies Department', '09736283638', 'Mark', '$2y$10$6UZbHdRTOzhTdkQ83/7ijuaTMqZ4M99UAPcxRELzbXK2Hkb0qzoH6', 0, NULL, 0, NULL, NULL);

--
-- Triggers `professors`
--
DELIMITER $$
CREATE TRIGGER `after_professor_insert` AFTER INSERT ON `professors` FOR EACH ROW BEGIN
    INSERT INTO logs (action, user, timestamp)
    VALUES (CONCAT('New professor registered: ', NEW.name), 'system', NOW());
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `professor_schedules`
--

CREATE TABLE `professor_schedules` (
  `id` int(11) NOT NULL,
  `professor_id` int(11) NOT NULL,
  `day_id` int(11) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `room` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `professor_schedules`
--

INSERT INTO `professor_schedules` (`id`, `professor_id`, `day_id`, `start_time`, `end_time`, `subject`, `room`, `created_at`, `updated_at`) VALUES
(187, 61, 1, '13:00:00', '15:00:00', 'MMW', 'CL 3', '2025-05-03 07:51:04', '2025-05-03 07:51:04'),
(188, 61, 2, '14:30:00', '17:30:00', 'SADD', 'CL 4', '2025-05-03 07:51:04', '2025-05-03 07:51:04'),
(189, 61, 3, '07:30:00', '09:00:00', 'NSTP', 'ECB 202', '2025-05-03 07:51:04', '2025-05-03 07:51:04'),
(190, 61, 4, '15:00:00', '17:00:00', 'DSA', 'CL 4', '2025-05-03 07:51:04', '2025-05-03 07:51:04'),
(191, 61, 5, '09:00:00', '12:00:00', 'IM', 'CL 6', '2025-05-03 07:51:04', '2025-05-03 07:51:04'),
(192, 61, 6, '15:30:00', '17:00:00', 'PATHFIT', 'GYM 5', '2025-05-03 07:51:04', '2025-05-03 07:51:04'),
(219, 62, 1, '07:00:00', '09:00:00', 'MMW', 'SB 3', '2025-05-03 16:07:48', '2025-05-03 16:07:48'),
(220, 62, 1, '09:00:00', '12:00:00', 'mmw', 'cl3', '2025-05-03 16:07:48', '2025-05-03 16:07:48'),
(221, 62, 2, '09:00:00', '12:00:00', 'IM', 'CL 3', '2025-05-03 16:07:48', '2025-05-03 16:07:48'),
(222, 62, 3, '10:00:00', '12:00:00', 'SADD', 'CL 3', '2025-05-03 16:07:48', '2025-05-03 16:07:48'),
(223, 62, 5, '13:00:00', '15:00:00', 'DSA', 'CL 4', '2025-05-03 16:07:48', '2025-05-03 16:07:48'),
(224, 62, 6, '17:30:00', '19:30:00', 'PATHFIT', 'GYM 5', '2025-05-03 16:07:48', '2025-05-03 16:07:48'),
(534, 60, 1, '07:00:00', '09:00:00', 'STS', 'ECB 202', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(535, 60, 1, '10:00:00', '12:00:00', 'MMW', 'CL 4', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(536, 60, 2, '10:30:00', '12:00:00', 'IM', 'CL 6', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(537, 60, 2, '12:00:00', '15:00:00', 'SADD', 'CL 4', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(538, 60, 3, '10:00:00', '12:00:00', 'SADD', 'CL 3', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(539, 60, 4, '09:00:00', '12:00:00', 'DSA', 'CL 3', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(540, 60, 5, '15:00:00', '17:30:00', 'PATHFIT', 'GYM 5', '2025-05-04 03:41:43', '2025-05-04 03:41:43'),
(541, 60, 6, '16:00:00', '19:00:00', 'COMPROG', 'CL 4', '2025-05-04 03:41:43', '2025-05-04 03:41:43');

-- --------------------------------------------------------

--
-- Table structure for table `schedule_days`
--

CREATE TABLE `schedule_days` (
  `id` int(11) NOT NULL,
  `day_name` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule_days`
--

INSERT INTO `schedule_days` (`id`, `day_name`) VALUES
(1, 'Monday'),
(2, 'Tuesday'),
(3, 'Wednesday'),
(4, 'Thursday'),
(5, 'Friday'),
(6, 'Saturday');

-- --------------------------------------------------------

--
-- Table structure for table `security_policies`
--

CREATE TABLE `security_policies` (
  `id` int(11) NOT NULL,
  `policy_name` varchar(100) NOT NULL,
  `policy_value` varchar(255) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_policies`
--

INSERT INTO `security_policies` (`id`, `policy_name`, `policy_value`, `description`) VALUES
(1, 'max_login_attempts', '5', 'Maximum allowed failed login attempts before lockout'),
(2, 'account_lock_duration', '30', 'Lockout duration in minutes');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `timezone` varchar(50) NOT NULL DEFAULT 'Asia/Manila',
  `am_cutoff` time DEFAULT '12:00:00',
  `pm_cutoff` time DEFAULT '17:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `timezone`, `am_cutoff`, `pm_cutoff`) VALUES
(1, 'Asia/Manila', '12:00:00', '17:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `professor_id` (`professor_id`),
  ADD KEY `schedule_id` (`schedule_id`),
  ADD KEY `idx_professor_date` (`professor_id`,`date`),
  ADD KEY `idx_attendance_professor_date` (`professor_id`,`checkin_date`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user`,`is_read`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type_created` (`type`,`created_at`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `professors`
--
ALTER TABLE `professors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_professors_approved_by` (`approved_by`);

--
-- Indexes for table `professor_schedules`
--
ALTER TABLE `professor_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `professor_id` (`professor_id`),
  ADD KEY `day_id` (`day_id`),
  ADD KEY `idx_professor_day` (`professor_id`,`day_id`),
  ADD KEY `idx_schedules_professor_day` (`professor_id`,`day_id`);

--
-- Indexes for table `schedule_days`
--
ALTER TABLE `schedule_days`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `security_policies`
--
ALTER TABLE `security_policies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `policy_name` (`policy_name`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `admin_logs`
--
ALTER TABLE `admin_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=130;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=645;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=198;

--
-- AUTO_INCREMENT for table `professors`
--
ALTER TABLE `professors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `professor_schedules`
--
ALTER TABLE `professor_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=542;

--
-- AUTO_INCREMENT for table `schedule_days`
--
ALTER TABLE `schedule_days`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `security_policies`
--
ALTER TABLE `security_policies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `professor_schedules` (`id`);

--
-- Constraints for table `professors`
--
ALTER TABLE `professors`
  ADD CONSTRAINT `fk_professors_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `professor_schedules`
--
ALTER TABLE `professor_schedules`
  ADD CONSTRAINT `professor_schedules_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `professors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `professor_schedules_ibfk_2` FOREIGN KEY (`day_id`) REFERENCES `schedule_days` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
