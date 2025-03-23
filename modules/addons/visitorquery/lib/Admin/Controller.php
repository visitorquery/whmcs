<?php


namespace WHMCS\Module\Addon\VisitorQuery\Admin;


require_once __DIR__ . '/../../util/Util.php';

use WHMCS\Database\Capsule;
use WHMCS\User\Client;
use WHMCS\Module\Addon\VisitorQuery\Util\Util;

class Controller {

	public function invalidate($vars) {
		if (!isset($_GET['reason'])) {
			$actual_link = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

			return <<<EOF
<div style="max-width: 500px; text-align: center; margin: 100px auto;">
	<h2 style="font-weight: bolder">Please select a reason</h2>
	<p>Please flag carefully and appropriately as it may have implications in our detection algorithm for this project</p>

	<ul style="list-style-type: none; padding: 0; text-align: center; display: grid; grid-template-columns: 1fr 1fr; grid-gap: 10px; margin-top: 20px">
		<li>
			<a href="{$actual_link}&reason=invalid" class="btn btn-lg btn-block btn-danger">
				This is an invalid flagging
			</a>
		</li>
		<li>
			<a href="{$actual_link}&reason=none" class="btn btn-lg btn-block btn-primary">
				I want to let the user in regardless
			</a>
		</li>
	</ul>
</div>
EOF;
		}

		try {
			$record = Capsule::table('mod_visitorquery_detections')
				->where('id', $_GET['id'])
				->first();

			if (!$record) {
				throw new \Exception('Record not found');
			}

			Capsule::table('mod_visitorquery_detections')
				->where('id', $_GET['id'])
				->update([
					'invalidated' => true
				]);

			$moduleConfig = getModuleConfigOptions('visitorquery');

			if ($_GET['reason'] === 'invalid') {
				try {
					// send the request back for further analysis
					sendRequest(
						'https://visitorquery.com/api/v1/invalidate',
						'POST',
						[
							'detect_id' => $record->detect_id,
						],
						[
							'Content-Type: application/json',
							'Authorisation: Bearer ' . $moduleConfig['apiKey']
						]
					);
				} catch (\Exception $e) {
					// log the error
					logModuleCall(
						'visitorquery',
						'Invalidate:report',
						$vars,
						$e->getMessage(),
						$e->getTraceAsString()
					);
				}
			}
		} catch (\Exception $e) {
			// log the error
			logModuleCall(
				'visitorquery',
				'Invalidate',
				$vars,
				$e->getMessage(),
				$e->getTraceAsString()
			);
		}
		header('Location: ' . $vars['modulelink'] . '&action=index');
		exit;
	}

	public function index($vars) {
		// Get common module parameters
		$modulelink = $vars['modulelink']; // eg. addonmodules.php?module=addonmodule
		$version = $vars['version']; // eg. 1.0
		$perPage = 30;

		$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;

		$detections = Capsule::table('mod_visitorquery_detections')
			->orderBy('created_at', 'desc')
			->limit($perPage)
			->offset(($currentPage - 1) * $perPage)
			->get();

		$totalDetections = Capsule::table('mod_visitorquery_detections')->count();
		$numPages = ceil($totalDetections / $perPage);

		$prevPage = max($currentPage - 1, 1);
		$prevPageUrl = Util::modifyPageParam($prevPage);
		$prevPageHiddenStyles = $prevPage == $currentPage ? 'display: none' : '';

		$nextPage = min($currentPage + 1, $numPages);
		$nextPageUrl = Util::modifyPageParam($nextPage);
		$nextPageHiddenStyles = $currentPage == $numPages ? 'display: none' : '';


		$rowsHtml = '';
		if (count($detections) > 0) {
			foreach ($detections as $detection) {
				// last part of the session_id is the user_id
				$userEmail = "";
				if ($detection->user_id) {
					$user = Client::find($detection->user_id);

					if ($user) {
						$userEmail = $user->owner()->first()->email;
					}
				}


				$invalidateActionHtml = <<<EOF
					<a href="{$modulelink}&action=invalidate&id={$detection->id}" class="btn btn-danger btn-xs">
						Invalidate
					</a>
EOF;
				if ($detection->invalidated) {
					$invalidateActionHtml = '<span>Invalidated</span>';
				}

				$session_id = explode(':', $detection->session_id)[0];

				$rowsHtml .= <<<EOF
					<tr>
						<td style="font-family: monospace; font-weight: bold">{$session_id}</td>
						<td>{$userEmail}</td>
						<td style="font-family: monospace">{$detection->ip_address}</td>
						<td style="font-family: monospace">{$detection->confidence_bot}</td>
						<td style="font-family: monospace">{$detection->confidence_proxy_vpn}</td>
						<td style="font-family: monospace">{$detection->created_at}</td>
						<td style="text-align: right">{$invalidateActionHtml}</td>
					</tr>
EOF;
			}
		}

		$footerHtml = <<<EOF
<tfoot>
	<tr>
		<td colspan="7">
			<div style="display: flex; padding-top: 7px">
				<p style="flex-grow: 1; text-align: left;">
					Page {$currentPage} of {$numPages}
				</p>
				<div style="">
					<a style="margin-right: 10px; {$prevPageHiddenStyles}" href="{$prevPageUrl}">&laquo; Prev</a>
					<a style="{$nextPageHiddenStyles}" href="{$nextPageUrl}">Next &raquo;</a>
				</div>
			</div>
		</td>
	</tr>
</tfoot>
EOF;

		$countStr = number_format($totalDetections);
		return <<<EOF

<h2>Detections ({$countStr})</h2>

<div class="table-container">
	<table id="visitorQueryTable" class="datatable display" style="width:100%">
		<thead>
			<tr>
				<th style="" rowspan="2">Session ID</th>
				<th style="width: 300px" rowspan="2">User</th>
				<th style="width: 140px" rowspan="2">IP Address</th>
				<th style="width: 180px" colspan="2">Confidence</th>
				<th style="width: 200px" rowspan="2">Date</th>
				<th style="width: 100px" rowspan="2">&nbsp;</th>
			</tr>
			
			<tr>
				<th style="width: 90px">Bot</th>
				<th style="width: 90px">Proxy/VPN</th>
			</tr>
		</thead>
		
		<tbody>
			{$rowsHtml}
		</tbody>
		
		{$footerHtml}
	</table>
</div>
EOF;
	}

}


function sendRequest($url, $method = 'GET', $data = [], $headers = []) {
	$ch = curl_init();

	// Set URL
	curl_setopt($ch, CURLOPT_URL, $url);

	// Set request method
	if ($method == 'POST') {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	} else if ($method != 'GET') {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if (!empty($data)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		}
	}

	// Set headers
	if (!empty($headers)) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}

	// Return the response instead of outputting it
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	// Execute the request
	$response = curl_exec($ch);

	// Check for errors
	if (curl_errno($ch)) {
		$error = curl_error($ch);
		curl_close($ch);
		return "cURL Error: " . $error;
	}

	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	curl_close($ch);
	return [
		'success' => ($httpCode >= 200 && $httpCode < 300),
		'response' => $response,
		'code' => $httpCode
	];
}