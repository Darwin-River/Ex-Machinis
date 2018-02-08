-- MySQL dump 10.13  Distrib 5.7.21, for Linux (x86_64)
--
-- Host: localhost    Database: exmachinis
-- ------------------------------------------------------
-- Server version	5.7.21-0ubuntu0.16.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `agents`
--

DROP TABLE IF EXISTS `agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int(10) unsigned NOT NULL,
  `coord_x` double(8,2) NOT NULL,
  `coord_y` double(8,2) NOT NULL,
  `coord_z` double(8,2) NOT NULL,
  `speed_x` double(8,2) NOT NULL DEFAULT '0.00',
  `speed_y` double(8,2) NOT NULL DEFAULT '0.00',
  `speed_z` double(8,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `agents_company_id_foreign` (`company_id`),
  CONSTRAINT `agents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agents`
--

LOCK TABLES `agents` WRITE;
/*!40000 ALTER TABLE `agents` DISABLE KEYS */;
INSERT INTO `agents` VALUES (1,'SN667554',1,0.00,0.00,0.00,0.00,0.00,0.00),(2,'SN613613',1,0.00,0.00,0.00,0.00,0.00,0.00),(3,'SN415900',1,0.00,0.00,0.00,0.00,0.00,0.00),(4,'SN89726',2,0.00,0.00,0.00,0.00,0.00,0.00),(5,'SN310287',2,0.00,0.00,0.00,0.00,0.00,0.00),(6,'SN936010',2,0.00,0.00,0.00,0.00,0.00,0.00),(7,'SN202531',3,0.00,0.00,0.00,0.00,0.00,0.00),(8,'SN600438',3,0.00,0.00,0.00,0.00,0.00,0.00),(9,'SN177700',3,0.00,0.00,0.00,0.00,0.00,0.00),(25,'SN391506',9,0.00,0.00,0.00,0.00,0.00,0.00),(26,'SN131915',9,0.00,0.00,0.00,0.00,0.00,0.00),(27,'SN490906',9,0.00,0.00,0.00,0.00,0.00,0.00),(28,'SN108795',10,0.00,0.00,0.00,0.00,0.00,0.00),(29,'SN98290',10,0.00,0.00,0.00,0.00,0.00,0.00),(30,'SN398246',10,0.00,0.00,0.00,0.00,0.00,0.00),(46,'SN363105',16,0.00,0.00,0.00,0.00,0.00,0.00),(47,'SN809821',16,0.00,0.00,0.00,0.00,0.00,0.00),(48,'SN404777',16,0.00,0.00,0.00,0.00,0.00,0.00),(49,'SN429164',17,0.00,0.00,0.00,0.00,0.00,0.00),(50,'SN335346',17,0.00,0.00,0.00,0.00,0.00,0.00),(51,'SN309471',17,0.00,0.00,0.00,0.00,0.00,0.00),(52,'SN834430',18,0.00,0.00,0.00,0.00,0.00,0.00),(53,'SN971849',18,0.00,0.00,0.00,0.00,0.00,0.00),(54,'SN273244',18,0.00,0.00,0.00,0.00,0.00,0.00);
/*!40000 ALTER TABLE `agents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commands`
--

DROP TABLE IF EXISTS `commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commands` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commands_agent_id_foreign` (`agent_id`),
  CONSTRAINT `commands_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commands`
--

LOCK TABLES `commands` WRITE;
/*!40000 ALTER TABLE `commands` DISABLE KEYS */;
INSERT INTO `commands` VALUES (1,'\r\ntest\r\n',27,'2018-01-08 23:47:59','2018-01-08 23:47:59'),(2,'\r\ntest\r\n',27,'2018-01-08 23:50:01','2018-01-08 23:50:01'),(3,'\r\ntest\r\n',27,'2018-01-09 00:00:02','2018-01-09 00:00:02'),(4,'\r\ntest\r\n',27,'2018-01-09 00:10:02','2018-01-09 00:10:02'),(5,'\r\ntest\r\n',27,'2018-01-09 00:20:01','2018-01-09 00:20:01'),(6,'\r\ntest\r\n',27,'2018-01-09 00:30:02','2018-01-09 00:30:02'),(7,'\r\ntest\r\n',27,'2018-01-09 00:40:02','2018-01-09 00:40:02'),(8,'\r\ntest\r\n',27,'2018-01-09 00:50:01','2018-01-09 00:50:01'),(9,'\r\ntest\r\n',27,'2018-01-09 01:00:02','2018-01-09 01:00:02'),(10,'\r\ntest\r\n',27,'2018-01-09 01:10:01','2018-01-09 01:10:01'),(11,'\r\ntest\r\n',27,'2018-01-09 01:20:02','2018-01-09 01:20:02'),(12,'\r\ntest\r\n',27,'2018-01-09 01:30:02','2018-01-09 01:30:02'),(13,'\r\ntest\r\n',27,'2018-01-09 01:40:01','2018-01-09 01:40:01'),(14,'\r\ntest\r\n',27,'2018-01-09 01:50:02','2018-01-09 01:50:02'),(15,'\r\ntest\r\n',27,'2018-01-09 02:00:01','2018-01-09 02:00:01'),(16,'\r\ntest\r\n',27,'2018-01-09 02:10:02','2018-01-09 02:10:02'),(17,'\r\ntest\r\n',27,'2018-01-09 02:20:01','2018-01-09 02:20:01'),(18,'\r\ntest\r\n',27,'2018-01-09 02:30:02','2018-01-09 02:30:02'),(19,'\r\ntest\r\n',27,'2018-01-09 02:40:02','2018-01-09 02:40:02'),(20,'\r\ntest\r\n',27,'2018-01-09 02:50:02','2018-01-09 02:50:02'),(21,'\r\ntest\r\n',27,'2018-01-09 03:00:01','2018-01-09 03:00:01'),(22,'\r\ntest\r\n',27,'2018-01-09 03:10:02','2018-01-09 03:10:02'),(23,'\r\ntest\r\n',27,'2018-01-09 03:20:01','2018-01-09 03:20:01'),(24,'\r\ntest\r\n',27,'2018-01-09 03:30:02','2018-01-09 03:30:02'),(25,'\r\ntest\r\n',27,'2018-01-09 03:40:02','2018-01-09 03:40:02'),(26,'\r\ntest\r\n',27,'2018-01-09 03:43:22','2018-01-09 03:43:22'),(27,'testing this',46,'2018-01-09 06:12:42','2018-01-09 06:12:42'),(28,'\r\n\r\nTest\r\n\r\n',52,'2018-01-09 18:00:01','2018-01-09 18:00:01');
/*!40000 ALTER TABLE `commands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `companies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_name_unique` (`name`),
  KEY `FK_COMPANIES_USERS_USER_ID_idx` (`user_id`),
  CONSTRAINT `companies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (1,'Williamtroke',1),(2,'Timmyzelve',2),(3,'Thomasnailt',3),(9,NULL,10),(10,NULL,11),(16,NULL,17),(17,'SimonDob',18),(18,NULL,19);
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2014_10_12_000000_create_users_table',1),(2,'2014_10_12_100000_create_password_resets_table',1),(3,'2017_10_11_000000_create_companies_table',2),(4,'2017_10_11_100000_create_agents_table',3),(5,'2017_10_11_200000_create_commands_table',4),(6,'2018_01_09_212834_companies_name_not_required',5),(7,'2018_01_09_225924_foreign_keys',6);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Williamtroke','codydavis3434@yahoo.com','$2y$10$maRcbyOV5SOexfj02ptLg.X64XcemvA5ic2U2oiaqV/v43.zfRa12',NULL,'2017-11-15 23:15:12','2017-11-15 23:15:12'),(2,'Timmyzelve','ossaka@paulos.gq','$2y$10$awFm2ze8GibQ72eD1sjKCul/YN4dsI1Fab7hCfXVQbN7bLX4VKf/.',NULL,'2017-12-02 08:32:04','2017-12-02 08:32:04'),(3,'Thomasnailt','michailcypryis@gmail.com','$2y$10$ygZfQqP3yd/MVcvwCMR7Ke78fsp4cD8y9UJE5pNQH.xzlKzkhht4a',NULL,'2017-12-03 00:47:35','2017-12-03 00:47:35'),(10,'Fernando Rivas','freelance.frivas@gmail.com',NULL,NULL,'2017-12-31 01:10:01','2017-12-31 01:10:01'),(11,'David Rozak','rozakd@gmail.com',NULL,NULL,'2018-01-03 17:10:01','2018-01-03 17:10:01'),(17,'Fernando Rivas','puppeteer_2501@yahoo.com.ar',NULL,NULL,'2018-01-09 06:07:27','2018-01-09 06:07:27'),(18,'SimonDob','wielogorski727@gmail.com','$2y$10$9L53rcsZLjvUStasB17qluBlvml94PT6ZSLUPE3jrVIsMdSW7mOTG',NULL,'2018-01-09 08:15:33','2018-01-09 08:15:33'),(19,'David Rozak','david.rozak@darwinriver.com',NULL,NULL,'2018-01-09 17:50:01','2018-01-09 17:50:01');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2018-02-08 19:39:44
