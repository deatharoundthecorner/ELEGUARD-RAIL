-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: eleguard_rail
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) DEFAULT NULL,
  `section_id` int(11) NOT NULL,
  `alert_level` enum('Low','Medium','High','Critical') NOT NULL,
  `alert_message` text NOT NULL,
  `alert_status` enum('Active','Resolved','False Alert') DEFAULT 'Active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`),
  KEY `section_id` (`section_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `elephant_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `railway_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alerts`
--

LOCK TABLES `alerts` WRITE;
/*!40000 ALTER TABLE `alerts` DISABLE KEYS */;
INSERT INTO `alerts` VALUES (1,3,1,'Critical','COLLISION RISK: Large bull elephant reported directly on the track at Habarana bend near forest temple.','Active',2,'2026-11-08 02:07:21',NULL),(2,NULL,2,'High','SIGHTING WARNING: Elephant movement detected near Minneriya crossing marker 102. Reduce speed and watch tracks.','Active',2,'2026-11-08 01:32:21',NULL),(3,NULL,4,'Low','PRECAUTIONARY ALERT: General elephant movement reported in Batticaloa Line Forest Section. Be vigilant.','Resolved',2,'2026-11-07 21:32:21','2026-11-08 05:02:21'),(4,5,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 02:40:44',NULL),(5,8,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 02:41:26',NULL),(6,11,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 03:12:44',NULL),(7,14,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 03:21:57',NULL),(8,17,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 03:41:29',NULL),(9,20,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 03:58:56',NULL),(10,23,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 04:12:57',NULL),(11,26,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 05:04:57',NULL),(12,29,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 06:16:05',NULL),(13,32,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 07:06:34',NULL),(14,39,2,'High','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 07:12:14',NULL),(15,NULL,2,'Critical','COLLISION HAZARD: Sighting of 3 elephant(s) at Near Minneriya Crossing Marker 105 (GPS: 8.04125000, 80.82564000).','Active',2,'2026-11-08 15:29:49',NULL);
/*!40000 ALTER TABLE `alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `driver_acknowledgements`
--

DROP TABLE IF EXISTS `driver_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `driver_acknowledgements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `action_status` enum('Acknowledged','Slowing Down','Stopped','False Alert') NOT NULL,
  `note` text DEFAULT NULL,
  `acknowledged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `alert_id` (`alert_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `driver_acknowledgements_ibfk_1` FOREIGN KEY (`alert_id`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `driver_acknowledgements_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `driver_acknowledgements`
--

LOCK TABLES `driver_acknowledgements` WRITE;
/*!40000 ALTER TABLE `driver_acknowledgements` DISABLE KEYS */;
INSERT INTO `driver_acknowledgements` VALUES (1,1,3,'Slowing Down','Proceeding with caution under 15km/h. Alert logged.','2026-11-08 02:17:21'),(2,3,3,'Acknowledged','Noted. Section clear upon transit.','2026-11-07 22:32:21'),(3,5,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 02:41:26'),(4,6,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 03:12:44'),(5,7,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 03:21:57'),(6,8,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 03:41:29'),(7,9,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 03:58:56'),(8,10,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 04:12:57'),(9,11,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 05:04:57'),(10,12,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 06:16:05'),(11,13,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 07:06:34'),(12,14,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 07:12:14'),(13,15,3,'Slowing Down','Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.','2026-11-08 15:29:49');
/*!40000 ALTER TABLE `driver_acknowledgements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `elephant_reports`
--

DROP TABLE IF EXISTS `elephant_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `elephant_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `reported_by` int(11) NOT NULL,
  `elephant_count` int(11) NOT NULL,
  `location_description` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `gps_accuracy_meters` decimal(8,2) DEFAULT NULL,
  `urgency_level` enum('Low','Medium','High','Critical') NOT NULL,
  `risk_score` enum('Low','Medium','High','Critical') DEFAULT 'Low',
  `report_status` enum('Pending','Verified','Rejected','Converted to Alert','Resolved') DEFAULT 'Pending',
  `description` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `ai_status` enum('pending','processed','skipped','failed') DEFAULT 'pending',
  `ai_detected_elephant` tinyint(1) DEFAULT NULL,
  `ai_confidence` decimal(5,4) DEFAULT NULL,
  `ai_estimated_count` int(11) DEFAULT NULL,
  `ai_model_version` varchar(50) DEFAULT NULL,
  `ai_detection_notes` text DEFAULT NULL,
  `ai_processed_at` datetime DEFAULT NULL,
  `is_potential_duplicate` tinyint(1) DEFAULT 0,
  `duplicate_cluster_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`),
  KEY `reported_by` (`reported_by`),
  KEY `idx_elephant_reports_coords` (`latitude`,`longitude`),
  KEY `idx_elephant_reports_ai_status` (`ai_status`),
  KEY `idx_elephant_reports_duplicate` (`is_potential_duplicate`,`duplicate_cluster_id`),
  CONSTRAINT `elephant_reports_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `railway_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `elephant_reports_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `elephant_reports`
--

LOCK TABLES `elephant_reports` WRITE;
/*!40000 ALTER TABLE `elephant_reports` DISABLE KEYS */;
INSERT INTO `elephant_reports` VALUES (1,3,5,2,'Near Mile Post 85, close to the small lake',NULL,NULL,NULL,'Medium','Medium','Pending','Two adult elephants spotted drinking water near the tracks.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 01:32:21',NULL),(2,2,4,5,'Minneriya Crossing marker 102',NULL,NULL,NULL,'High','High','Verified','Herd of 5 elephants including a calf moving slowly towards the railway track.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 00:32:21',NULL),(3,1,5,1,'Habarana bend near forest temple',NULL,NULL,NULL,'Critical','Critical','Converted to Alert','Large bull elephant standing directly on the tracks.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:02:21',NULL),(4,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:40:44',NULL),(5,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:40:44','2026-11-08 08:10:44'),(6,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:40:44',NULL),(7,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:41:26',NULL),(8,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:41:26','2026-11-08 08:11:26'),(9,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 02:41:26',NULL),(10,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:12:44',NULL),(11,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:12:44','2026-11-08 08:42:44'),(12,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:12:44',NULL),(13,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:21:57',NULL),(14,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:21:57','2026-11-08 08:51:57'),(15,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:21:57',NULL),(16,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:41:29',NULL),(17,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:41:29','2026-11-08 09:11:29'),(18,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:41:29',NULL),(19,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:58:56',NULL),(20,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:58:56','2026-11-08 09:28:56'),(21,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 03:58:56',NULL),(22,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 04:12:57',NULL),(23,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 04:12:57','2026-11-08 09:42:57'),(24,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 04:12:57',NULL),(25,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 05:04:57',NULL),(26,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 05:04:57','2026-11-08 10:34:57'),(27,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 05:04:57',NULL),(28,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 06:16:05',NULL),(29,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 06:16:05','2026-11-08 11:46:05'),(30,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 06:16:05',NULL),(31,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 07:06:34',NULL),(32,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 07:06:34','2026-11-08 12:36:34'),(33,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 07:06:34',NULL),(38,1,5,2,'Near Curve 44 (Text Only)',NULL,NULL,NULL,'Medium','High','Pending','Testing text-only submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 07:12:14',NULL),(39,2,5,3,'Near Minneriya Crossing Marker 105',8.04125000,80.82564000,14.50,'High','High','Converted to Alert','Testing GPS-enabled submission.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 07:12:14','2026-11-08 12:42:14'),(40,1,5,1,'Gal Oya Manual Coordinates',8.12560000,80.91240000,NULL,'Low','High','Pending','Testing manual coordinate entry.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 07:12:14',NULL),(41,1,4,3,'Habarana Corridor Milepost 127',8.03840000,80.75520000,12.00,'High','Critical','Pending','Herd of three elephants observed moving toward railway embankment.','assets/uploads/reports/report_habarana_herd.jpg','processed',1,0.9197,3,'YOLOX-Tiny-ONNX-v1.0-COCO','[Potential Elephant Detected] AI detected 3 potential elephant(s) with highest confidence of 92.0%. Advisory suggestion for wildlife officer review.','2026-11-08 12:43:24',0,NULL,'2026-11-08 02:43:23',NULL),(42,2,4,1,'Minneriya Track Kilometer 214',8.04100000,80.82600000,15.00,'Low','Medium','Pending','Routine track inspection photograph; clear corridor.','assets/uploads/reports/report_ambewela_track.jpg','processed',0,0.0004,0,'YOLOX-Tiny-ONNX-v1.0-COCO','[No Elephant Detected] No elephant detected in image (highest match 0.0% below threshold). Advisory suggestion only.','2026-11-08 12:43:25',0,NULL,'2026-11-08 02:43:24',NULL),(43,1,4,1,'Gal Oya Forest Edge Dusk',8.08200000,80.89100000,25.00,'Medium','High','Pending','Distant obscured shape near tree line at twilight.','assets/uploads/reports/report_minneriya_dusk.jpg','processed',0,0.3767,0,'YOLOX-Tiny-ONNX-v1.0-COCO','[Uncertain Result] Low-confidence feature match (37.7%), possibly degraded, distant, or ambiguous image. Wildlife officer manual inspection required.','2026-11-08 12:43:26',0,NULL,'2026-11-08 02:43:25',NULL),(53,1,5,3,'E2E-AUDIT-ELEPHANT-1794150352',8.03500000,80.75200000,10.00,'High','Critical','Verified','Herd of elephants detected crossing railway embankment.','assets/uploads/reports/report_1794150352_a0c4b53089bea85f.jpg','processed',1,0.9197,3,'YOLOX-Tiny-ONNX-v1.0-COCO','[Potential Elephant Detected] AI detected 3 potential elephant(s) with highest confidence of 92.0%. Advisory suggestion for wildlife officer review.','2026-11-08 20:35:52',0,NULL,'2026-11-08 15:05:52','2026-11-08 20:35:54'),(54,2,5,1,'E2E-AUDIT-TRACK-1794150352',8.03500000,80.75200000,10.00,'Low','High','Rejected','Track corridor inspection; clear railway track.','assets/uploads/reports/report_1794150352_a766ca5326de38e0.jpg','processed',0,0.0004,0,'YOLOX-Tiny-ONNX-v1.0-COCO','[No Elephant Detected] No elephant detected in image (highest match 0.0% below threshold). Advisory suggestion only.','2026-11-08 20:35:53',0,NULL,'2026-11-08 15:05:52','2026-11-08 20:35:54'),(55,1,5,1,'E2E-AUDIT-UNCERTAIN-1794150352',8.03500000,80.75200000,10.00,'Medium','Critical','Pending','Distant ambiguous shape at forest boundary.','assets/uploads/reports/report_1794150353_00534b9dfcdcb982.jpg','processed',0,0.3767,0,'YOLOX-Tiny-ONNX-v1.0-COCO','[Uncertain Result] Low-confidence feature match (37.7%), possibly degraded, distant, or ambiguous image. Wildlife officer manual inspection required.','2026-11-08 20:35:53',0,NULL,'2026-11-08 15:05:53',NULL),(56,3,5,2,'E2E-AUDIT-TEXTONLY-1794150352',8.03500000,80.75200000,10.00,'Low','Medium','Pending','Verbal report received from local resident; no photograph.',NULL,'skipped',NULL,NULL,NULL,NULL,'No photograph attached to report.',NULL,0,NULL,'2026-11-08 15:05:53',NULL);
/*!40000 ALTER TABLE `elephant_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hotspots`
--

DROP TABLE IF EXISTS `hotspots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hotspots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `hotspot_name` varchar(150) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `risk_level` enum('Low','Medium','High','Critical') NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`),
  CONSTRAINT `hotspots_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `railway_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hotspots`
--

LOCK TABLES `hotspots` WRITE;
/*!40000 ALTER TABLE `hotspots` DISABLE KEYS */;
INSERT INTO `hotspots` VALUES (1,2,'Minneriya Elephant Crossing',8.04120000,80.82560000,'Critical','Elephants crossing from national park to reservoir during seasonal migrations.','2026-11-08 02:32:21'),(2,1,'Habarana Forest Edge',8.03540000,80.75110000,'High','Thick vegetation close to tracks limiting driver visibility to less than 50 meters.','2026-11-08 02:32:21'),(3,1,'Gal Oya Corridor',8.12560000,80.91240000,'High','Active path linking two major forest reserves.','2026-11-08 02:32:21'),(4,3,'Night Movement Zone 01',7.98540000,80.32140000,'Medium','Water bodies on south side force night-time crossings during dry periods.','2026-11-08 02:32:21');
/*!40000 ALTER TABLE `hotspots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `incident_history`
--

DROP TABLE IF EXISTS `incident_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `incident_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `incident_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `incident_date` date NOT NULL,
  `severity` enum('Low','Medium','High','Critical') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`),
  CONSTRAINT `incident_history_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `railway_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `incident_history`
--

LOCK TABLES `incident_history` WRITE;
/*!40000 ALTER TABLE `incident_history` DISABLE KEYS */;
INSERT INTO `incident_history` VALUES (1,1,'Elephant Sighting Collision','Udayadevi Express collided with a mature elephant cow. Severe engine damage, elephant passed away.','2026-03-12','Critical','2026-08-10 02:32:21'),(2,2,'Near Miss Sighting','Night mail train managed to stop 10 meters before a herd of 3 elephants after being warned by forest guard torches.','2026-05-02','Medium','2026-09-19 02:32:21'),(3,3,'False Alarm Sighting','Report of an elephant on the tracks turned out to be a large water buffalo grazing near the fence.','2026-06-10','Low','2026-10-28 02:32:21');
/*!40000 ALTER TABLE `incident_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `railway_sections`
--

DROP TABLE IF EXISTS `railway_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `railway_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_name` varchar(150) NOT NULL,
  `district` varchar(100) NOT NULL,
  `start_location` varchar(150) NOT NULL,
  `end_location` varchar(150) NOT NULL,
  `risk_level` enum('Low','Medium','High','Critical') NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `railway_sections`
--

LOCK TABLES `railway_sections` WRITE;
/*!40000 ALTER TABLE `railway_sections` DISABLE KEYS */;
INSERT INTO `railway_sections` VALUES (1,'Habarana - Gal Oya','Anuradhapura/Polonnaruwa','Habarana Station','Gal Oya Junction','Critical','Highly active elephant crossing corridor with dense jungle cover and several blind curves.','2026-11-08 02:32:21'),(2,'Minneriya - Hingurakgoda','Polonnaruwa','Minneriya Station','Hingurakgoda Station','High','Near Minneriya National Park reservoir. Frequent elephant movement during dry season (July-October).','2026-11-08 02:32:21'),(3,'Maho - Anuradhapura','Kurunegala/Anuradhapura','Maho Junction','Anuradhapura Station','Medium','Flat plains with scattered rural farmlands. Elephants occasionally cross for water.','2026-11-08 02:32:21'),(4,'Batticaloa Line Forest Section','Batticaloa','Welikanda Station','Valachchenai Station','High','Traverses through national sanctuary borders. Multiple elephant herds roam nearby.','2026-11-08 02:32:21');
/*!40000 ALTER TABLE `railway_sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_logs`
--

DROP TABLE IF EXISTS `system_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=186 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_logs`
--

LOCK TABLES `system_logs` WRITE;
/*!40000 ALTER TABLE `system_logs` DISABLE KEYS */;
INSERT INTO `system_logs` VALUES (1,1,'Database Init','Initial database schema and seed data loaded successfully.','2026-10-29 02:32:21'),(2,5,'User Login','Reporter successfully logged in from Web UI.','2026-11-08 01:32:21'),(3,4,'Report Verified','Wildlife Officer verified elephant report #2.','2026-11-08 01:42:21'),(4,2,'Alert Created','Railway Controller converted report #3 into Critical Alert #1.','2026-11-08 02:07:21'),(5,1,'User Login','User successfully logged in.','2026-11-08 02:40:44'),(6,1,'User Logout','User logged out.','2026-11-08 02:40:44'),(7,2,'User Login','User successfully logged in.','2026-11-08 02:40:44'),(8,2,'User Logout','User logged out.','2026-11-08 02:40:44'),(9,3,'User Login','User successfully logged in.','2026-11-08 02:40:44'),(10,3,'User Logout','User logged out.','2026-11-08 02:40:44'),(11,4,'User Login','User successfully logged in.','2026-11-08 02:40:44'),(12,4,'User Logout','User logged out.','2026-11-08 02:40:44'),(13,5,'User Login','User successfully logged in.','2026-11-08 02:40:44'),(14,5,'User Logout','User logged out.','2026-11-08 02:40:44'),(15,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 02:40:44'),(16,4,'Report Updated','Report #5 status changed to \'Verified\'.','2026-11-08 02:40:44'),(17,2,'Alert Created','Alert #4 (High) created for railway section #2.','2026-11-08 02:40:44'),(18,1,'User Login','User successfully logged in.','2026-11-08 02:41:26'),(19,2,'User Login','User successfully logged in.','2026-11-08 02:41:26'),(20,3,'User Login','User successfully logged in.','2026-11-08 02:41:26'),(21,4,'User Login','User successfully logged in.','2026-11-08 02:41:26'),(22,5,'User Login','User successfully logged in.','2026-11-08 02:41:26'),(23,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 02:41:26'),(24,4,'Report Updated','Report #8 status changed to \'Verified\'.','2026-11-08 02:41:26'),(25,2,'Alert Created','Alert #5 (High) created for railway section #2.','2026-11-08 02:41:26'),(26,3,'Driver Acknowledged Alert','Driver acknowledged Alert #5 as \'Slowing Down\'.','2026-11-08 02:41:26'),(27,1,'User Login','User successfully logged in.','2026-11-08 03:12:43'),(28,2,'User Login','User successfully logged in.','2026-11-08 03:12:44'),(29,3,'User Login','User successfully logged in.','2026-11-08 03:12:44'),(30,4,'User Login','User successfully logged in.','2026-11-08 03:12:44'),(31,5,'User Login','User successfully logged in.','2026-11-08 03:12:44'),(32,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 03:12:44'),(33,4,'Report Updated','Report #11 status changed to \'Verified\'.','2026-11-08 03:12:44'),(34,2,'Alert Created','Alert #6 (High) created for railway section #2.','2026-11-08 03:12:44'),(35,3,'Driver Acknowledged Alert','Driver acknowledged Alert #6 as \'Slowing Down\'.','2026-11-08 03:12:44'),(36,1,'User Login','User successfully logged in.','2026-11-08 03:21:57'),(37,2,'User Login','User successfully logged in.','2026-11-08 03:21:57'),(38,3,'User Login','User successfully logged in.','2026-11-08 03:21:57'),(39,4,'User Login','User successfully logged in.','2026-11-08 03:21:57'),(40,5,'User Login','User successfully logged in.','2026-11-08 03:21:57'),(41,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 03:21:57'),(42,4,'Report Updated','Report #14 status changed to \'Verified\'.','2026-11-08 03:21:57'),(43,2,'Alert Created','Alert #7 (High) created for railway section #2.','2026-11-08 03:21:57'),(44,3,'Driver Acknowledged Alert','Driver acknowledged Alert #7 as \'Slowing Down\'.','2026-11-08 03:21:57'),(45,1,'User Login','User successfully logged in.','2026-11-08 03:32:40'),(46,4,'User Login','User successfully logged in.','2026-11-08 03:32:42'),(47,1,'User Login','User successfully logged in.','2026-11-08 03:35:00'),(48,4,'User Login','User successfully logged in.','2026-11-08 03:35:00'),(49,2,'User Login','User successfully logged in.','2026-11-08 03:35:00'),(50,3,'User Login','User successfully logged in.','2026-11-08 03:35:00'),(51,5,'User Login','User successfully logged in.','2026-11-08 03:35:01'),(52,1,'User Login','User successfully logged in.','2026-11-08 03:36:23'),(53,1,'User Login','User successfully logged in.','2026-11-08 03:37:32'),(54,3,'User Login','User successfully logged in.','2026-11-08 03:40:48'),(55,5,'User Login','User successfully logged in.','2026-11-08 03:40:48'),(56,4,'User Login','User successfully logged in.','2026-11-08 03:40:48'),(57,2,'User Login','User successfully logged in.','2026-11-08 03:40:48'),(58,1,'User Login','User successfully logged in.','2026-11-08 03:40:48'),(59,1,'User Login','User successfully logged in.','2026-11-08 03:41:28'),(60,2,'User Login','User successfully logged in.','2026-11-08 03:41:28'),(61,3,'User Login','User successfully logged in.','2026-11-08 03:41:28'),(62,4,'User Login','User successfully logged in.','2026-11-08 03:41:28'),(63,5,'User Login','User successfully logged in.','2026-11-08 03:41:29'),(64,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 03:41:29'),(65,4,'Report Updated','Report #17 status changed to \'Verified\'.','2026-11-08 03:41:29'),(66,2,'Alert Created','Alert #8 (High) created for railway section #2.','2026-11-08 03:41:29'),(67,3,'Driver Acknowledged Alert','Driver acknowledged Alert #8 as \'Slowing Down\'.','2026-11-08 03:41:29'),(68,1,'User Login','User successfully logged in.','2026-11-08 03:58:55'),(69,2,'User Login','User successfully logged in.','2026-11-08 03:58:55'),(70,3,'User Login','User successfully logged in.','2026-11-08 03:58:55'),(71,4,'User Login','User successfully logged in.','2026-11-08 03:58:55'),(72,5,'User Login','User successfully logged in.','2026-11-08 03:58:56'),(73,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 03:58:56'),(74,4,'Report Updated','Report #20 status changed to \'Verified\'.','2026-11-08 03:58:56'),(75,2,'Alert Created','Alert #9 (High) created for railway section #2.','2026-11-08 03:58:56'),(76,3,'Driver Acknowledged Alert','Driver acknowledged Alert #9 as \'Slowing Down\'.','2026-11-08 03:58:56'),(77,1,'User Login','User successfully logged in.','2026-11-08 04:12:56'),(78,2,'User Login','User successfully logged in.','2026-11-08 04:12:56'),(79,3,'User Login','User successfully logged in.','2026-11-08 04:12:56'),(80,4,'User Login','User successfully logged in.','2026-11-08 04:12:56'),(81,5,'User Login','User successfully logged in.','2026-11-08 04:12:57'),(82,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 04:12:57'),(83,4,'Report Updated','Report #23 status changed to \'Verified\'.','2026-11-08 04:12:57'),(84,2,'Alert Created','Alert #10 (High) created for railway section #2.','2026-11-08 04:12:57'),(85,3,'Driver Acknowledged Alert','Driver acknowledged Alert #10 as \'Slowing Down\'.','2026-11-08 04:12:57'),(86,1,'User Login','User successfully logged in.','2026-11-08 05:04:57'),(87,2,'User Login','User successfully logged in.','2026-11-08 05:04:57'),(88,3,'User Login','User successfully logged in.','2026-11-08 05:04:57'),(89,4,'User Login','User successfully logged in.','2026-11-08 05:04:57'),(90,5,'User Login','User successfully logged in.','2026-11-08 05:04:57'),(91,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 05:04:57'),(92,4,'Report Updated','Report #26 status changed to \'Verified\'.','2026-11-08 05:04:57'),(93,2,'Alert Created','Alert #11 (High) created for railway section #2.','2026-11-08 05:04:57'),(94,3,'Driver Acknowledged Alert','Driver acknowledged Alert #11 as \'Slowing Down\'.','2026-11-08 05:04:57'),(95,1,'User Login','User successfully logged in.','2026-11-08 06:16:05'),(96,2,'User Login','User successfully logged in.','2026-11-08 06:16:05'),(97,3,'User Login','User successfully logged in.','2026-11-08 06:16:05'),(98,4,'User Login','User successfully logged in.','2026-11-08 06:16:05'),(99,5,'User Login','User successfully logged in.','2026-11-08 06:16:05'),(100,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 06:16:05'),(101,4,'Report Updated','Report #29 status changed to \'Verified\'.','2026-11-08 06:16:05'),(102,2,'Alert Created','Alert #12 (High) created for railway section #2.','2026-11-08 06:16:05'),(103,3,'Driver Acknowledged Alert','Driver acknowledged Alert #12 as \'Slowing Down\'.','2026-11-08 06:16:05'),(104,1,'User Login','User successfully logged in.','2026-11-08 07:06:33'),(105,2,'User Login','User successfully logged in.','2026-11-08 07:06:33'),(106,3,'User Login','User successfully logged in.','2026-11-08 07:06:33'),(107,4,'User Login','User successfully logged in.','2026-11-08 07:06:33'),(108,5,'User Login','User successfully logged in.','2026-11-08 07:06:34'),(109,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 07:06:34'),(110,4,'Report Updated','Report #32 status changed to \'Verified\'.','2026-11-08 07:06:34'),(111,2,'Alert Created','Alert #13 (High) created for railway section #2.','2026-11-08 07:06:34'),(112,3,'Driver Acknowledged Alert','Driver acknowledged Alert #13 as \'Slowing Down\'.','2026-11-08 07:06:34'),(113,4,'Report Updated','Report #34 status changed to \'Verified\'.','2026-11-08 07:11:41'),(114,4,'Report Updated','Report #34 status changed to \'Rejected\'.','2026-11-08 07:11:41'),(115,1,'User Login','User successfully logged in.','2026-11-08 07:12:14'),(116,2,'User Login','User successfully logged in.','2026-11-08 07:12:14'),(117,3,'User Login','User successfully logged in.','2026-11-08 07:12:14'),(118,4,'User Login','User successfully logged in.','2026-11-08 07:12:14'),(119,5,'User Login','User successfully logged in.','2026-11-08 07:12:14'),(120,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 07:12:14'),(121,4,'Report Updated','Report #39 status changed to \'Verified\'.','2026-11-08 07:12:14'),(122,2,'Alert Created','Alert #14 (High) created for railway section #2.','2026-11-08 07:12:14'),(123,3,'Driver Acknowledged Alert','Driver acknowledged Alert #14 as \'Slowing Down\'.','2026-11-08 07:12:14'),(124,5,'User Login','User successfully logged in.','2026-11-08 14:55:41'),(125,5,'Sighting Submitted','Sighting reported in section #1 (3 elephants).','2026-11-08 14:55:43'),(126,5,'User Login','User successfully logged in.','2026-11-08 14:57:02'),(127,5,'Sighting Submitted','Sighting reported in section #1 (3 elephants).','2026-11-08 14:57:03'),(128,5,'Sighting Submitted','Sighting reported in section #2 (1 elephants).','2026-11-08 14:57:03'),(129,5,'Sighting Submitted','Sighting reported in section #1 (1 elephants).','2026-11-08 14:57:04'),(130,5,'Sighting Submitted','Sighting reported in section #3 (2 elephants).','2026-11-08 14:57:04'),(131,4,'User Login','User successfully logged in.','2026-11-08 14:57:04'),(132,4,'Report Updated','Report #45 status changed to \'Verified\'.','2026-11-08 14:57:04'),(133,4,'User Login','User successfully logged in.','2026-11-08 14:59:27'),(134,4,'Report Updated','Report #45 status changed to \'Verified\'.','2026-11-08 14:59:27'),(135,4,'Report Updated','Report #46 status changed to \'Verified\'.','2026-11-08 15:00:54'),(136,4,'User Login','User successfully logged in.','2026-11-08 15:02:25'),(137,4,'User Login','User successfully logged in.','2026-11-08 15:03:21'),(138,4,'Report Updated','Report #47 status changed to \'Verified\'.','2026-11-08 15:03:21'),(139,5,'User Login','User successfully logged in.','2026-11-08 15:05:10'),(140,5,'Sighting Submitted','Sighting reported in section #1 (3 elephants).','2026-11-08 15:05:10'),(141,5,'Sighting Submitted','Sighting reported in section #2 (1 elephants).','2026-11-08 15:05:11'),(142,5,'Sighting Submitted','Sighting reported in section #1 (1 elephants).','2026-11-08 15:05:12'),(143,5,'Sighting Submitted','Sighting reported in section #3 (2 elephants).','2026-11-08 15:05:12'),(144,4,'User Login','User successfully logged in.','2026-11-08 15:05:12'),(145,4,'Report Updated','Report #49 status changed to \'Verified\'.','2026-11-08 15:05:12'),(146,4,'Report Updated','Report #50 status changed to \'Rejected\'.','2026-11-08 15:05:12'),(147,5,'User Login','User successfully logged in.','2026-11-08 15:05:52'),(148,5,'Sighting Submitted','Sighting reported in section #1 (3 elephants).','2026-11-08 15:05:52'),(149,5,'Sighting Submitted','Sighting reported in section #2 (1 elephants).','2026-11-08 15:05:53'),(150,5,'Sighting Submitted','Sighting reported in section #1 (1 elephants).','2026-11-08 15:05:53'),(151,5,'Sighting Submitted','Sighting reported in section #3 (2 elephants).','2026-11-08 15:05:53'),(152,4,'User Login','User successfully logged in.','2026-11-08 15:05:53'),(153,4,'Report Updated','Report #53 status changed to \'Verified\'.','2026-11-08 15:05:54'),(154,4,'Report Updated','Report #54 status changed to \'Rejected\'.','2026-11-08 15:05:54'),(155,4,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 15:15:40'),(156,3,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 15:15:41'),(157,5,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 15:15:41'),(158,4,'User Login','User successfully logged in.','2026-11-08 15:17:47'),(159,3,'User Login','User successfully logged in.','2026-11-08 15:17:47'),(160,5,'User Login','User successfully logged in.','2026-11-08 15:17:47'),(161,4,'User Login','User successfully logged in.','2026-11-08 15:18:39'),(162,4,'User Login','User successfully logged in.','2026-11-08 15:19:01'),(163,5,'User Login','User successfully logged in.','2026-11-08 15:19:43'),(164,5,'User Login','User successfully logged in.','2026-11-08 15:20:13'),(165,4,'User Login','User successfully logged in.','2026-11-08 15:20:46'),(166,3,'User Login','User successfully logged in.','2026-11-08 15:20:46'),(167,5,'User Login','User successfully logged in.','2026-11-08 15:20:47'),(168,5,'Sighting Submitted','Sighting reported in section #1 (1 elephants).','2026-11-08 15:20:47'),(169,5,'User Login','User successfully logged in.','2026-11-08 15:21:10'),(170,5,'Sighting Submitted','Sighting reported in section #1 (2 elephants).','2026-11-08 15:21:10'),(171,5,'Sighting Submitted','Sighting reported in section #1 (2 elephants).','2026-11-08 15:21:11'),(172,5,'Sighting Submitted','Sighting reported in section #1 (2 elephants).','2026-11-08 15:21:11'),(173,5,'Sighting Submitted','Sighting reported in section #1 (2 elephants).','2026-11-08 15:21:12'),(174,5,'Sighting Submitted','Sighting reported in section #1 (2 elephants).','2026-11-08 15:21:12'),(175,1,'User Login','User successfully logged in.','2026-11-08 15:29:49'),(176,2,'User Login','User successfully logged in.','2026-11-08 15:29:49'),(177,3,'User Login','User successfully logged in.','2026-11-08 15:29:49'),(178,4,'User Login','User successfully logged in.','2026-11-08 15:29:49'),(179,5,'User Login','User successfully logged in.','2026-11-08 15:29:49'),(180,1,'Failed Login Attempt','Attempt with incorrect password.','2026-11-08 15:29:49'),(181,4,'Report Updated','Report #82 status changed to \'Verified\'.','2026-11-08 15:29:49'),(182,2,'Alert Created','Alert #15 (Critical) created for railway section #2.','2026-11-08 15:29:49'),(183,3,'Driver Acknowledged Alert','Driver acknowledged Alert #15 as \'Slowing Down\'.','2026-11-08 15:29:49'),(184,4,'Report Updated','Report #84 status changed to \'Verified\'.','2026-11-08 15:29:53'),(185,4,'Report Updated','Report #84 status changed to \'Rejected\'.','2026-11-08 15:29:53');
/*!40000 ALTER TABLE `system_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','control_officer','driver','wildlife_officer','reporter') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'System Admin','admin@eleguard.lk','$2y$10$lpY2SQj2iOosCP/7t5e6yeOBY7MqBAwa88y1JULHOXnqewSJpMZyG','admin','active','2026-11-08 02:31:48'),(2,'Railway Controller','control@eleguard.lk','$2y$10$kBkhQHGluT0Z1ztkBECxE.swld4PjkrT5mkuRYzug53Bl4tTY7Hsq','control_officer','active','2026-11-08 02:31:48'),(3,'Train Driver A','driver@eleguard.lk','$2y$10$vnN/I.y7o8K1S0RfXzHG9Ortoy5qO0a77ipUQi8PrzWwiZVwrrwwq','driver','active','2026-11-08 02:31:48'),(4,'Wildlife Ranger','wildlife@eleguard.lk','$2y$10$CMJdLm4xQ5td4tGgFRUW4ucVXSUzgAc5XS0z/.5/OrBlqI7LC6F6m','wildlife_officer','active','2026-11-08 02:31:48'),(5,'Villager Reporter','reporter@eleguard.lk','$2y$10$qUF0y9ta98GkXDMrTqGwyehv9zIVykiRptIgJn0rLgOEfV5de4dBW','reporter','active','2026-11-08 02:31:48');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'eleguard_rail'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-09  8:15:39
