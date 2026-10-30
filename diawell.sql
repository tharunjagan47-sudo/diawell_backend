-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 27, 2026
-- Server version: 10.4.24-MariaDB
-- PHP Version: 8.1.6
-- DiaWell Complete Database Schema
-- Includes Authentication, Profile, Glucose, Diet, Medications, Activities, Notifications, AI Chat, and Settings

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `diawell`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

-- 1. Table structure for table `users`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `diabetes_type` varchar(30) DEFAULT NULL,
  `age` tinyint(3) UNSIGNED DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `current_glucose_mg_dl` decimal(5,2) DEFAULT NULL,
  `target_glucose_mg_dl` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping sample data for table `users`
--
-- --------------------------------------------------------
-- 2. Table structure for table `health_preferences`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `health_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `health_focus` varchar(100) DEFAULT NULL,
  `dietary_preferences` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `activity_level` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_health_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `created_at`, `diabetes_type`, `age`, `height_cm`, `weight_kg`, `profile_photo`, `current_glucose_mg_dl`, `target_glucose_mg_dl`) VALUES
(8, 'Tharun kumar', 'tharun@example.com', '$2y$10$8Qk1sk.J1VdeiKX7.t3fJ.fJI31dC/8JuEl2g6ohD3GpcPqvQcnji', '2026-10-27 04:20:50', 'Type 1', 25, '172.00', '68.00', 'upload/profile_photos/0024d92160efd6452076b9dc72656805.png', '145.00', '120.00')
ON DUPLICATE KEY UPDATE `id`=`id`;
-- --------------------------------------------------------
-- 3. Table structure for table `food_preferences`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `food_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `preferred_diet` varchar(50) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `preferred_styles` text DEFAULT NULL,
  `foods_to_avoid` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_food_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 4. Table structure for table `glucose_readings`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `glucose_readings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `glucose_value` decimal(5,2) NOT NULL,
  `reading_type` varchar(50) NOT NULL,
  `reading_date` date NOT NULL,
  `reading_time` time NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_glucose_user_date` (`user_id`, `reading_date`),
  CONSTRAINT `fk_glucose_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `health_preferences`
-- (Corresponds to HealthPreferencesScreen.kt - Step 2 of Onboarding)
--
-- --------------------------------------------------------
-- 5. Table structure for table `food_items` (Food Catalog)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `food_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL,
  `diet_type` varchar(50) NOT NULL,
  `portion` varchar(100) NOT NULL,
  `calories` int(11) NOT NULL,
  `carbs_g` decimal(5,1) NOT NULL,
  `protein_g` decimal(5,1) NOT NULL,
  `fat_g` decimal(5,1) NOT NULL,
  `fiber_g` decimal(5,1) DEFAULT 0.0,
  `glycemic_index` varchar(20) DEFAULT 'Low',
  `icon` varchar(20) DEFAULT '🥗',
  `ingredients` text DEFAULT NULL,
  `health_benefits` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_food_cat_diet` (`category`, `diet_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `health_preferences` (
-- --------------------------------------------------------
-- 6. Table structure for table `food_logs` (User Logged Meals)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `food_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `health_focus` varchar(100) DEFAULT NULL COMMENT 'Glucose Control, Weight Management, Hba1c Reduction, Active Energy & Fitness',
  `dietary_preferences` text DEFAULT NULL COMMENT 'JSON array of selected diets e.g. ["Non-Vegetarian", "Low-Carb / Keto"]',
  `allergies` text DEFAULT NULL COMMENT 'JSON array of allergies e.g. ["Nuts", "Lactose / Dairy"]',
  `activity_level` varchar(100) DEFAULT NULL COMMENT 'Sedentary, Light Exercise, Moderate, Active',
  `meal_category` varchar(50) NOT NULL,
  `food_name` varchar(150) NOT NULL,
  `portion` varchar(100) NOT NULL,
  `calories` int(11) NOT NULL,
  `carbs_g` decimal(5,1) DEFAULT 0.0,
  `protein_g` decimal(5,1) DEFAULT 0.0,
  `fat_g` decimal(5,1) DEFAULT 0.0,
  `log_date` date NOT NULL,
  `log_time` time NOT NULL,
  `icon` varchar(20) DEFAULT '🥣',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_food_log_user_date` (`user_id`, `log_date`),
  CONSTRAINT `fk_food_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 7. Table structure for table `medications` (User Prescriptions & Reminders)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `medications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `dose` varchar(100) NOT NULL,
  `reminder_time` time NOT NULL,
  `reminder_date` date NOT NULL,
  `timing` varchar(50) DEFAULT 'After Meal',
  `frequency` varchar(50) DEFAULT 'Daily',
  `status` varchar(20) DEFAULT 'Upcoming',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_health_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  KEY `idx_meds_user_date` (`user_id`, `reminder_date`),
  CONSTRAINT `fk_meds_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 8. Table structure for table `activity_logs` (User Workouts & Exercises)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `icon` varchar(20) DEFAULT '🚶‍♂️',
  `duration_mins` int(11) NOT NULL,
  `distance_km` decimal(5,2) DEFAULT 0.00,
  `calories_kcal` int(11) NOT NULL,
  `glucose_before` decimal(5,2) DEFAULT NULL,
  `glucose_after` decimal(5,2) DEFAULT NULL,
  `activity_date` date NOT NULL,
  `activity_time` time NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_user_date` (`user_id`, `activity_date`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `food_preferences`
-- (Corresponds to FoodPreferencesSettingsScreen.kt - Food Preferences Settings)
--
-- --------------------------------------------------------
-- 9. Table structure for table `notifications`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `notification_type` varchar(50) DEFAULT 'general',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `food_preferences` (
-- --------------------------------------------------------
-- 10. Table structure for table `ai_chat_messages`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `preferred_diet` varchar(50) DEFAULT NULL COMMENT 'Vegetarian, Non-Vegetarian, Vegan',
  `allergies` text DEFAULT NULL COMMENT 'JSON array of food allergies e.g. ["Nuts", "Milk"]',
  `preferred_styles` text DEFAULT NULL COMMENT 'JSON array of preferred styles e.g. ["Whole grains", "Vegetables"]',
  `foods_to_avoid` text DEFAULT NULL COMMENT 'Optional free-text note for foods to avoid',
  `sender` varchar(10) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_user` (`user_id`),
  CONSTRAINT `fk_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 11. Table structure for table `notification_preferences`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `medication_reminders` tinyint(1) DEFAULT 1,
  `meal_logging_reminders` tinyint(1) DEFAULT 1,
  `glucose_checkins` tinyint(1) DEFAULT 1,
  `weekly_report_alerts` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_food_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  CONSTRAINT `fk_notif_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Seed Data for Food Catalog
-- --------------------------------------------------------
INSERT INTO `food_items` (`name`, `category`, `diet_type`, `portion`, `calories`, `carbs_g`, `protein_g`, `fat_g`, `fiber_g`, `glycemic_index`, `icon`, `ingredients`, `health_benefits`) VALUES
('Berry & almond oats', 'Breakfast', 'Vegetarian', '1 bowl • 290 g', 320, 42.0, 12.0, 9.0, 7.0, 'Low', '🥣', 'Rolled oats, unsweetened almond milk, blueberries, crushed almonds, chia seeds', 'High soluble fiber helps slow glucose absorption and sustains energy.'),
('Grilled chicken bowl', 'Lunch', 'Non-Vegetarian', '1 bowl • 350 g', 450, 38.0, 42.0, 12.0, 6.0, 'Low', '🥗', 'Skinless chicken breast, quinoa, steamed broccoli, bell peppers, olive oil drizzle', 'Lean protein minimizes post-prandial glycemic spikes and preserves lean muscle.'),
('Greek yogurt & berries', 'Snacks', 'Vegetarian', '1 cup • 170 g', 160, 18.0, 15.0, 3.0, 4.0, 'Low', '🫐', 'Plain unsweetened Greek yogurt, fresh raspberries, flaxseed powder', 'Probiotics support gut metabolic health while keeping carbohydrates low.'),
('Salmon & brown rice', 'Dinner', 'Non-Vegetarian', '1 plate • 380 g', 480, 46.0, 34.0, 16.0, 5.0, 'Low', '🍱', 'Baked Atlantic salmon, brown basmati rice, sautéed spinach, lemon slice', 'Rich in Omega-3 fatty acids reducing systemic inflammation and supporting insulin sensitivity.'),
('Moong Dal Khichdi', 'Lunch', 'Vegetarian', '1 bowl • 250 g', 280, 44.0, 14.0, 4.5, 8.0, 'Low', '🍲', 'Yellow moong dal, brown rice, turmeric, cumin seeds, ginger, mild ghee', 'Gentle on digestion, balanced complex carbs and plant-based protein.'),
('Paneer Tikka Salad', 'Snacks', 'Vegetarian', '1 plate • 200 g', 230, 12.0, 18.0, 14.0, 4.5, 'Low', '🧀', 'Low-fat paneer cubes, capsicum, onions, mint curd dressing, chaat masala', 'High satiety index with minimal carbohydrate impact on blood sugar.'),
('Methi Paratha with Curd', 'Breakfast', 'Vegetarian', '2 parathas • 180 g', 310, 39.0, 10.0, 11.0, 6.0, 'Medium', '🫓', 'Whole wheat flour, fresh fenugreek (methi) leaves, ajwain, plain curd', 'Fenugreek contains 4-hydroxyisoleucine which stimulates glucose-dependent insulin release.'),
('Egg White Veggie Omelette', 'Breakfast', 'Non-Vegetarian', '1 plate • 220 g', 210, 8.0, 26.0, 7.0, 3.5, 'Low', '🍳', 'Egg whites, spinach, diced tomatoes, mushrooms, pinch of black pepper', 'Virtually zero glycemic load, ideal for breakfast fasting glucose control.'),
('Quinoa Veggie Bowl', 'Bowls', 'Vegan', '1 bowl • 300 g', 340, 48.0, 13.0, 8.0, 7.5, 'Low', '🥗', 'Tri-color quinoa, roasted zucchini, chickpeas, lemon tahini dressing', '100% plant-based complete protein with low glycemic index complex carbs.'),
('Sprouted Green Gram Chaat', 'Snacks', 'Vegan', '1 cup • 150 g', 140, 22.0, 9.0, 1.5, 6.0, 'Low', '🌱', 'Sprouted moong beans, cucumber, tomato, lemon juice, pink salt', 'Enzyme-rich raw sprouts offer high bioavailable fiber and slow digestion.')
ON DUPLICATE KEY UPDATE `name`=`name`;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;