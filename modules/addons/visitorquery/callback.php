<?php

// Initialize WHMCS if not already done
use WHMCS\Database\Capsule;

// Check if this is being called from within WHMCS
if (!defined("WHMCS")) {
	define("WHMCS", true);

	// Calculate path to WHMCS root
	$depth = 0;
	$path = dirname(__FILE__);
	while (!file_exists($path . '/init.php') && $depth < 10) {
		$path = dirname($path);
		$depth++;
	}

	// Include necessary WHMCS files
	require_once $path . '/init.php';
}

$moduleConfig = getModuleConfigOptions('visitorquery');
$privateApiKey = !empty($moduleConfig['privAK']) ? $moduleConfig['privAK'] : '';

// Get input data (supports both JSON and form POST)
$requestBody = file_get_contents('php://input');
if (!empty($requestBody)) {
	$postData = json_decode($requestBody, true);
} else {
	$postData = $_POST;
}

// Log the incoming webhook
logModuleCall(
	'visitorquery',
	'Incoming Webhook',
	$requestBody,
	print_r($postData, true)
);

// Validate the request has required data
if (empty($postData) || !isset($postData['type'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data: type', 'posted' => $postData]);
	exit;
}

if (!isset($postData['data'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data: data']);
	exit;
}

if (!isset($postData['data']['ip'])) {
	header('HTTP/1.1 401 Unauthorized');
	echo json_encode(['status' => 'error', 'message' => 'Invalid authentication: data.ip']);
	exit;
}

if (!isset($postData['data']['id'])) {
	header('HTTP/1.1 401 Unauthorized');
	echo json_encode(['status' => 'error', 'message' => 'Invalid authentication: data.id']);
	exit;
}

if (!isset($postData['data']['session_id'])) {
	header('HTTP/1.1 401 Unauthorized');
	echo json_encode(['status' => 'error', 'message' => 'Invalid authentication']);
	exit;
}

if (!isset($postData['data']['confidence'])) {
	header('HTTP/1.1 401 Unauthorized');
	echo json_encode(['status' => 'error', 'message' => 'Invalid authentication']);
	exit;
}

// Process the detection
try {
	// Insert the detection into the database
	Capsule::table('mod_visitorquery_detections')->insert([
		'ip_address' => $postData['data']['ip'],
		'detect_id' => $postData['data']['id'],
		'session_id' => $postData['data']['session_id'],
		'confidence' => (float)$postData['data']['confidence'],
		'created_at' => date('Y-m-d H:i:s')
	]);

	// Respond with success
	header('Content-Type: application/json');
	echo json_encode(['status' => 'success']);
} catch (Exception $e) {
	// Log the error
	logModuleCall(
		'visitorquery',
		'Webhook Error',
		$postData,
		$e->getMessage()
	);

	// Respond with error
	header('HTTP/1.1 500 Internal Server Error');
	echo json_encode(['status' => 'error', 'message' => 'Failed to process detection']);
}