<?php

use WHMCS\Module\Gateway;
use WHMCS\Config\Setting;

use Illuminate\Database\Capsule\Manager as Capsule;


add_hook('AdminAreaPage', 1, function ($vars) {
	$extraVariables = [];
	$moduleConfig = getModuleConfigOptions('visitorquery');

	if (!$moduleConfig["projectId"]) {
		// we just installed but did not configure
		return '';
	}

	$projectfromDb = Capsule::table('mod_visitorquery_projects')
		->where('project_id', $moduleConfig["projectId"])
		->first();

	if ($projectfromDb) {
		// we have the project stored which means we already did the setup
		return '';
	}

	$project = getProject($moduleConfig['projectId'], $moduleConfig['apiKey']);

	if (str_contains($project, "cURL Error")) {
		$extraVariables['jquerycode'] = '
            $("#contentarea").prepend(`
                <div class="errorbox">
                    <strong>
                        <span class="title">VisitorQuery error!</span>
                    </strong>
                     </br> 
                     We had a problem fetching the project details from VisitorQuery. 
                     Please check your API key and project ID.
                </div>`);
            ';
		return $extraVariables;
	}

	$parsedProject = json_decode($project, true);

	if (!isset($parsedProject["data"])) {
		$extraVariables['jquerycode'] = '
            $("#contentarea").prepend(`
                <div class="errorbox">
                    <strong>
                        <span class="title">VisitorQuery error!</span>
                    </strong>
                     </br> 
                     We had a problem fetching the project details from VisitorQuery. 
                     Please check your API key and project ID.
                </div>`);
            ';
		return $extraVariables;
	}

	$requiredFields = ["id", "apiKeyPublic", "apiKeyPrivate", "domain"];

	foreach ($requiredFields as $field) {
		if (!isset($parsedProject["data"][$field])) {
			$extraVariables['jquerycode'] = '
            $("#contentarea").prepend(`
                <div class="errorbox">
                    <strong>
                        <span class="title">VisitorQuery error!</span>
                    </strong>
                     </br> 
                     We had a problem fetching the project details from VisitorQuery. 
                     Please check your API key and project ID.
                </div>`);
            ';
			return $extraVariables;
		}
	}

	// Insert the project into the database
	Capsule::table('mod_visitorquery_projects')->insert([
		'project_id' => $parsedProject["data"]['id'],
		'api_key_public' => $parsedProject["data"]['apiKeyPublic'],
		'api_key_private' => $parsedProject["data"]['apiKeyPrivate'],
	]);


	// Add a webhook to the project
	$rootUrl = Setting::getValue('SystemURL');

	// Add forward slash if missing
	if (substr($rootUrl, -1) !== '/') {
		$rootUrl .= '/';
	}

	$hookUrl = $rootUrl . 'modules/addons/visitorquery/callback.php';
	$webhookResponse = addWebHook(
		$parsedProject["data"]['id'],
		$hookUrl,
		$moduleConfig['apiKey']
	);

	if (str_contains($webhookResponse, "cURL Error")) {
		$extraVariables['jquerycode'] = '
            $("#contentarea").prepend(`
                <div class="errorbox">
                    <strong>
                        <span class="title">VisitorQuery error!</span>
                    </strong>
                     </br> 
                     We had a problem setting the webhok on VisitorQuery.
                     Verify all details and, if everything looks correct, setup one yourself in the VisitorQuery dashboard.
                     Webhook URL: ' . $hookUrl . '
                </div>`);
            ';
	}

	return $extraVariables;
});

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
	session_start();

	$userId = $_SESSION['uid'] ?? 0;
	$moduleConfig = getModuleConfigOptions('visitorquery');
	$project = Capsule::table('mod_visitorquery_projects')
		->where('project_id', $moduleConfig["projectId"])
		->first();

	if (!$project) {
		return '';
	}

	$sessionId = session_id();

	$script = <<<EOT
<script src="https://cdn.visitorquery.com/visitorquery.js"></script>
<script type="text/javascript">
	window.addEventListener('load', () => {
		window.VisitorQuery.run({
			ApiKey   : "{$project->api_key_public}",
			SessionId: "{$sessionId}:{$userId}",
		});
	});
</script>
EOT;

	return $script;
});

// Didn't find a better place to show the message than via js/jquery
add_hook('ShoppingCartCheckoutOutput', 1, function ($vars) {
	session_start();

	$sessionId = session_id();
	$moduleConfig = getModuleConfigOptions('visitorquery');

	$minConfidence = !empty($moduleConfig['minConfidence']) ? (float)$moduleConfig['minConfidence'] : 0.9;

	// Check if this session id is flagged in our detections table
	$detections = Capsule::table('mod_visitorquery_detections')
		->where('backend_session_id', $sessionId)
		->get();

	$highestConfidence = 0;
	foreach ($detections as $detection) {
		if ($detection->confidence_proxy_vpn > $highestConfidence) {
			$highestConfidence = $detection->confidence_proxy_vpn;
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
	session_start();

	if (isset($vars['gateways']) && is_array($vars['gateways'])) {
		$moduleConfig = getModuleConfigOptions('visitorquery');
		$sessionId = session_id();
		$minConfidence = !empty($moduleConfig['minConfidence']) ? (float)$moduleConfig['minConfidence'] : 0.9;

		if (!isset($vars['checkout']) || $vars['checkout'] !== true) {
			return $vars;
		}

		// Check if this IP is flagged in our detections table
		$detections = Capsule::table('mod_visitorquery_detections')
			->where('backend_session_id', $sessionId)
			->get();

		$highestConfidence = 0;
		foreach ($detections as $detection) {
			if ($detection->confidence_proxy_vpn > $highestConfidence) {
				$highestConfidence = $detection->confidence_proxy_vpn;
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


function getProject($projectId, $apiKey): string {
	$curl = curl_init();

	curl_setopt_array($curl, [
		CURLOPT_URL => "https://visitorquery.com/api/v1/projects/{$projectId}",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "GET",
		CURLOPT_HTTPHEADER => [
			"Authorization: Bearer {$apiKey}"
		],
	]);

	$response = curl_exec($curl);
	$err = curl_error($curl);

	curl_close($curl);

	if ($err) {
		return "cURL Error #:" . $err;
	} else {
		return $response;
	}
}

function addWebHook($projectId, $webhookUrl, $apiKey) {
	$curl = curl_init();

	curl_setopt_array($curl, [
		CURLOPT_URL => "https://visitorquery.com/api/v1/projects/{$projectId}/webhooks",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => json_encode([
			"type" => "proxy_vpn_detect",
			"url" => $webhookUrl
		]),
		CURLOPT_HTTPHEADER => [
			"Authorization: Bearer {$apiKey}",
			"Content-Type: application/json"
		],
	]);

	$response = curl_exec($curl);
	$err = curl_error($curl);

	curl_close($curl);

	if ($err) {
		return "cURL Error #:" . $err;
	} else {
		return $response;
	}
}