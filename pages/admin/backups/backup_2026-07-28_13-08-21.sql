-- Database Backup
-- Generated: 2026-07-28 13:08:21
-- Database: dental_clinic

-- Table: appointments
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `services` text NOT NULL,
  `appointment_date` date DEFAULT NULL,
  `appointment_time` time DEFAULT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `age` int DEFAULT NULL,
  `occupation` varchar(120) DEFAULT NULL,
  `region` varchar(120) DEFAULT NULL,
  `province` varchar(120) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `barangay` varchar(120) DEFAULT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `notes` text,
  `internal_notes` text,
  `terms_accepted` tinyint(1) DEFAULT '1',
  `privacy_accepted` tinyint(1) DEFAULT '1',
  `is_verified` tinyint(1) DEFAULT '0',
  `status` enum('pending','approved','confirmed','in_progress','completed','cancelled','rejected') DEFAULT 'pending',
  `dentist_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `tracking_token` varchar(255) DEFAULT NULL,
  `is_rescheduled` tinyint(1) NOT NULL DEFAULT '0',
  `previous_date` date DEFAULT NULL,
  `previous_time` time DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phone_email` (`phone`,`email`),
  KEY `idx_status` (`status`),
  KEY `idx_tracking_token` (`tracking_token`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table appointments
INSERT INTO `appointments` (`id`, `phone`, `email`, `services`, `appointment_date`, `appointment_time`, `full_name`, `age`, `occupation`, `region`, `province`, `city`, `barangay`, `street_address`, `notes`, `internal_notes`, `terms_accepted`, `privacy_accepted`, `is_verified`, `status`, `dentist_id`, `created_at`, `updated_at`, `tracking_token`, `is_rescheduled`, `previous_date`, `previous_time`) VALUES ('16', '+639388631221', 'pulmonesmarjsorie8@gmail.com', '[\"1\",\"2\",\"3\"]', '2026-07-28', '10:30:00', 'jhonny santiago', '25', 'ghg', 'Western Visayas', 'Capiz', 'President Roxas', 'Poblacion', 'san josef', 'test', NULL, '1', '1', '1', 'confirmed', '1', '2026-07-23 23:34:37', '2026-07-28 12:27:36', 'MTZ8MTc4NDgyMDg3N3w3MTI3', '0', NULL, NULL);
INSERT INTO `appointments` (`id`, `phone`, `email`, `services`, `appointment_date`, `appointment_time`, `full_name`, `age`, `occupation`, `region`, `province`, `city`, `barangay`, `street_address`, `notes`, `internal_notes`, `terms_accepted`, `privacy_accepted`, `is_verified`, `status`, `dentist_id`, `created_at`, `updated_at`, `tracking_token`, `is_rescheduled`, `previous_date`, `previous_time`) VALUES ('17', '+639388631221', 'pulmonesmarjsorie8@gmail.com', '[2]', '2026-07-28', '14:00:00', 'uhuy', '0', '', NULL, NULL, NULL, NULL, '', '', NULL, '1', '1', '1', 'pending', NULL, '2026-07-25 13:23:16', '2026-07-28 12:27:40', 'MTd8MTc4NDk1Njk5Nnw3OTM3', '1', '2026-07-29', '14:00:00');
INSERT INTO `appointments` (`id`, `phone`, `email`, `services`, `appointment_date`, `appointment_time`, `full_name`, `age`, `occupation`, `region`, `province`, `city`, `barangay`, `street_address`, `notes`, `internal_notes`, `terms_accepted`, `privacy_accepted`, `is_verified`, `status`, `dentist_id`, `created_at`, `updated_at`, `tracking_token`, `is_rescheduled`, `previous_date`, `previous_time`) VALUES ('18', '+639388631221', 'pulmonesmarjsorie8@gmail.com', '[1]', '2026-07-28', '09:30:00', 'uhuy', '0', '', NULL, NULL, NULL, NULL, '', '', NULL, '1', '1', '1', 'pending', NULL, '2026-07-27 09:22:19', '2026-07-28 12:27:43', 'MTh8MTc4NTExNTMzOXw1ODc4', '0', NULL, NULL);

-- Table: captcha_codes
DROP TABLE IF EXISTS `captcha_codes`;
CREATE TABLE `captcha_codes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `captcha_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT ((now() + interval 5 minute)),
  `is_used` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_used` (`is_used`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table captcha_codes
INSERT INTO `captcha_codes` (`id`, `captcha_code`, `created_at`, `expires_at`, `is_used`) VALUES ('1', '116294', '2026-07-25 14:54:52', '2026-07-25 14:59:52', '0');

-- Table: chat_conversations
DROP TABLE IF EXISTS `chat_conversations`;
CREATE TABLE `chat_conversations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `status` enum('open','staff_assigned','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `assigned_to` int DEFAULT NULL,
  `assigned_role` enum('staff','admin') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_message_at` datetime DEFAULT NULL,
  `patient_last_read_at` datetime DEFAULT NULL,
  `staff_last_read_at` datetime DEFAULT NULL,
  `patient_typing_at` datetime DEFAULT NULL,
  `staff_typing_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table chat_conversations
INSERT INTO `chat_conversations` (`id`, `patient_id`, `status`, `assigned_to`, `assigned_role`, `last_message_at`, `patient_last_read_at`, `staff_last_read_at`, `patient_typing_at`, `staff_typing_at`, `created_at`) VALUES ('1', '27', 'staff_assigned', '22', 'staff', '2026-07-25 12:50:45', '2026-07-28 12:15:17', '2026-07-25 12:53:21', NULL, NULL, '2026-07-24 16:08:25');
INSERT INTO `chat_conversations` (`id`, `patient_id`, `status`, `assigned_to`, `assigned_role`, `last_message_at`, `patient_last_read_at`, `staff_last_read_at`, `patient_typing_at`, `staff_typing_at`, `created_at`) VALUES ('3', '21', 'staff_assigned', '1', 'staff', '2026-07-27 15:48:13', '2026-07-25 12:51:45', '2026-07-27 16:37:12', NULL, NULL, '2026-07-25 11:31:58');

-- Table: chat_logs
DROP TABLE IF EXISTS `chat_logs`;
CREATE TABLE `chat_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `guest_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'hybrid',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_mode` (`mode`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table chat_logs
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('1', 'guest_6a60d36e41f52_1784730478', 'user', 'hey', 'hybrid', NULL, NULL, '2026-07-22 22:27:58');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('2', 'guest_6a60d36e41f52_1784730478', 'bot', 'Hello! 👋 Welcome to Dr. Brendalyn Wansi Calacat\n\nI\'m your dental assistant. I can help you with:\n• 📋 **Services** - What we offer\n• 💰 **Prices** - Treatment costs\n• 🏥 **Hours** - When we\'re open\n• 📍 **Location** - Where to find us\n• 📞 **Contact** - How to reach us\n• 📅 **Booking** - Schedule an appointment\n\nWhat would you like to know? 😊', 'hybrid', NULL, NULL, '2026-07-22 22:27:58');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('3', 'guest_6a60d36e41f52_1784730478', 'user', 'location', 'hybrid', NULL, NULL, '2026-07-22 22:28:14');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('4', 'guest_6a60d36e41f52_1784730478', 'bot', '📍 **Clinic Location**\n\nWe\'re located at:\n🏢 haha\n\n📞 09388631221asdasd\n\n🕐 Operating hours: Ask me about our hours!\n\nNeed directions? Feel free to ask! 🗺️', 'hybrid', NULL, NULL, '2026-07-22 22:28:14');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('5', 'guest_6a60d36e41f52_1784730478', 'user', 'booking?', 'hybrid', NULL, NULL, '2026-07-22 22:28:23');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('6', 'guest_6a60d36e41f52_1784730478', 'bot', '📅 **Book an Appointment**\n\nTo book an appointment with us:\n1️⃣ Click the **\'Book Appointment\'** button on our website\n2️⃣ Fill in your details and select your preferred service\n3️⃣ Choose your preferred date and time\n4️⃣ Verify your email/phone with OTP\n5️⃣ You\'ll receive a confirmation once approved\n\nOr you can call us directly at 09388631221asdasd\nWe\'re here to help! 😊', 'hybrid', NULL, NULL, '2026-07-22 22:28:23');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('7', 'guest_6a60d36e41f52_1784730478', 'user', 'prices?', 'hybrid', NULL, NULL, '2026-07-22 22:32:05');
INSERT INTO `chat_logs` (`id`, `guest_id`, `sender`, `message`, `mode`, `ip_address`, `user_agent`, `created_at`) VALUES ('8', 'guest_6a60d36e41f52_1784730478', 'bot', '💰 **Our Service Prices:**\n\n• Dental Cleaning: **₱700.00**\n• Dental Implants: **₱50,000.00**\n• Dentures, Crowns and Fix Bridges: **₱5,000.00**\n\nPrices are subject to change. Please confirm when booking.\nClick \'Book Appointment\' to schedule! 📅', 'hybrid', NULL, NULL, '2026-07-22 22:32:05');

-- Table: chat_messages
DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `guest_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender` enum('guest','staff','admin','bot') COLLATE utf8mb4_unicode_ci DEFAULT 'guest',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'hybrid',
  `is_read` tinyint(1) DEFAULT '0',
  `is_replied` tinyint(1) DEFAULT '0',
  `staff_reply_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_read` (`is_read`),
  KEY `idx_created` (`created_at`),
  KEY `idx_replied` (`is_replied`)
) ENGINE=MyISAM AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table chat_messages
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('1', 'guest_6a60d36e41f52_1784730478', 'guest', 'hey', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 22:36:19');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('2', 'guest_6a60d36e41f52_1784730478', 'bot', 'Hello! 👋 Welcome to Dr. Brendalyn Wansi Calacat\n\nI\'m your dental assistant. I can help you with:\n• 📋 Services & treatments\n• 💰 Price inquiries\n• 🏥 Operating hours\n• 📍 Location & directions\n• 📞 Contact information\n• 📅 Booking appointments\n\nWhat would you like to know? 😊', 'hybrid', '0', '0', NULL, NULL, NULL, '2026-07-22 22:36:19');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('3', 'guest_6a60d36e41f52_1784730478', 'guest', 'contact', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 22:36:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('4', 'guest_6a60d36e41f52_1784730478', 'bot', '📞 **Contact Us**\n\n• 📞 Phone: 09388631221asdasd\n• ✉️ Email: dr.brendalyn@gmail.comss\n• 📍 Location: haha\n• 🏥 Hours: 08:00 AM – 05:00 PM\n\nWe\'re always happy to hear from you! 😊', 'hybrid', '0', '0', NULL, NULL, NULL, '2026-07-22 22:36:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('5', 'guest_6a60d36e41f52_1784730478', 'guest', 'asd', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 22:36:27');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('6', 'guest_6a60d36e41f52_1784730478', 'bot', '🤔 I\'m not quite sure about that.\n\nI can help you with:\n• 📋 Services - \'What services do you offer?\'\n• 💰 Prices - \'How much does cleaning cost?\'\n• 🏥 Hours - \'What are your operating hours?\'\n• 📍 Location - \'Where is the clinic located?\'\n• 📞 Contact - \'How can I reach you?\'\n• 📅 Booking - \'How do I book an appointment?\'\n\nCould you please rephrase your question? 😊', 'hybrid', '0', '0', NULL, NULL, NULL, '2026-07-22 22:36:27');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('7', 'guest_6a60d36e41f52_1784730478', 'guest', 'asdasd', 'human', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 22:39:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('8', 'guest_6a60d36e41f52_1784730478', 'bot', '🤔 I\'m not quite sure about that.\n\nI can help you with:\n• 📋 Services - \'What services do you offer?\'\n• 💰 Prices - \'How much does cleaning cost?\'\n• 🏥 Hours - \'What are your operating hours?\'\n• 📍 Location - \'Where is the clinic located?\'\n• 📞 Contact - \'How can I reach you?\'\n• 📅 Booking - \'How do I book an appointment?\'\n\nCould you please rephrase your question? 😊', 'human', '0', '0', NULL, NULL, NULL, '2026-07-22 22:39:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('9', 'guest_6a60d36e41f52_1784730478', 'guest', 'asd', 'human', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 22:39:23');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('10', 'guest_6a60d36e41f52_1784730478', 'bot', '🤔 I\'m not quite sure about that.\n\nI can help you with:\n• 📋 Services - \'What services do you offer?\'\n• 💰 Prices - \'How much does cleaning cost?\'\n• 🏥 Hours - \'What are your operating hours?\'\n• 📍 Location - \'Where is the clinic located?\'\n• 📞 Contact - \'How can I reach you?\'\n• 📅 Booking - \'How do I book an appointment?\'\n\nCould you please rephrase your question? 😊', 'human', '0', '0', NULL, NULL, NULL, '2026-07-22 22:39:23');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('11', 'guest_6a60d36e41f52_1784730478', 'guest', 'hey', 'human', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 22:44:04');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('12', 'guest_6a60d36e41f52_1784730478', 'bot', 'Hello! 👋 Welcome to Dr. Brendalyn Wansi Calacat\n\nI\'m your dental assistant. I can help you with:\n• 📋 Services & treatments\n• 💰 Price inquiries\n• 🏥 Operating hours\n• 📍 Location & directions\n• 📞 Contact information\n• 📅 Booking appointments\n\nWhat would you like to know? 😊', 'human', '0', '0', NULL, NULL, NULL, '2026-07-22 22:44:04');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('13', 'guest_cd4ccb269774b_1784769984', 'guest', 'What services do you offer?', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:26:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('14', 'guest_cd4ccb269774b_1784769984', 'bot', '🦷 Here\'s what we offer:\n• Dental Cleaning\n• Dental Implants\n• Dentures, Crowns and Fix Bridges\n\nWant pricing or details on any of these? Just ask!', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:26:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('15', 'guest_c0f5e5b45c59c_1784769999', 'guest', 'pricing', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:26:39');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('16', 'guest_c0f5e5b45c59c_1784769999', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:26:39');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('17', 'guest_b909165a1a103_1784770017', 'guest', 'price?', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:26:57');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('18', 'guest_b909165a1a103_1784770017', 'bot', '💰 Here are some of our service prices:\n• Dental Cleaning — ₱700.00\n• Dental Implants — ₱50,000.00\n• Dentures, Crowns and Fix Bridges — ₱5,000.00\n\nAsk me about a specific service for its exact price!', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:26:57');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('19', 'guest_0f235093497ef_1784770026', 'guest', 'what are the price?', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:27:06');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('20', 'guest_0f235093497ef_1784770026', 'bot', '💰 Here are some of our service prices:\n• Dental Cleaning — ₱700.00\n• Dental Implants — ₱50,000.00\n• Dentures, Crowns and Fix Bridges — ₱5,000.00\n\nAsk me about a specific service for its exact price!', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:27:06');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('21', 'guest_a89af46eed054_1784770093', 'guest', 'heyy', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:28:13');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('22', 'guest_a89af46eed054_1784770093', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:28:13');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('23', 'guest_f3036bcc42bd3_1784770180', 'guest', 'hello', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:29:40');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('24', 'guest_f3036bcc42bd3_1784770180', 'bot', 'Hello! 😊 I\'m your virtual dental assistant. Ask me about our services, prices, hours, or location — or type \"book\" to start an appointment.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:29:40');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('25', 'guest_f3036bcc42bd3_1784770180', 'staff', 'hey', 'human', '1', '1', NULL, NULL, NULL, '2026-07-23 09:29:47');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('26', 'guest_ee913994bb10a_1784770199', 'guest', 'heyy', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:29:59');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('27', 'guest_ee913994bb10a_1784770199', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 09:29:59');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('28', 'guest_ee913994bb10a_1784770199', 'staff', 'hi', 'human', '1', '1', NULL, NULL, NULL, '2026-07-23 09:30:04');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('151', 'guest_c8cbb73d70a36_1785138271', 'bot', '🦷 Here\'s what we offer:\n• Dental Cleaning\n• Dental Implants\n• Dentures, Crowns and Fix Bridges\n\nWant pricing or details on any of these? Just ask!', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('150', 'guest_c8cbb73d70a36_1785138271', 'guest', 'What services do you offer?', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('148', 'guest_c8cbb73d70a36_1785138271', 'guest', 'price', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:01');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('149', 'guest_c8cbb73d70a36_1785138271', 'bot', '💰 Here are some of our service prices:\n• Dental Cleaning — ₱700.00\n• Dental Implants — ₱50,000.00\n• Dentures, Crowns and Fix Bridges — ₱5,000.00\n\nAsk me about a specific service for its exact price!', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:01');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('146', 'guest_c8cbb73d70a36_1785138271', 'guest', 'pricing', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:44:51');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('147', 'guest_c8cbb73d70a36_1785138271', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:44:51');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('145', 'guest_c8cbb73d70a36_1785138271', 'staff', 'haha', 'human', '1', '1', NULL, NULL, NULL, '2026-07-27 15:44:40');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('143', 'guest_c8cbb73d70a36_1785138271', 'guest', 'kupal', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:44:31');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('144', 'guest_c8cbb73d70a36_1785138271', 'bot', 'tantadoooooo! 😊 abay gago palamunin ka nga eh — ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:44:31');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('142', 'guest_fd5ca2e6c5d69_1784770686', 'staff', 'hahahha', 'human', '1', '1', NULL, NULL, NULL, '2026-07-25 12:43:23');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('140', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'hahahaha', 'bot', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:29');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('141', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'TAWA PA! 😊 TANGINAMO — ', 'bot', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:29');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('138', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'haha', 'bot', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:18');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('139', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'bot', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:18');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('136', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'haha', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:13');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('137', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:13');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('135', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:09');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('133', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'yannnn! 😊 matutuo ka mag sorry bobo ka —  ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:55');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('134', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'ha\\', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:55:09');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('132', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'pasensya', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:55');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('131', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:41');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('130', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'hahaha', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:41');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('129', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:08');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('128', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'wahahahha', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:08');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('126', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'hahahahha', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('127', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:54:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('124', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'hahahaha', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:58');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('125', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:58');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('120', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'tanga', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:49');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('116', 'guest_fd5ca2e6c5d69_1784770686', 'staff', 'ha', 'human', '1', '1', NULL, NULL, NULL, '2026-07-24 12:52:59');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('117', 'guest_fd5ca2e6c5d69_1784770686', 'staff', 'asdasd', 'human', '1', '1', NULL, NULL, NULL, '2026-07-24 12:53:09');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('118', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'gago', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('119', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'tantadoooooo! 😊 abay gago palamunin ka nga eh — ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('78', 'guest_6a60d36e41f52_1784730478', 'staff', 'why', 'human', '1', '1', NULL, NULL, NULL, '2026-07-23 13:54:24');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('79', 'guest_6a60d36e41f52_1784730478', 'staff', 'why', 'human', '1', '1', NULL, NULL, NULL, '2026-07-23 13:54:38');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('123', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'yannnn! 😊 matutuo ka mag sorry bobo ka —  ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:53');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('122', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'sorry', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:53');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('121', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'tantadoooooo! 😊 abay gago palamunin ka nga eh — ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:53:49');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('114', 'guest_fd5ca2e6c5d69_1784770686', 'guest', 'asd', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:52:51');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('115', 'guest_fd5ca2e6c5d69_1784770686', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-24 12:52:51');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('152', 'guest_c8cbb73d70a36_1785138271', 'guest', 'ahgsfads', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:10');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('153', 'guest_c8cbb73d70a36_1785138271', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:10');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('154', 'guest_c8cbb73d70a36_1785138271', 'guest', 'gago', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:19');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('155', 'guest_c8cbb73d70a36_1785138271', 'bot', 'tantadoooooo! 😊 abay gago palamunin ka nga eh — ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:19');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('156', 'guest_c8cbb73d70a36_1785138271', 'guest', 'sorry', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:23');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('157', 'guest_c8cbb73d70a36_1785138271', 'bot', 'yannnn! 😊 matutuo ka mag sorry bobo ka —  ', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:23');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('158', 'guest_c8cbb73d70a36_1785138271', 'guest', 'location', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:45');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('159', 'guest_c8cbb73d70a36_1785138271', 'bot', '📍 You can find us at **sa ilalim ng tulay KUPAL**.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:45:45');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('160', 'guest_c8cbb73d70a36_1785138271', 'guest', 'asd', 'hybrid', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:47:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('161', 'guest_c8cbb73d70a36_1785138271', 'bot', 'I\'m not fully sure about that one 🤔 — switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.', 'hybrid', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:47:05');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('162', 'guest_c8cbb73d70a36_1785138271', 'guest', 'location', 'bot', '1', '0', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:47:18');
INSERT INTO `chat_messages` (`id`, `guest_id`, `sender`, `message`, `mode`, `is_read`, `is_replied`, `staff_reply_id`, `ip_address`, `user_agent`, `created_at`) VALUES ('163', 'guest_c8cbb73d70a36_1785138271', 'bot', '📍 You can find us at **san josef**.', 'bot', '1', '1', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 15:47:18');

-- Table: chat_presence
DROP TABLE IF EXISTS `chat_presence`;
CREATE TABLE `chat_presence` (
  `user_id` int NOT NULL,
  `role` enum('patient','staff','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_role_seen` (`role`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table chat_presence
INSERT INTO `chat_presence` (`user_id`, `role`, `last_seen_at`) VALUES ('21', 'patient', '2026-07-25 12:51:45');
INSERT INTO `chat_presence` (`user_id`, `role`, `last_seen_at`) VALUES ('27', 'patient', '2026-07-28 12:15:17');
INSERT INTO `chat_presence` (`user_id`, `role`, `last_seen_at`) VALUES ('22', 'staff', '2026-07-25 12:53:41');
INSERT INTO `chat_presence` (`user_id`, `role`, `last_seen_at`) VALUES ('1', 'staff', '2026-07-27 16:37:12');

-- Table: chat_sessions
DROP TABLE IF EXISTS `chat_sessions`;
CREATE TABLE `chat_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `guest_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','resolved','archived') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `assigned_to` int DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`)
) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table chat_sessions
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('1', 'guest_6a60d36e41f52_1784730478', '', '1', '2026-07-23 13:54:38', '2026-07-22 22:36:19');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('2', 'guest_cd4ccb269774b_1784769984', 'active', NULL, '2026-07-23 09:26:24', '2026-07-23 09:26:24');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('3', 'guest_c0f5e5b45c59c_1784769999', 'active', NULL, '2026-07-23 09:26:39', '2026-07-23 09:26:39');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('4', 'guest_b909165a1a103_1784770017', 'active', NULL, '2026-07-23 09:26:57', '2026-07-23 09:26:57');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('5', 'guest_0f235093497ef_1784770026', 'active', NULL, '2026-07-23 09:27:06', '2026-07-23 09:27:06');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('6', 'guest_a89af46eed054_1784770093', 'active', NULL, '2026-07-23 09:28:13', '2026-07-23 09:28:13');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('7', 'guest_f3036bcc42bd3_1784770180', '', NULL, '2026-07-23 09:29:47', '2026-07-23 09:29:40');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('8', 'guest_ee913994bb10a_1784770199', '', NULL, '2026-07-23 09:30:04', '2026-07-23 09:29:59');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('9', 'guest_fd5ca2e6c5d69_1784770686', '', NULL, '2026-07-25 12:43:23', '2026-07-23 09:38:06');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('10', 'guest_d0300d2f23e98_1784771901', 'active', NULL, '2026-07-23 16:45:56', '2026-07-23 09:58:21');
INSERT INTO `chat_sessions` (`id`, `guest_id`, `status`, `assigned_to`, `last_message_at`, `created_at`) VALUES ('11', 'guest_c8cbb73d70a36_1785138271', '', NULL, '2026-07-27 15:47:18', '2026-07-27 15:44:31');

-- Table: chat_thread_messages
DROP TABLE IF EXISTS `chat_thread_messages`;
CREATE TABLE `chat_thread_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversation_id` int NOT NULL,
  `sender_role` enum('patient','staff','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_id` int NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conversation` (`conversation_id`),
  CONSTRAINT `fk_thread_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table chat_thread_messages
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('1', '1', 'patient', '27', 'asdasd', NULL, NULL, '1', '2026-07-24 16:08:49');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('2', '1', 'admin', '1', 'asd', NULL, NULL, '1', '2026-07-24 16:15:25');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('3', '1', 'staff', '22', 'asdasd', NULL, NULL, '1', '2026-07-24 16:15:32');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('4', '1', 'staff', '22', '', 'uploads/chat/8371d9ebc405bf0559baac4a6493937f.png', 'image', '1', '2026-07-24 16:22:23');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('7', '1', 'admin', '1', 'hh', NULL, NULL, '1', '2026-07-25 11:02:50');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('8', '1', 'staff', '22', 'heyy', NULL, NULL, '1', '2026-07-25 11:13:17');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('9', '1', 'patient', '27', 'asdasdasd', NULL, NULL, '1', '2026-07-25 11:16:23');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('10', '3', 'patient', '21', 'heyyy', NULL, NULL, '1', '2026-07-25 11:32:11');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('11', '3', 'staff', '22', 'yes?', NULL, NULL, '1', '2026-07-25 11:32:34');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('12', '3', 'staff', '22', 'asdasd', NULL, NULL, '1', '2026-07-25 11:58:16');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('13', '3', 'staff', '22', 'kupal', NULL, NULL, '1', '2026-07-25 12:00:25');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('14', '1', 'patient', '27', 'nye', NULL, NULL, '1', '2026-07-25 12:12:39');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('15', '1', 'patient', '27', 'asdas', NULL, NULL, '1', '2026-07-25 12:12:54');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('16', '1', 'patient', '27', 'asdasd', NULL, NULL, '1', '2026-07-25 12:33:50');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('17', '1', 'staff', '22', 'asdasdasdad', NULL, NULL, '1', '2026-07-25 12:50:45');
INSERT INTO `chat_thread_messages` (`id`, `conversation_id`, `sender_role`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('18', '3', 'staff', '1', '', 'uploads/chat/dfa61c477afa959424d6fb053a2d6ea6.gif', 'image', '1', '2026-07-27 15:48:13');

-- Table: clinic_settings
DROP TABLE IF EXISTS `clinic_settings`;
CREATE TABLE `clinic_settings` (
  `id` int NOT NULL DEFAULT '1',
  `clinic_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dr. Brendalyn Wansi Calacat',
  `clinic_tagline` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dental Clinic',
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `clinic_hours` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '08:00 AM – 05:00 PM',
  `facebook_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#',
  `instagram_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#',
  `messenger_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#',
  `hero_badge` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DR. BRENDALYN',
  `hero_title_line1` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Your Smile,',
  `hero_title_line2` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Our Priority',
  `hero_subtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Specialized dentistry with personalized care.',
  `hero_description` text COLLATE utf8mb4_unicode_ci,
  `hero_background` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stat1_value` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '10',
  `stat1_label` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dental Services',
  `stat2_value` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Easy',
  `stat2_label` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Online Booking',
  `stat3_value` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Fast',
  `stat3_label` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Confirmation',
  `about_heading` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Built for a better patient experience',
  `about_description` text COLLATE utf8mb4_unicode_ci,
  `cta_heading` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Book your appointment today',
  `cta_description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table clinic_settings
INSERT INTO `clinic_settings` (`id`, `clinic_name`, `clinic_tagline`, `phone`, `email`, `location`, `clinic_hours`, `facebook_url`, `instagram_url`, `messenger_url`, `hero_badge`, `hero_title_line1`, `hero_title_line2`, `hero_subtitle`, `hero_description`, `hero_background`, `stat1_value`, `stat1_label`, `stat2_value`, `stat2_label`, `stat3_value`, `stat3_label`, `about_heading`, `about_description`, `cta_heading`, `cta_description`, `updated_at`) VALUES ('1', 'Dr. Brendalyn Wansi Calacat', 'Dental Clinic', '09388631221', 'dr.brendalyn@gmail.comss', 'san josef', '08:00 AM – 05:00 PM', 'https://www.facebook.com/jhonny.santiago.10420', '#', '#', 'DR. BRENDALYN', 'Your Smile,', 'Our Priority', 'Specialized dentistry with personalized care.', 'Our system is designed to reduce confusion and make every step from booking to visit smooth and well-organized.', 'uploads/hero/1784527647_dentalimg.jpg', '10', 'Dental Services', 'Easy', 'Online Booking', 'Fast', 'Confirmation', 'Built for a better patient experience', 'Our system is designed to reduce confusion and make every step from booking to visit smooth and well-organized.', 'Book your appointment today', 'Let us handle the scheduling so you can focus on getting the care you need. Quick, simple, and confirmation guaranteed.', '2026-07-27 15:45:59');

-- Table: dental_chart
DROP TABLE IF EXISTS `dental_chart`;
CREATE TABLE `dental_chart` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_key` varchar(150) NOT NULL,
  `tooth_number` varchar(5) NOT NULL,
  `condition_key` varchar(30) NOT NULL DEFAULT 'healthy',
  `notes` text,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_patient_tooth` (`patient_key`,`tooth_number`),
  KEY `idx_patient_key` (`patient_key`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table dental_chart
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('1', '+639388631221', '42', 'caries', 'asd', 'Staff', '2026-07-23 14:37:42');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('3', '+639388631221', '43', 'filled', 'asasd', 'jhonny santiago', '2026-07-23 14:51:04');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('4', '09382629463', '13', 'filled', '', 'jhonny santiago', '2026-07-23 20:24:24');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('5', '+639388631221', '11', 'missing', '', 'jhonny santiagos', '2026-07-25 13:15:53');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('6', '+639388631221', '23', 'healthy', '', 'jhonny santiagos', '2026-07-25 14:12:07');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('7', '+639388631221', '32', 'fractured', '', 'jhonny santiagos', '2026-07-25 13:37:40');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('8', '+639388631221', '34', 'impacted', '', 'jhonny santiagos', '2026-07-25 13:37:32');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('11', '+639388631221', '46', 'filled', '', 'jhonny santiagos', '2026-07-27 14:44:08');
INSERT INTO `dental_chart` (`id`, `patient_key`, `tooth_number`, `condition_key`, `notes`, `updated_by`, `updated_at`) VALUES ('12', '+639388631221', '41', 'impacted', '', 'jhonny santiagos', '2026-07-27 15:08:47');

-- Table: dentist_availability_exceptions
DROP TABLE IF EXISTS `dentist_availability_exceptions`;
CREATE TABLE `dentist_availability_exceptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exception_date` date NOT NULL,
  `status` enum('available','half_day','unavailable') COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_date` (`exception_date`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: dentist_working_hours
DROP TABLE IF EXISTS `dentist_working_hours`;
CREATE TABLE `dentist_working_hours` (
  `id` int NOT NULL AUTO_INCREMENT,
  `day_of_week` tinyint NOT NULL COMMENT '0=Sunday ... 6=Saturday',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `end_time` time NOT NULL DEFAULT '17:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_day` (`day_of_week`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table dentist_working_hours
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('1', '0', '1', '08:00:00', '17:00:00');
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('2', '1', '1', '08:00:00', '17:00:00');
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('3', '2', '1', '08:00:00', '17:00:00');
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('4', '3', '1', '08:00:00', '17:00:00');
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('5', '4', '1', '08:00:00', '17:00:00');
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('6', '5', '1', '08:00:00', '17:00:00');
INSERT INTO `dentist_working_hours` (`id`, `day_of_week`, `is_active`, `start_time`, `end_time`) VALUES ('7', '6', '1', '08:00:00', '17:00:00');

-- Table: dentists
DROP TABLE IF EXISTS `dentists`;
CREATE TABLE `dentists` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `specialization` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `license_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `years_experience` int DEFAULT '0',
  `bio` text COLLATE utf8mb4_unicode_ci,
  `profile_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table dentists
INSERT INTO `dentists` (`id`, `first_name`, `last_name`, `specialization`, `email`, `phone`, `license_number`, `years_experience`, `bio`, `profile_image`, `is_active`, `created_at`, `updated_at`) VALUES ('1', 'Juan', 'Dela Cruz', 'General Dentistry', 'juan.delacruz@clinic.com', '09123456789', 'DENT-2024-001', '8', 'Dr. Juan Dela Cruz has over 8 years of experience in general dentistry, focusing on preventive care and patient education.', NULL, '1', '2026-07-22 11:12:10', '2026-07-22 11:12:10');
INSERT INTO `dentists` (`id`, `first_name`, `last_name`, `specialization`, `email`, `phone`, `license_number`, `years_experience`, `bio`, `profile_image`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'Maria', 'Santos', 'Orthodontics', 'maria.santos@clinic.com', '09123456788', 'DENT-2024-002', '12', 'Dr. Maria Santos specializes in orthodontics with extensive experience in braces and clear aligner treatments.', NULL, '1', '2026-07-22 11:12:10', '2026-07-22 11:12:10');
INSERT INTO `dentists` (`id`, `first_name`, `last_name`, `specialization`, `email`, `phone`, `license_number`, `years_experience`, `bio`, `profile_image`, `is_active`, `created_at`, `updated_at`) VALUES ('3', 'Jose', 'Rizal', 'Oral Surgery', 'jose.rizal@clinic.com', '09123456787', 'DENT-2024-003', '12', 'Dr. Jose Rizal is an experienced oral surgeon specializing in dental implants, extractions, and complex surgical procedures.', NULL, '1', '2026-07-22 11:12:10', '2026-07-22 11:46:43');
INSERT INTO `dentists` (`id`, `first_name`, `last_name`, `specialization`, `email`, `phone`, `license_number`, `years_experience`, `bio`, `profile_image`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'jhonnysad', 'santiago', 'oral surgery', 'sammsasdggawadrid556@gmail.com', '09382629463', 'dent-x44', '2', 'asd', NULL, '1', '2026-07-22 11:41:51', '2026-07-22 11:41:51');

-- Table: email_verifications
DROP TABLE IF EXISTS `email_verifications`;
CREATE TABLE `email_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `otp_code` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT ((now() + interval 5 minute)),
  `is_verified` tinyint(1) DEFAULT '0',
  `is_used` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`(250)),
  KEY `idx_otp` (`otp_code`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: features
DROP TABLE IF EXISTS `features`;
CREATE TABLE `features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa-list-check',
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table features
INSERT INTO `features` (`id`, `icon`, `title`, `description`, `sort_order`, `is_active`, `image`) VALUES ('1', 'fa-list-check', 'Guided booking process', 'Step-by-step appointment request that\'s easy to follow for both new and returning patients.', '1', '1', '');
INSERT INTO `features` (`id`, `icon`, `title`, `description`, `sort_order`, `is_active`, `image`) VALUES ('2', 'fa-clock', 'Clear service information', 'Review treatment details, pricing, and duration before you commit to an appointment.', '2', '1', NULL);
INSERT INTO `features` (`id`, `icon`, `title`, `description`, `sort_order`, `is_active`, `image`) VALUES ('3', 'fa-comment-dots', 'Direct clinic support', 'Use the chat to ask questions about services, schedules, or anything before your visit.', '3', '1', NULL);
INSERT INTO `features` (`id`, `icon`, `title`, `description`, `sort_order`, `is_active`, `image`) VALUES ('4', 'fa-file-lines', 'Organized scheduling', 'Every confirmed appointment is tracked and organized so both parties are always prepared.', '4', '1', NULL);

-- Table: gallery_items
DROP TABLE IF EXISTS `gallery_items`;
CREATE TABLE `gallery_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa-image',
  `tag` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table gallery_items
INSERT INTO `gallery_items` (`id`, `icon`, `tag`, `sort_order`, `is_active`, `image`) VALUES ('1', 'fa-user-doctor', 'In Sessions', '1', '1', 'uploads/gallery/1784527032_2ef71de2-93d4-4998-999d-0456ec6bd13e.jpeg');
INSERT INTO `gallery_items` (`id`, `icon`, `tag`, `sort_order`, `is_active`, `image`) VALUES ('2', 'fa-flask', 'Sterile Tools', '2', '1', NULL);
INSERT INTO `gallery_items` (`id`, `icon`, `tag`, `sort_order`, `is_active`, `image`) VALUES ('3', 'fa-chair', 'Treatment Chair', '3', '1', NULL);
INSERT INTO `gallery_items` (`id`, `icon`, `tag`, `sort_order`, `is_active`, `image`) VALUES ('4', 'fa-store', 'Reception', '4', '1', NULL);
INSERT INTO `gallery_items` (`id`, `icon`, `tag`, `sort_order`, `is_active`, `image`) VALUES ('5', 'fa-kit-medical', 'Equipment', '5', '1', NULL);
INSERT INTO `gallery_items` (`id`, `icon`, `tag`, `sort_order`, `is_active`, `image`) VALUES ('6', 'fa-hand-holding-heart', 'Patient Care', '6', '1', NULL);

-- Table: medical_dental_history
DROP TABLE IF EXISTS `medical_dental_history`;
CREATE TABLE `medical_dental_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `under_physician_care` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physician_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pregnant` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taking_medicine` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medicine_details` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conditions` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_condition_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allergic_local_anesthesia` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allergic_antibiotics` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allergic_pain_killer` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allergic_other` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allergic_other_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hospitalized` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hospitalized_when` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hospitalized_why` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `worn_denture` enum('yes','no') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_dental_visit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_dental_visit_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appointment_id` (`appointment_id`)
) ENGINE=MyISAM AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table medical_dental_history
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('5', '7', 'no', '', 'no', 'no', '', '', '', 'no', 'no', 'yes', 'no', '', 'no', '', '', 'no', '', '', '2026-07-22 20:46:32');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('6', '11', 'yes', 'asd', 'yes', 'yes', 'asd', 'as', 'asd', 'yes', 'no', 'yes', 'no', 'asd', 'yes', 'asd', 'as', 'yes', 'asd', 'asd', '2026-07-23 20:14:18');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('2', '9', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-22 19:58:19');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('4', '8', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-22 20:05:00');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('7', '12', 'yes', 'asd', 'yes', 'yes', 'asd', 'asd', 'asd', 'yes', 'no', 'yes', 'no', 'asd', 'yes', 'asd', 'as', 'yes', 'asd', 'asd', '2026-07-23 22:32:27');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('8', '13', 'yes', 'asd', 'yes', 'yes', 'asd', 'asd', 'asd', 'yes', 'no', 'yes', 'no', 'asd', 'yes', 'asd', 'as', 'yes', 'asd', 'asd', '2026-07-23 22:49:08');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('9', '14', 'no', '', 'no', 'no', '', 'High Blood Pressure, Asthma, Diabetes', '', 'no', 'no', 'no', 'no', '', 'no', '', '', 'no', '67', 'asdasd', '2026-07-23 22:53:53');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('10', '15', 'yes', 'asd', 'yes', 'yes', 'asd', 'asd', 'asd', 'yes', 'no', 'yes', 'no', 'asd', 'yes', 'asd', 'as', 'yes', 'asd', 'asd', '2026-07-23 22:55:34');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('11', '16', 'yes', '—sadsd', 'no', 'yes', '—asd', 'Low Blood Pressure, Heart Disease', '—', 'no', 'yes', 'no', 'no', '—as', 'yes', '—', '—asd', 'no', '10 months ago', 'lalangg', '2026-07-23 23:34:37');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('12', '17', 'yes', '', 'no', 'yes', '', 'Low Blood Pressure', '', 'no', 'yes', 'no', 'no', '', 'no', '', '', 'no', '10 months ago', 'lalangg', '2026-07-25 13:23:16');
INSERT INTO `medical_dental_history` (`id`, `appointment_id`, `under_physician_care`, `physician_name`, `pregnant`, `taking_medicine`, `medicine_details`, `conditions`, `other_condition_details`, `allergic_local_anesthesia`, `allergic_antibiotics`, `allergic_pain_killer`, `allergic_other`, `allergic_other_details`, `hospitalized`, `hospitalized_when`, `hospitalized_why`, `worn_denture`, `last_dental_visit`, `last_dental_visit_reason`, `created_at`) VALUES ('13', '18', 'yes', '', 'no', 'yes', '', 'Low Blood Pressure', '', 'no', 'yes', 'no', 'no', '', 'no', '', '', 'no', '10 months ago', 'lalangg', '2026-07-27 09:22:19');

-- Table: otp_verifications
DROP TABLE IF EXISTS `otp_verifications`;
CREATE TABLE `otp_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_verified` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phone_email` (`phone`,`email`),
  KEY `idx_otp_code` (`otp_code`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table otp_verifications
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('3', '+639388631221', 'santiagojhonny607@gmail.com', '302922', '2026-07-20 08:14:56', '1', '2026-07-20 16:09:56');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('4', '+639388631221', 'santiagojhpnny607@gmail.com', '481957', '2026-07-20 11:02:40', '1', '2026-07-20 18:57:40');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('6', '+639388631221', 'pulmonesmasdsrjorie8@gmail.com', '595236', '2026-07-20 13:21:02', '0', '2026-07-20 21:16:02');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('9', '+639388631221', 'pulmondesmarjorie8@gmail.com', '516184', '2026-07-20 14:11:48', '0', '2026-07-20 22:06:48');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('10', '+639388631221', 'asddsa@gmail.com', '383744', '2026-07-21 06:04:35', '0', '2026-07-21 13:59:35');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('12', '+639388631221', 'pulmonesmqewearjorie8@gmail.com', '475946', '2026-07-21 06:15:39', '0', '2026-07-21 14:10:39');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('13', '+639388631221', 'pulmoneasdasmarjorie8@gmail.com', '143233', '2026-07-21 06:42:06', '1', '2026-07-21 14:37:06');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('14', '+639388631221', 'pulmonesmarjasdorie8@gmail.com', '660354', '2026-07-21 06:56:55', '1', '2026-07-21 14:51:55');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('15', '+639388631221', 'sdfdcfsrjorie8@gmail.com', '187994', '2026-07-21 10:37:09', '0', '2026-07-21 18:32:09');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('16', '+639388631221', 'puldmarjorie8@gmail.com', '288835', '2026-07-21 10:38:12', '1', '2026-07-21 18:33:12');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('17', '+639388631221', 'pulmonesfdsddfmarjorie8@gmail.com', '961731', '2026-07-21 14:39:59', '1', '2026-07-21 22:34:59');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('18', '+639388631221', 'pulmonesmarjoriasdasd8@gmail.com', '474315', '2026-07-22 02:46:13', '0', '2026-07-22 10:41:13');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('19', '+639388631221', 'pulmonesmasdrie8@gmail.com', '180146', '2026-07-22 02:56:04', '1', '2026-07-22 10:51:04');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('20', '+639388631221', 'pulmonesmajkljkrjorie8@gmail.com', '761130', '2026-07-23 14:57:56', '1', '2026-07-23 22:52:56');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('21', '+639454466213', 'choyabitona@gmail.com', '883021', '2026-07-23 15:34:22', '0', '2026-07-23 23:29:22');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('22', '+639388631221', 'chhhhas@gmail.com', '126847', '2026-07-23 15:37:38', '0', '2026-07-23 23:32:38');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('23', '+639388631221', 'pulmonesmarjsorie8@gmail.com', '121911', '2026-07-23 15:38:30', '1', '2026-07-23 23:33:30');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('24', '+639388631221', 'pulmonessssmarjorie8@gmail.com', '353951', '2026-07-25 06:23:17', '0', '2026-07-25 14:18:17');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('25', '+639388631221', 'pulmonesmassddaasrjorie8@gmail.com', '220889', '2026-07-25 07:33:27', '1', '2026-07-25 15:28:27');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('26', '+639382629463', 'sammadssrid556@gmail.com', '136537', '2026-07-25 08:11:26', '0', '2026-07-25 16:06:26');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('27', '+639382629463', 'sammadrid55ssd6@gmail.com', '338257', '2026-07-25 08:29:17', '0', '2026-07-25 16:24:17');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('29', '+639382629463', 'asdsdsamdsamadrid556@gmail.com', '570958', '2026-07-25 08:37:28', '1', '2026-07-25 16:32:28');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('30', '+639382629463', 'asdasd@gmail.com', '672569', '2026-07-25 08:40:35', '0', '2026-07-25 16:35:35');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('31', '+639382629463', 'asdsammadriddsasdd556@gmail.com', '992119', '2026-07-25 08:43:40', '1', '2026-07-25 16:38:40');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('32', '+639382629463', 'sammadrid556@gmail.com', '760270', '2026-07-25 08:57:07', '0', '2026-07-25 16:52:07');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('33', '+639382629463', 'sammddadrid556@gmail.com', '305195', '2026-07-25 08:58:09', '1', '2026-07-25 16:53:09');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('34', '+639382629463', 'sammasdasfgrid556@gmail.com', '892999', '2026-07-25 09:00:14', '1', '2026-07-25 16:55:14');
INSERT INTO `otp_verifications` (`id`, `phone`, `email`, `otp_code`, `expires_at`, `is_verified`, `created_at`) VALUES ('35', '+639382629463', 'sammadssdarid556@gmail.com', '301106', '2026-07-27 05:46:54', '1', '2026-07-27 13:41:54');

-- Table: patient_record_archive
DROP TABLE IF EXISTS `patient_record_archive`;
CREATE TABLE `patient_record_archive` (
  `id` int NOT NULL AUTO_INCREMENT,
  `receipt_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `appointment_id` int NOT NULL,
  `patient_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `snapshot_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `generated_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_receipt_code` (`receipt_code`),
  KEY `idx_appointment_id` (`appointment_id`)
) ENGINE=MyISAM AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table patient_record_archive
INSERT INTO `patient_record_archive` (`id`, `receipt_code`, `appointment_id`, `patient_key`, `full_name`, `snapshot_json`, `generated_by`, `created_at`) VALUES ('10', 'RCPT-20260725-5E910C', '17', '+639388631221', 'uhuy', '{\"appointment\":{\"id\":17,\"phone\":\"+639388631221\",\"email\":\"pulmonesmarjsorie8@gmail.com\",\"services\":\"[2]\",\"appointment_date\":\"2026-07-30\",\"appointment_time\":\"14:00:00\",\"full_name\":\"uhuy\",\"age\":0,\"occupation\":\"\",\"region\":null,\"province\":null,\"city\":null,\"barangay\":null,\"street_address\":\"\",\"notes\":\"\",\"internal_notes\":null,\"terms_accepted\":1,\"privacy_accepted\":1,\"is_verified\":1,\"status\":\"approved\",\"dentist_id\":null,\"created_at\":\"2026-07-25 13:23:16\",\"updated_at\":\"2026-07-25 13:24:30\",\"tracking_token\":\"MTd8MTc4NDk1Njk5Nnw3OTM3\"},\"dentist\":null,\"services\":[{\"id\":\"2\",\"title\":\"Dental Implants\",\"description\":\"Professional dental care tailored to your individual needs and comfort.\",\"icon\":\"fa-tooth\",\"duration\":\"30 min\",\"price\":\"50000.00\",\"sort_order\":\"2\",\"is_active\":\"1\",\"image\":\"uploads\\/services\\/1784528243_dentalimg1.jpg\",\"created_at\":\"2026-07-20 12:37:08\"}],\"total_price\":50000,\"medical_history\":{\"id\":12,\"appointment_id\":17,\"under_physician_care\":\"yes\",\"physician_name\":\"\",\"pregnant\":\"no\",\"taking_medicine\":\"yes\",\"medicine_details\":\"\",\"conditions\":\"Low Blood Pressure\",\"other_condition_details\":\"\",\"allergic_local_anesthesia\":\"no\",\"allergic_antibiotics\":\"yes\",\"allergic_pain_killer\":\"no\",\"allergic_other\":\"no\",\"allergic_other_details\":\"\",\"hospitalized\":\"no\",\"hospitalized_when\":\"\",\"hospitalized_why\":\"\",\"worn_denture\":\"no\",\"last_dental_visit\":\"10 months ago\",\"last_dental_visit_reason\":\"lalangg\",\"created_at\":\"2026-07-25 13:23:16\"},\"dental_chart\":[{\"tooth_number\":\"11\",\"condition_key\":\"missing\",\"notes\":\"\",\"updated_by\":\"jhonny santiagos\",\"updated_at\":\"2026-07-25 13:15:53\"},{\"tooth_number\":\"23\",\"condition_key\":\"impacted\",\"notes\":\"\",\"updated_by\":\"jhonny santiagos\",\"updated_at\":\"2026-07-25 13:15:56\"},{\"tooth_number\":\"32\",\"condition_key\":\"fractured\",\"notes\":\"\",\"updated_by\":\"jhonny santiagos\",\"updated_at\":\"2026-07-25 13:37:40\"},{\"tooth_number\":\"34\",\"condition_key\":\"impacted\",\"notes\":\"\",\"updated_by\":\"jhonny santiagos\",\"updated_at\":\"2026-07-25 13:37:32\"},{\"tooth_number\":\"42\",\"condition_key\":\"caries\",\"notes\":\"asd\",\"updated_by\":\"Staff\",\"updated_at\":\"2026-07-23 14:37:42\"},{\"tooth_number\":\"43\",\"condition_key\":\"filled\",\"notes\":\"asasd\",\"updated_by\":\"jhonny santiago\",\"updated_at\":\"2026-07-23 14:51:04\"}],\"other_appointments\":[{\"id\":16,\"appointment_date\":\"2026-07-30\",\"appointment_time\":\"10:30:00\",\"status\":\"approved\",\"services\":\"[\\\"1\\\",\\\"2\\\",\\\"3\\\"]\"}],\"generated_at\":\"2026-07-25 13:37:47\"}', 'jhonny santiagos', '2026-07-25 13:37:47');
INSERT INTO `patient_record_archive` (`id`, `receipt_code`, `appointment_id`, `patient_key`, `full_name`, `snapshot_json`, `generated_by`, `created_at`) VALUES ('9', 'RCPT-20260725-E75B2F', '16', '+639388631221', 'jhonny santiago', '{\"appointment\":{\"id\":16,\"phone\":\"+639388631221\",\"email\":\"pulmonesmarjsorie8@gmail.com\",\"services\":\"[\\\"1\\\",\\\"2\\\",\\\"3\\\"]\",\"appointment_date\":\"2026-07-30\",\"appointment_time\":\"10:30:00\",\"full_name\":\"jhonny santiago\",\"age\":20,\"occupation\":\"ghg\",\"region\":\"Western Visayas\",\"province\":\"Capiz\",\"city\":\"President Roxas\",\"barangay\":\"Poblacion\",\"street_address\":\"san josef\",\"notes\":\"test\",\"internal_notes\":null,\"terms_accepted\":1,\"privacy_accepted\":1,\"is_verified\":1,\"status\":\"approved\",\"dentist_id\":1,\"created_at\":\"2026-07-23 23:34:37\",\"updated_at\":\"2026-07-25 13:33:15\",\"tracking_token\":\"MTZ8MTc4NDgyMDg3N3w3MTI3\"},\"dentist\":{\"id\":1,\"first_name\":\"Juan\",\"last_name\":\"Dela Cruz\",\"specialization\":\"General Dentistry\",\"email\":\"juan.delacruz@clinic.com\",\"phone\":\"09123456789\",\"license_number\":\"DENT-2024-001\",\"years_experience\":8,\"bio\":\"Dr. Juan Dela Cruz has over 8 years of experience in general dentistry, focusing on preventive care and patient education.\",\"profile_image\":null,\"is_active\":1,\"created_at\":\"2026-07-22 11:12:10\",\"updated_at\":\"2026-07-22 11:12:10\"},\"services\":[{\"id\":\"1\",\"title\":\"Dental Cleaning\",\"description\":\"Professional dental care tailored to your individual needs and comfort.\",\"icon\":\"fa-tooth\",\"duration\":\"30 min\",\"price\":\"700.00\",\"sort_order\":\"1\",\"is_active\":\"1\",\"image\":\"uploads\\/services\\/1784528232_dentalimg.jpg\",\"created_at\":\"2026-07-20 12:37:08\"},{\"id\":\"2\",\"title\":\"Dental Implants\",\"description\":\"Professional dental care tailored to your individual needs and comfort.\",\"icon\":\"fa-tooth\",\"duration\":\"30 min\",\"price\":\"50000.00\",\"sort_order\":\"2\",\"is_active\":\"1\",\"image\":\"uploads\\/services\\/1784528243_dentalimg1.jpg\",\"created_at\":\"2026-07-20 12:37:08\"},{\"id\":\"3\",\"title\":\"Dentures, Crowns and Fix Bridges\",\"description\":\"Professional dental care tailored to your individual needs and comfort.\",\"icon\":\"fa-crown\",\"duration\":\"30 min\",\"price\":\"5000.00\",\"sort_order\":\"3\",\"is_active\":\"1\",\"image\":\"uploads\\/services\\/1784528253_dentalimg2.jpg\",\"created_at\":\"2026-07-20 12:37:08\"}],\"total_price\":55700,\"medical_history\":{\"id\":11,\"appointment_id\":16,\"under_physician_care\":\"yes\",\"physician_name\":\"\",\"pregnant\":\"no\",\"taking_medicine\":\"yes\",\"medicine_details\":\"\",\"conditions\":\"Low Blood Pressure, Heart Disease\",\"other_condition_details\":\"\",\"allergic_local_anesthesia\":\"no\",\"allergic_antibiotics\":\"yes\",\"allergic_pain_killer\":\"no\",\"allergic_other\":\"no\",\"allergic_other_details\":\"\",\"hospitalized\":\"no\",\"hospitalized_when\":\"\",\"hospitalized_why\":\"\",\"worn_denture\":\"no\",\"last_dental_visit\":\"10 months ago\",\"last_dental_visit_reason\":\"lalangg\",\"created_at\":\"2026-07-23 23:34:37\"},\"dental_chart\":[{\"tooth_number\":\"11\",\"condition_key\":\"missing\",\"notes\":\"\",\"updated_by\":\"jhonny santiagos\",\"updated_at\":\"2026-07-25 13:15:53\"},{\"tooth_number\":\"23\",\"condition_key\":\"impacted\",\"notes\":\"\",\"updated_by\":\"jhonny santiagos\",\"updated_at\":\"2026-07-25 13:15:56\"},{\"tooth_number\":\"42\",\"condition_key\":\"caries\",\"notes\":\"asd\",\"updated_by\":\"Staff\",\"updated_at\":\"2026-07-23 14:37:42\"},{\"tooth_number\":\"43\",\"condition_key\":\"filled\",\"notes\":\"asasd\",\"updated_by\":\"jhonny santiago\",\"updated_at\":\"2026-07-23 14:51:04\"}],\"other_appointments\":[{\"id\":17,\"appointment_date\":\"2026-07-30\",\"appointment_time\":\"14:00:00\",\"status\":\"approved\",\"services\":\"[2]\"}],\"generated_at\":\"2026-07-25 13:36:42\"}', 'jhonny santiagos', '2026-07-25 13:36:42');

-- Table: patient_services
DROP TABLE IF EXISTS `patient_services`;
CREATE TABLE `patient_services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `service_id` int NOT NULL,
  `dentist_id` int NOT NULL,
  `session_count` int NOT NULL DEFAULT '1',
  `total_sessions` int NOT NULL DEFAULT '1',
  `completed_sessions` int DEFAULT '0',
  `status` enum('pending','in-progress','completed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `appointment_date` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `service_id` (`service_id`),
  KEY `dentist_id` (`dentist_id`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table patient_services
INSERT INTO `patient_services` (`id`, `patient_id`, `service_id`, `dentist_id`, `session_count`, `total_sessions`, `completed_sessions`, `status`, `appointment_date`, `notes`, `created_at`, `updated_at`) VALUES ('8', '8', '3', '4', '1', '1', '0', 'in-progress', NULL, '', '2026-07-23 23:45:55', '2026-07-23 23:45:55');
INSERT INTO `patient_services` (`id`, `patient_id`, `service_id`, `dentist_id`, `session_count`, `total_sessions`, `completed_sessions`, `status`, `appointment_date`, `notes`, `created_at`, `updated_at`) VALUES ('7', '8', '1', '4', '1', '1', '0', 'completed', NULL, '', '2026-07-23 23:43:18', '2026-07-23 23:44:11');

-- Table: patients
DROP TABLE IF EXISTS `patients`;
CREATE TABLE `patients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `age` int DEFAULT NULL,
  `gender` enum('Male','Female','Other','Prefer not to say') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','pending') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source` enum('walkin','appointment') COLLATE utf8mb4_unicode_ci DEFAULT 'walkin',
  `appointment_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `patient_id` (`patient_id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_full_name` (`full_name`),
  KEY `idx_phone` (`phone`),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_source` (`source`),
  KEY `idx_appointment_id` (`appointment_id`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table patients
INSERT INTO `patients` (`id`, `patient_id`, `full_name`, `age`, `gender`, `phone`, `email`, `address`, `status`, `created_at`, `updated_at`, `source`, `appointment_id`) VALUES ('8', 'PAT-20260723-C19F02', 'jhonny santiago', '0', '', '+639388631221', 'pulmonesmarjsorie8@gmail.com', '', 'active', '2026-07-23 23:35:42', '2026-07-23 23:35:42', 'appointment', '16');

-- Table: service_sessions
DROP TABLE IF EXISTS `service_sessions`;
CREATE TABLE `service_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_service_id` int NOT NULL,
  `session_number` int NOT NULL,
  `date` date DEFAULT NULL,
  `time` time DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled','no-show') COLLATE utf8mb4_unicode_ci DEFAULT 'scheduled',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_service_id` (`patient_service_id`)
) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table service_sessions
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('1', '1', '1', '2026-07-23', NULL, 'completed', '', '2026-07-23 20:39:47');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('2', '1', '2', NULL, NULL, 'completed', '', '2026-07-23 20:39:47');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('3', '1', '3', NULL, NULL, 'scheduled', '', '2026-07-23 20:39:47');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('4', '1', '4', NULL, NULL, 'scheduled', '', '2026-07-23 20:39:47');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('5', '1', '5', NULL, NULL, 'scheduled', '', '2026-07-23 20:39:47');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('6', '2', '1', '2026-07-23', NULL, 'completed', '', '2026-07-23 20:50:22');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('7', '3', '1', '2026-07-24', NULL, 'scheduled', '', '2026-07-23 21:19:23');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('8', '3', '2', '2026-07-25', NULL, 'scheduled', '', '2026-07-23 21:19:23');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('9', '3', '3', '2026-07-26', NULL, 'scheduled', '', '2026-07-23 21:19:23');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('10', '3', '4', '2026-07-27', NULL, 'scheduled', '', '2026-07-23 21:19:23');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('11', '4', '1', '2026-07-24', NULL, 'scheduled', '', '2026-07-23 21:28:46');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('12', '5', '1', '2026-07-31', NULL, 'scheduled', '', '2026-07-23 21:29:14');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('13', '6', '1', '2026-07-29', NULL, 'scheduled', '', '2026-07-23 23:05:59');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('14', '7', '1', '2026-07-31', NULL, 'completed', '', '2026-07-23 23:43:18');
INSERT INTO `service_sessions` (`id`, `patient_service_id`, `session_number`, `date`, `time`, `status`, `notes`, `created_at`) VALUES ('15', '8', '1', '2026-08-03', NULL, 'scheduled', '', '2026-07-23 23:45:55');

-- Table: services
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa-tooth',
  `duration` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '30 min',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table services
INSERT INTO `services` (`id`, `title`, `description`, `icon`, `duration`, `price`, `sort_order`, `is_active`, `image`, `created_at`) VALUES ('1', 'Dental Cleaning', 'Professional dental care tailored to your individual needs and comfort.', 'fa-tooth', '30 min', '700.00', '1', '1', 'uploads/services/1784528232_dentalimg.jpg', '2026-07-20 12:37:08');
INSERT INTO `services` (`id`, `title`, `description`, `icon`, `duration`, `price`, `sort_order`, `is_active`, `image`, `created_at`) VALUES ('2', 'Dental Implants', 'Professional dental care tailored to your individual needs and comfort.', 'fa-tooth', '30 min', '50000.00', '2', '1', 'uploads/services/1784528243_dentalimg1.jpg', '2026-07-20 12:37:08');
INSERT INTO `services` (`id`, `title`, `description`, `icon`, `duration`, `price`, `sort_order`, `is_active`, `image`, `created_at`) VALUES ('3', 'Dentures, Crowns and Fix Bridges', 'Professional dental care tailored to your individual needs and comfort.', 'fa-crown', '30 min', '5000.00', '3', '1', 'uploads/services/1784528253_dentalimg2.jpg', '2026-07-20 12:37:08');

-- Table: staff_channel_messages
DROP TABLE IF EXISTS `staff_channel_messages`;
CREATE TABLE `staff_channel_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sender_id` int NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`)
) ENGINE=MyISAM AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table staff_channel_messages
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('1', '22', 'heyy', NULL, NULL, '2026-07-25 12:15:13');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('2', '22', 'asdasd', NULL, NULL, '2026-07-25 12:15:16');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('3', '1', 'ehhh', NULL, NULL, '2026-07-25 12:19:31');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('4', '1', 'asdas', NULL, NULL, '2026-07-25 12:35:51');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('5', '1', 'asdasd', NULL, NULL, '2026-07-25 12:38:14');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('6', '22', 'asdasd', NULL, NULL, '2026-07-25 12:39:35');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('7', '22', 'asdasd', NULL, NULL, '2026-07-25 12:40:31');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('8', '1', 'asdasdas', NULL, NULL, '2026-07-25 12:43:28');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('9', '1', 'hahahahhahahsdasdytVADFGABNHSJDMKLA,S;DSA', NULL, NULL, '2026-07-25 12:46:59');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('10', '1', 'ASDASDASDAS FHTASGJDHJALSK;D', NULL, NULL, '2026-07-25 12:47:07');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('11', '1', 'ASD', NULL, NULL, '2026-07-25 12:47:08');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('12', '22', 'aaasdads', NULL, NULL, '2026-07-25 12:47:52');
INSERT INTO `staff_channel_messages` (`id`, `sender_id`, `message`, `attachment`, `attachment_type`, `created_at`) VALUES ('13', '1', 'asdas', NULL, NULL, '2026-07-25 12:50:16');

-- Table: staff_channel_reads
DROP TABLE IF EXISTS `staff_channel_reads`;
CREATE TABLE `staff_channel_reads` (
  `user_id` int NOT NULL,
  `last_read_message_id` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table staff_channel_reads
INSERT INTO `staff_channel_reads` (`user_id`, `last_read_message_id`) VALUES ('22', '13');
INSERT INTO `staff_channel_reads` (`user_id`, `last_read_message_id`) VALUES ('1', '13');

-- Table: staff_dm_conversations
DROP TABLE IF EXISTS `staff_dm_conversations`;
CREATE TABLE `staff_dm_conversations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_one_id` int NOT NULL,
  `user_two_id` int NOT NULL,
  `last_message_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pair` (`user_one_id`,`user_two_id`),
  KEY `user_two_id` (`user_two_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table staff_dm_conversations
INSERT INTO `staff_dm_conversations` (`id`, `user_one_id`, `user_two_id`, `last_message_at`) VALUES ('1', '1', '22', '2026-07-25 12:48:14');

-- Table: staff_dm_messages
DROP TABLE IF EXISTS `staff_dm_messages`;
CREATE TABLE `staff_dm_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversation_id` int NOT NULL,
  `sender_id` int NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `sender_id` (`sender_id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data for table staff_dm_messages
INSERT INTO `staff_dm_messages` (`id`, `conversation_id`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('1', '1', '22', 'asdas', NULL, NULL, '1', '2026-07-25 12:17:17');
INSERT INTO `staff_dm_messages` (`id`, `conversation_id`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('2', '1', '1', 'asdasd', NULL, NULL, '1', '2026-07-25 12:35:38');
INSERT INTO `staff_dm_messages` (`id`, `conversation_id`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('3', '1', '22', 'admin', NULL, NULL, '1', '2026-07-25 12:48:06');
INSERT INTO `staff_dm_messages` (`id`, `conversation_id`, `sender_id`, `message`, `attachment`, `attachment_type`, `is_read`, `created_at`) VALUES ('4', '1', '1', 'staff', NULL, NULL, '1', '2026-07-25 12:48:14');

-- Table: staff_replies
DROP TABLE IF EXISTS `staff_replies`;
CREATE TABLE `staff_replies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `staff_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `staff_role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reply_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message` (`message_id`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','staff','patient') DEFAULT 'patient',
  `is_active` tinyint(1) DEFAULT '0',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_email` (`email`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table users
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('1', 'sssantiagojhonny607@gmail.com', '$2y$10$aGMT3Tb7zFtvHOfh5w4JG.9V7RhOp1hixs1LE4iQNN0erm2zZ3IES', 'jhonny santiagos', '09382629463', 'admin', '1', '2026-07-28 12:14:46', '2026-07-20 17:49:00', '2026-07-28 12:14:46');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('21', 'asdasdasdasqw@gmail.com', '$2y$10$aGMT3Tb7zFtvHOfh5w4JG.9V7RhOp1hixs1LE4iQNN0erm2zZ3IES', 'kimee', 'fgyfy', 'patient', '1', '2026-07-25 12:11:18', '2026-07-21 22:39:43', '2026-07-25 12:11:18');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('22', 'santiagsojhonny607@gmail.com', '$2y$10$aGMT3Tb7zFtvHOfh5w4JG.9V7RhOp1hixs1LE4iQNN0erm2zZ3IES', 'jhoncny santiago', '09382629463', 'staff', '1', '2026-07-28 12:15:42', '2026-07-21 22:55:58', '2026-07-28 12:15:42');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('27', 'pulmonesmarjsorie8@gmail.com', '$2y$10$aGMT3Tb7zFtvHOfh5w4JG.9V7RhOp1hixs1LE4iQNN0erm2zZ3IES', 'uhuy', '+639388631221', 'patient', '1', '2026-07-27 16:38:29', '2026-07-23 23:39:41', '2026-07-27 16:38:29');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('43', 'asdsdsamdsamadrid556@gmail.com', '$2y$10$uMYVf.X0A15OSS5pgjA4aOhUfr6KB2jlz6I3ab6IvO.S/fNkw3dpC', 'Lisandro Madridss', '+639382629463', 'patient', '1', NULL, '2026-07-25 16:32:51', '2026-07-25 16:32:51');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('44', 'asdsammadriddsasdd556@gmail.com', '$2y$10$WWnuFp3CwX/k539Rw/hm9.rvrarpT/NTDLkhTCVxZoYou6wlalXIC', 'jhonny santiago', '+639382629463', 'patient', '1', NULL, '2026-07-25 16:38:58', '2026-07-25 16:38:58');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('45', 'sammddadrid556@gmail.com', '$2y$10$/jKm3o6vzOByrMoLWFzmVuaPmF1qHy9M8ZkmN0YRGvs8FbPq10pgy', 'jhonny santiago', '+639382629463', 'patient', '1', NULL, '2026-07-25 16:53:28', '2026-07-25 16:53:28');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('46', 'sammasdasfgrid556@gmail.com', '$2y$10$DrG.9FfXr9b11LbKu7QHBeiPFAnpzv7t76Sp9IWjAHtO0veKL0oFe', 'jhonny santiago', '+639382629463', 'patient', '1', NULL, '2026-07-25 16:55:31', '2026-07-25 16:55:31');
INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('47', 'sammadssdarid556@gmail.com', '$2y$10$jd0XFV1H9.gxdR2K2VQfWOZ8F2mLD80CFFx89Sn3b3bwqIrnXKNKe', 'Lisandro Madridss', '+639382629463', 'patient', '1', NULL, '2026-07-27 13:42:29', '2026-07-27 13:42:29');

