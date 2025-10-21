-- Create credit usage table
CREATE TABLE IF NOT EXISTS `credit_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `amount` int(11) NOT NULL COMMENT 'Toman value entered by admin',
  `credit_value` decimal(10,1) NOT NULL COMMENT 'Amount divided by 5000, with one decimal place',
  `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_refund` tinyint(1) NOT NULL DEFAULT '0',
  `user_mobile` varchar(15) NOT NULL,
  `admin_mobile` varchar(15) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_mobile` (`user_mobile`),
  KEY `datetime` (`datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Records of credit usage by customers';