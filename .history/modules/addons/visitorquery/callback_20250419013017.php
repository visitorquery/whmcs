<?php

// Add CORS headers to allow all origins
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	header('HTTP/1.1 200 OK');
	exit;
}

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
$project = Capsule::table('mod_visitorquery_projects')
	->where('project_id', $moduleConfig["projectId"])
	->first();

if (!$project) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Invalid project ID']);
	exit;
}

// @TODO: Validate the request has required headers!!!!

// Get input data (supports both JSON and form POST)
$requestBody = file_get_contents('php://input');
$postData = json_decode($requestBody, true);

throw new Exception(print_r($postData, true));

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
	echo json_encode($postData);
	exit;
}

if (!isset($postData['data']['ip_address'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data: data.ip']);
	exit;
}

if (!isset($postData['data']['id'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data: data.id']);
	exit;
}

if (!isset($postData['data']['client_session_id'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data: data.client_session_id']);
	exit;
}

if (!isset($postData['data']['backend_session_id'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data: data.backend_session_id']);
	exit;
}

if (!isset($postData['data']['confidence'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Missing required data']);
	exit;
}

if (!isset($postData['data']['confidence']['bot'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Bot confidence missing']);
	exit;
}

if (!isset($postData['data']['confidence']['proxy_vpn'])) {
	header('HTTP/1.1 400 Bad Request');
	echo json_encode(['status' => 'error', 'message' => 'Proxy/VPN confidence missing']);
	exit;
}

// Process the detection
try {
	$bsidSplit = explode(':', $postData['data']['backend_session_id']);
	$bsid = $sidSplit[0];

	$uid = '';
	if (count($sidSplit) > 1) {
		$uid = $sidSplit[1];
	}

	// Insert the detection into the database
	Capsule::table('mod_visitorquery_detections')->insert([
		'ip_address' => $postData['data']['ip_address'],
		'detect_id' => $postData['data']['id'],
		'user_id' => $uid,
		'backend_session_id' => $bsid,
		'client_session_id' => $postData['data']['client_session_id'],
		'confidence_bot' => (float)$postData['data']['confidence']['bot'],
		'confidence_proxy_vpn' => (float)$postData['data']['confidence']['proxy_vpn'],
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