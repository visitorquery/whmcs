<?php

use WHMCS\Module\Gateway;
use Illuminate\Database\Capsule\Manager as Capsule;

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
	session_start();

	$userId = $_SESSION['uid'] ?? 0;
	$moduleConfig = getModuleConfigOptions('visitorquery');
	$publicApiKey = !empty($moduleConfig['pubAK']) ? $moduleConfig['pubAK'] : '';

	if ($publicApiKey == '') {
		return '';
	}

	$sessionId = session_id();

	$script = <<<EOT
<script src="https://cdn.visitorquery.com/visitorquery.js"></script>
<script type="text/javascript">
	window.addEventListener('load', () => {
		window.VisitorQuery.run({
			ApiKey   : "{$publicApiKey}",
			SessionId: "{$sessionId}:{$userId}",
		});
	});
</script>
EOT;

	return $script;
});

// Didn't find a better place to show the message than via js/jquery
add_hook('ShoppingCartCheckoutOutput', 1, function ($vars) {
	$sessionId = session_id();
	$moduleConfig = getModuleConfigOptions('visitorquery');
	$minConfidence = !empty($moduleConfig['minConfidence']) ? (float)$moduleConfig['minConfidence'] : 0.9;

	// Check if this IP is flagged in our detections table
	$detections = Capsule::table('mod_visitorquery_detections')
		->where('session_id', $sessionId)
		->get();

	$highestConfidence = 0;
	foreach ($detections as $detection) {
		if ($detection->confidence > $highestConfidence) {
			$highestConfidence = $detection->confidence;
		}
	}

	// If this IP is using a proxy/VPN according to our detection
	if ($highestConfidence >= $minConfidence) {
		if (configFlagIsOn($moduleConfig, 'skip_on_returning')) {
			// Check if this user has previous orders
			$previousOrdersCount = getUserPreviousOrdersCount();

			if ($previousOrdersCount > 0) {
				logModuleCall(
					'visitorquery', 'Check skipped', '', 'User has previous orders'
				);
				return '';
			}
		}

		if (isset($moduleConfig['show_message']) && $moduleConfig['show_message'] == 'on') {
			$message = $moduleConfig['message'];
			return '
			<script type="text/javascript">
				$(document).ready(function() {
					// Create the message element
					var messageHtml = \'<div class="alert alert-danger">' . $message . '</div>\';
					$(\'#paymentGatewaysContainer\').before(messageHtml); 
				});
			</script>';
		}
	}
});

add_hook('ClientAreaPageCart', 1, function ($vars) {
	if (isset($vars['gateways']) && is_array($vars['gateways'])) {
		$moduleConfig = getModuleConfigOptions('visitorquery');
		$sessionId = session_id();
		$minConfidence = !empty($moduleConfig['minConfidence']) ? (float)$moduleConfig['minConfidence'] : 0.9;

		if (!isset($vars['checkout']) || $vars['checkout'] !== true) {
			return $vars;
		}

		// Check if this IP is flagged in our detections table
		$detections = Capsule::table('mod_visitorquery_detections')
			->where('session_id', $sessionId)
			->get();

		$highestConfidence = 0;
		foreach ($detections as $detection) {
			if ($detection->confidence > $highestConfidence) {
				$highestConfidence = $detection->confidence;
			}
		}

		if ($highestConfidence >= $minConfidence) {
			if (configFlagIsOn($moduleConfig, 'skip_on_returning')) {
				// Check if this user has previous orders
				$previousOrdersCount = getUserPreviousOrdersCount();

				if ($previousOrdersCount > 0) {
					logModuleCall(
						'visitorquery', 'Check skipped', '', 'User has previous orders'
					);
					return $vars;
				}
			}

			$allowedGateways = [];

			foreach ($vars['gateways'] as $gateway => $details) {
				// Check if this gateway should be hidden for VPN/proxy users
				$configKey = 'gateway_' . $gateway;

				// If the gateway is not explicitly disabled in module settings, keep it
				if (!isset($moduleConfig[$configKey]) || $moduleConfig[$configKey] != 'on') {
					$allowedGateways[$gateway] = $details;
				}
			}

			$vars['gateways'] = $allowedGateways;
		}
	}

	return $vars;
});

function configFlagIsOn($config, $key) {
	return isset($config[$key]) && $config[$key] == 'on';
}

function getUserPreviousOrdersCount() {
	$command = 'GetOrders';
	$postData = array(
		'userid' => '1',
		'paymentstatus' => 'Paid',
	);

	$response = localAPI($command, $postData);
	if ($response['result'] == 'success') {
		return $response['totalresults'];
	}
	return 0;
}

function getModuleConfigOptions($moduleName) {
	$config = [];

	$moduleParams = Capsule::table('tbladdonmodules')
		->where('module', $moduleName)
		->get();

	foreach ($moduleParams as $param) {
		$config[$param->setting] = $param->value;
	}

	return $config;
}