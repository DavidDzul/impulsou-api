-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3306
-- Tiempo de generación: 15-01-2025 a las 19:32:46
-- Versión del servidor: 8.0.31
-- Versión de PHP: 8.2.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `impulsou_api`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `academic_information`
--

DROP TABLE IF EXISTS `academic_information`;
CREATE TABLE IF NOT EXISTS `academic_information` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `postgraduate_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `institute_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `postgraduate_start_date` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `postgraduate_end_date` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `highlight` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `academic_information_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `business_data`
--

DROP TABLE IF EXISTS `business_data`;
CREATE TABLE IF NOT EXISTS `business_data` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `bs_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_director` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_rfc` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_country` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_state` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_locality` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_adrress` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_telphone` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_line` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_other_line` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bs_description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `bs_website` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `business_data_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `business_visualizations`
--

DROP TABLE IF EXISTS `business_visualizations`;
CREATE TABLE IF NOT EXISTS `business_visualizations` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `cv_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `business_visualizations_user_id_foreign` (`user_id`),
  KEY `business_visualizations_cv_id_foreign` (`cv_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `continuing_education`
--

DROP TABLE IF EXISTS `continuing_education`;
CREATE TABLE IF NOT EXISTS `continuing_education` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `course_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_institute` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_start_date` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_end_date` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `continuing_education_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `curriculum`
--

DROP TABLE IF EXISTS `curriculum`;
CREATE TABLE IF NOT EXISTS `curriculum` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `day_birth` int NOT NULL,
  `month_birth` int NOT NULL,
  `year_birth` int NOT NULL,
  `phone_num` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locality` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `linkedin` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `professional_title` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `professional_summary` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_1` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_2` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_3` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_4` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_5` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `curriculum_email_unique` (`email`),
  KEY `curriculum_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `generations`
--

DROP TABLE IF EXISTS `generations`;
CREATE TABLE IF NOT EXISTS `generations` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `generation_name` int NOT NULL,
  `campus` enum('MERIDA','VALLADOLID','OXKUTZCAB','TIZIMIN') COLLATE utf8mb4_unicode_ci NOT NULL,
  `generation_active` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `images`
--

DROP TABLE IF EXISTS `images`;
CREATE TABLE IF NOT EXISTS `images` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `images_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `job_applications`
--

DROP TABLE IF EXISTS `job_applications`;
CREATE TABLE IF NOT EXISTS `job_applications` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `business_id` bigint UNSIGNED NOT NULL,
  `vacant_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_applications_user_id_foreign` (`user_id`),
  KEY `job_applications_business_id_foreign` (`business_id`),
  KEY `job_applications_vacant_id_foreign` (`vacant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `migrations`
--

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=276 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(259, '2014_10_12_000000_create_generations_table', 1),
(260, '2014_10_12_000001_create_users_table', 1),
(261, '2014_10_12_100000_create_password_resets_table', 1),
(262, '2019_08_19_000000_create_failed_jobs_table', 1),
(263, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(264, '2023_05_20_102314_create_images_table', 1),
(265, '2024_11_15_172149_create_permission_tables', 1),
(266, '2024_11_18_153435_create_curriculum_table', 1),
(267, '2024_11_20_155143_create_work_experience_table', 1),
(268, '2024_11_22_102548_create_academic_information', 1),
(269, '2024_11_22_104052_create_continuing_education', 1),
(270, '2024_11_22_111651_create_technical_knowledge', 1),
(271, '2024_11_29_191339_create_business_data_table', 1),
(272, '2024_12_02_093535_create_vacant_position_table', 1),
(273, '2025_01_05_170900_add_plan_attributes_to_roles_table', 1),
(274, '2025_01_07_141254_create_business_visualizations_table', 1),
(275, '2025_01_09_202656_create_job_applications_table', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
CREATE TABLE IF NOT EXISTS `model_has_permissions` (
  `permission_id` bigint UNSIGNED NOT NULL,
  `model_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint UNSIGNED NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
CREATE TABLE IF NOT EXISTS `model_has_roles` (
  `role_id` bigint UNSIGNED NOT NULL,
  `model_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `model_has_roles`
--

INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES
(5, 'App\\Models\\User', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'BUSINESS_MENU', 'web', '2025-01-15 19:30:05', '2025-01-15 19:30:05');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `num_visualizations` int NOT NULL DEFAULT '0',
  `num_vacancies` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `name`, `guard_name`, `created_at`, `updated_at`, `num_visualizations`, `num_vacancies`) VALUES
(1, 'BRONZE', 'web', '2025-01-15 19:30:05', '2025-01-15 19:30:05', 0, 0),
(2, 'SILVER', 'web', '2025-01-15 19:30:05', '2025-01-15 19:30:05', 0, 0),
(3, 'GOLD', 'web', '2025-01-15 19:30:05', '2025-01-15 19:30:05', 0, 0),
(4, 'PLATINUM', 'web', '2025-01-15 19:30:05', '2025-01-15 19:30:05', 0, 0),
(5, 'DIAMOND', 'web', '2025-01-15 19:30:05', '2025-01-15 19:30:05', 0, 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
CREATE TABLE IF NOT EXISTS `role_has_permissions` (
  `permission_id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `role_has_permissions`
--

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `technical_knowledge`
--

DROP TABLE IF EXISTS `technical_knowledge`;
CREATE TABLE IF NOT EXISTS `technical_knowledge` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `type` enum('SOFTWARE','LANGUAGE','OTHER') COLLATE utf8mb4_unicode_ci NOT NULL,
  `other_knowledge` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_knowledge` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` enum('BEGINNER','INTERMEDIATE','ADVANCED') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `technical_knowledge_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `enrollment` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workstation` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_type` enum('ADMIN','BEC_ACTIVE','BEC_INACTIVE','BUSINESS') COLLATE utf8mb4_unicode_ci NOT NULL,
  `campus` enum('MERIDA','VALLADOLID','OXKUTZCAB','TIZIMIN') COLLATE utf8mb4_unicode_ci NOT NULL,
  `generation_id` bigint UNSIGNED DEFAULT NULL,
  `active` tinyint(1) NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_generation_id_foreign` (`generation_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `enrollment`, `first_name`, `last_name`, `email`, `email_verified_at`, `password`, `phone`, `workstation`, `user_type`, `campus`, `generation_id`, `active`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Impulso', 'Universitario A.C.', 'vinculacion@iu.org.mx', NULL, '$2y$10$QX2Ko6kXyKNAHA4dqzb7kuRrMS4Vo2V1QPp1ufR6GxnmGDaizIP2a', '+529911071509', NULL, 'BUSINESS', 'MERIDA', NULL, 1, NULL, '2025-01-15 19:30:06', '2025-01-15 19:30:06'),
(2, 'MER170209', 'David', 'Fernando', 'david@iu.org.mx', NULL, '$2y$10$aCiSdxOISWOq/EbY1.Zg7.yJRBNffcQiE1yL7HER1Vs1QBf.hid1e', '+529911071509', NULL, 'BEC_ACTIVE', 'MERIDA', NULL, 1, NULL, '2025-01-15 19:30:06', '2025-01-15 19:30:06');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vacant_position`
--

DROP TABLE IF EXISTS `vacant_position`;
CREATE TABLE IF NOT EXISTS `vacant_position` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `vacant_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('JOB_POSITION','PROFESSIONAL_PRACTICE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `activities` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `study_profile` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `financial_support` tinyint(1) DEFAULT '0',
  `net_salary` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `support_amount` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_day` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `end_day` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_hour` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_minute` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `end_hour` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `end_minute` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `saturday_hour` tinyint(1) DEFAULT '0',
  `saturday_start_hour` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saturday_start_minute` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saturday_end_hour` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saturday_end_minute` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `additional_time_info` text COLLATE utf8mb4_unicode_ci,
  `experience` tinyint(1) DEFAULT '0',
  `experience_description` text COLLATE utf8mb4_unicode_ci,
  `software_use` tinyint(1) DEFAULT '0',
  `software_description` text COLLATE utf8mb4_unicode_ci,
  `skills` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `observations` text COLLATE utf8mb4_unicode_ci,
  `semester` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `general_knowledge` tinyint(1) DEFAULT '0',
  `knowledge_description` text COLLATE utf8mb4_unicode_ci,
  `employment_contract` tinyint(1) DEFAULT '0',
  `vacation` tinyint(1) DEFAULT '0',
  `christmas_bonus` tinyint(1) DEFAULT '0',
  `social_security` tinyint(1) DEFAULT '0',
  `vacation_bonus` tinyint(1) DEFAULT '0',
  `grocery_vouchers` tinyint(1) DEFAULT '0',
  `savings_fund` tinyint(1) DEFAULT '0',
  `life_insurance` tinyint(1) DEFAULT '0',
  `medical_expenses` tinyint(1) DEFAULT '0',
  `day_off` tinyint(1) DEFAULT '0',
  `sunday_bonus` tinyint(1) DEFAULT '0',
  `paternity_leave` tinyint(1) DEFAULT '0',
  `transportation_help` tinyint(1) DEFAULT '0',
  `productivity_bonus` tinyint(1) DEFAULT '0',
  `automobile` tinyint(1) DEFAULT '0',
  `dining_room` tinyint(1) DEFAULT '0',
  `loans` tinyint(1) DEFAULT '0',
  `other` tinyint(1) DEFAULT '0',
  `benefit_description` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `candidate_type` enum('INTERNAL','EXTERNAL','NOT_COVERED') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `campus` enum('MERIDA','VALLADOLID','OXKUTZCAB','TIZIMIN') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vacant_position_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `work_experience`
--

DROP TABLE IF EXISTS `work_experience`;
CREATE TABLE IF NOT EXISTS `work_experience` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `job_position` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `end_date` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsibility` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `achievement` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_experience_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `academic_information`
--
ALTER TABLE `academic_information`
  ADD CONSTRAINT `academic_information_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `business_data`
--
ALTER TABLE `business_data`
  ADD CONSTRAINT `business_data_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `business_visualizations`
--
ALTER TABLE `business_visualizations`
  ADD CONSTRAINT `business_visualizations_cv_id_foreign` FOREIGN KEY (`cv_id`) REFERENCES `curriculum` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `business_visualizations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `continuing_education`
--
ALTER TABLE `continuing_education`
  ADD CONSTRAINT `continuing_education_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `curriculum`
--
ALTER TABLE `curriculum`
  ADD CONSTRAINT `curriculum_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `images`
--
ALTER TABLE `images`
  ADD CONSTRAINT `images_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `job_applications_business_id_foreign` FOREIGN KEY (`business_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `job_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `job_applications_vacant_id_foreign` FOREIGN KEY (`vacant_id`) REFERENCES `vacant_position` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `technical_knowledge`
--
ALTER TABLE `technical_knowledge`
  ADD CONSTRAINT `technical_knowledge_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_generation_id_foreign` FOREIGN KEY (`generation_id`) REFERENCES `generations` (`id`);

--
-- Filtros para la tabla `vacant_position`
--
ALTER TABLE `vacant_position`
  ADD CONSTRAINT `vacant_position_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Filtros para la tabla `work_experience`
--
ALTER TABLE `work_experience`
  ADD CONSTRAINT `work_experience_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
