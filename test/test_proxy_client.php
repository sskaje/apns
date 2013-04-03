<?php
/**
 * Client test script for proxy
 */

require(__DIR__ . '/../class/proxy_client.class.php');

$server_url = 'http://apns.rst.im/api.php';
$provider = 'yida';
$auth_user = 'sskaje';
$auth_pass = 'zzddff';
$token = 'xxx';

$client = new spAPNSProxyClient(
    $server_url,
    $provider,
    $auth_user,
    $auth_pass
);

$ret = $client->pushOne($token, 'hello ' . mt_rand(1, 100));

var_dump($ret);
# EOF