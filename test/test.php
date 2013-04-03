<?php 
require(__DIR__ . '/../class/apns.php');

$ao1 = new spSimpleAPNS(__DIR__ . '/apple.apns.1.pem');
$ao2 = new spSimpleAPNS(__DIR__ . '/apple.apns.2.pem');

$token = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$msg1 = new spAPNSMessage(array(
	'aps'	=>	array('alert'=>	array('body'=>'test1=123')),
));
$msg2 = new spAPNSMessage(array(
	'aps'	=>	array('alert'=>	array('body'=>'test2-234')),
));
$i = 0;
$t = microtime(1);
do {
	$ret = $ao1->push($msg1, $token);
	var_dump($ret);
	echo microtime(1) - $t, "\n";
	$ret = $ao2->push($msg2, $token);
	var_dump($ret);
	echo microtime(1) - $t, "\n";
} while(++$i < 3);
