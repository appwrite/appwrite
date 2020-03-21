CREATE DATABASE IF NOT EXISTS `appwrite` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `appwrite`;

CREATE TABLE IF NOT EXISTS `template.abuse.abuse` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `_key` varchar(255) NOT NULL,
  `_time` int(11) NOT NULL,
  `_count` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique1` (`_key`,`_time`),
  KEY `index1` (`_key`,`_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `template.audit.audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` varchar(45) NOT NULL,
  `event` varchar(45) NOT NULL,
  `resource` varchar(45) DEFAULT NULL,
  `userAgent` text NOT NULL,
  `ip` varchar(45) NOT NULL,
  `location` varchar(45) DEFAULT NULL,
  `time` datetime NOT NULL,
  `data` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  KEY `index_1` (`userId`),
  KEY `index_2` (`event`),
  KEY `index_3` (`resource`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `template.database.documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for each node',
  `uid` varchar(45) DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT 0,
  `createdAt` datetime DEFAULT NULL,
  `updatedAt` datetime DEFAULT NULL,
  `signature` varchar(32) NOT NULL,
  `revision` varchar(45) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  UNIQUE KEY `index2` (`uid`),
  KEY `index3` (`signature`,`uid`,`revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `template.database.properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary Key',
  `documentUid` varchar(45) NOT NULL COMMENT 'Unique UID foreign key',
  `documentRevision` varchar(45) NOT NULL,
  `key` varchar(32) NOT NULL COMMENT 'Property key name',
  `value` text NOT NULL COMMENT 'Value of property',
  `primitive` varchar(32) NOT NULL COMMENT 'Primitive type of property value',
  `array` tinyint(4) NOT NULL DEFAULT 0,
  `order` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `index1` (`documentUid`),
  KEY `index2` (`key`,`value`(5)),
  FULLTEXT KEY `index3` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `template.database.relationships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `revision` varchar(45) NOT NULL,
  `start` varchar(45) NOT NULL COMMENT 'Unique UID foreign key',
  `end` varchar(45) NOT NULL COMMENT 'Unique UID foreign key',
  `key` varchar(256) NOT NULL,
  `path` int(11) NOT NULL DEFAULT 0,
  `array` tinyint(4) NOT NULL DEFAULT 0,
  `order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `relationships_start_nodes_id_idx` (`start`),
  KEY `relationships_end_nodes_id_idx` (`end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `template.database.unique` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `index1` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Default App */

CREATE TABLE IF NOT EXISTS `app_console.database.documents` LIKE `template.database.documents`;
CREATE TABLE IF NOT EXISTS `app_console.database.properties` LIKE `template.database.properties`;
CREATE TABLE IF NOT EXISTS `app_console.database.relationships` LIKE `template.database.relationships`;
CREATE TABLE IF NOT EXISTS `app_console.database.unique` LIKE `template.database.unique`;
CREATE TABLE IF NOT EXISTS `app_console.audit.audit` LIKE `template.audit.audit`;
CREATE TABLE IF NOT EXISTS `app_console.abuse.abuse` LIKE `template.abuse.abuse`;