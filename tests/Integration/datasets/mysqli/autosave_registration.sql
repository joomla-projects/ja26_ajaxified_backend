DROP TABLE IF EXISTS `#__extensions`;
CREATE TABLE `#__extensions` (
  `extension_id` int NOT NULL AUTO_INCREMENT,
  `package_id` int NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL,
  `element` varchar(100) NOT NULL,
  `folder` varchar(100) NOT NULL,
  `client_id` tinyint NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT 0,
  `access` int unsigned NOT NULL DEFAULT 1,
  `protected` tinyint NOT NULL DEFAULT 0,
  `locked` tinyint NOT NULL DEFAULT 0,
  `manifest_cache` text NOT NULL,
  `params` text NOT NULL,
  `custom_data` text NOT NULL,
  `ordering` int DEFAULT 0,
  `state` int DEFAULT 0,
  PRIMARY KEY (`extension_id`),
  KEY `extension` (`type`,`element`,`folder`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
