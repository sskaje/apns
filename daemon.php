<?php
/**
 * Daemon script
 * usage: '/path/to/php/binary /path/to/daemon.php'
 *
 * @author sskaje
 */
require(__DIR__ . '/class/proxy.class.php');

$ini_path = __DIR__ . '/proxy.example.ini';

$config = new spAPNSProxyConfig($ini_path, true);
$apns = new spAPNSProxy($config);

$apns->exec(0, 100000, 61);

# EOF