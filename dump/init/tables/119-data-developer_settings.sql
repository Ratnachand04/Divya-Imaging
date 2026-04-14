-- Data dump for table 'developer_settings'
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS=0;
START TRANSACTION;

INSERT IGNORE INTO `developer_settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'public_ip', ''),
(2, 'local_ip', ''),
(3, 'last_ip_check', ''),
(4, 'ip_check_interval', '300'),
(5, 'last_reload_time', ''),
(6, 'ip_last_error', ''),
(7, 'ip_change_log', ''),
(8, 'port_scan_results', ''),
(9, 'last_port_scan', ''),
(10, 'public_ip_diagnostics', '');

COMMIT;
SET FOREIGN_KEY_CHECKS=1;
