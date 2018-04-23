# MySQLSessionHandler
PHP(7.1) SessionHandler interface using MySQLi

# Requirements
PHP >= v7.1

# MySQL Table definition

for PHP(64-bit)...

CREATE TABLE `sessions` (
  `session_id` char(48) NOT NULL,
  `init_unixtime` bigint(20) unsigned NOT NULL,
  `last_request_unixtime` bigint(20) unsigned NOT NULL,
  `expire_unixtime` bigint(20) unsigned DEFAULT NULL,
  `request_signature` varchar(96) DEFAULT NULL,
  `writes` int(4) unsigned DEFAULT NULL,
  `data` text,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `session_id_UNIQUE` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
