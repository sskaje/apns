<?php
/**
 * Http api
 *
 * @author sskaje
 */
require(__DIR__ . '/class/apns.inc.php');

$ini_path = __DIR__ . '/proxy.example.ini';

$config = new spAPNSProxyConfig($ini_path, true);
$apns = new spAPNSProxy($config);
$apns->api();

# EOF