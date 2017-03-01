<?php

$url = 'http://owncloud.loc/index.php/apps/sensorlogger/api/v1/createlog/';

$array = array("deviceId" => "9999-1111-2222-333",
	"temperature" => "18.08",
	"humidity" => 23.89,
	"date" => date('Y-m-d H:i:s'));
$data_json = json_encode($array);

$username = 'admin';
$token = 'RQBDK-NOSIQ-XENJE-XAKRJ';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $token);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS,$data_json);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response  = curl_exec($ch);
curl_close($ch);
