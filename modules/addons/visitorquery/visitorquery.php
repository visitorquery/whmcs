<?php


use WHMCS\Database\Capsule;

use WHMCS\Module\Addon\VisitorQuery\Admin\AdminDispatcher;
use WHMCS\Module\Addon\VisitorQuery\Client\ClientDispatcher;

if (!defined("WHMCS")) {
	die("This file cannot be accessed directly");
}

function visitorquery_config() {
	$cfg = [
		'name' => 'VisitorQuery - Proxy and VPN detect',
		'description' => 'This module allows you to customize or deny the checkout process for a client'
			. ' that is found to be using a proxy or a VPN',
		'author' => 'VisitorQuery.com',
		'language' => 'english',
		'version' => '1.0',
		'fields' => [
			'pubAK' => [
				'FriendlyName' => 'Project Public API KEY',
				'Type' => 'text',
				'Size' => '65',
				'Description' => 'Your project\'s public API KEY.',
			],
			'privAK' => [
				'FriendlyName' => 'Project Private API KEY',
				'Type' => 'text',
				'Size' => '65',
				'Description' => 'Your project\'s private API KEY',
			],
			'apiKey' => [
				'FriendlyName' => 'Developer API KEY',
				'Type' => 'text',
				'Size' => '65',
				'Description' => 'Your developer API KEY',
			],
			'minConfidence' => [
				'FriendlyName' => 'Minimum Confidence',
				'Type' => 'text',
				'Size' => '5',
				'Description' => 'The minimum confidence level required to flag a session as a proxy/VPN. Must be between 0 and 1',
				'Default' => '0.60',
			],
			'show_message' => [
				'FriendlyName' => 'Show Message',
				'Type' => 'yesno',
				'Description' => 'Show a message to the client when a proxy/VPN is detected',
			],
			'message' => [
				'FriendlyName' => 'Message',
				'Type' => 'textarea',
				'Rows' => '3',
				'Description' => 'The message to show to the client when a proxy/VPN is detected',
				'Default' => 'Some of the payment methods have been disabled because we detected unusual activity from your session. If you think this is in error, please contact support.',
			],
			'skip_on_returning' => [
				'FriendlyName' => 'Skip on Returning Clients',
				'Type' => 'yesno',
				'Default' => 'on',
				'Description' => 'Skip blocking on returning/existing clients (users with previous orders)',
			],
		]
	];

	try {
		$uniqueGateways = Capsule::table('tblpaymentgateways')
			->select('gateway')
			->distinct()
			->get();

		foreach ($uniqueGateways as $gateway) {
			$gatewayName = $gateway->gateway;
			$fieldName = 'gateway_' . $gatewayName;

			$cfg['fields'][$fieldName] = [
				'FriendlyName' => ucfirst($gatewayName),
				'Type' => 'yesno',
				'Description' => 'Hide this gateway when a proxy/VPN is detected',
			];
		}
	} catch (\Exception $e) {
		// Handle any database errors
		logModuleCall(
			'visitorquery',
			'Config Error',
			'Error fetching gateways',
			$e->getMessage()
		);
	}

	return $cfg;
}

function visitorquery_activate() {
	// Create custom tables and schema required by your module
	try {

		Capsule::schema()
			->create(
				'mod_visitorquery_detections',
				function ($table) {
					$table->increments('id');
					$table->text('detect_id');
					$table->text('ip_address');
					$table->text('session_id');
					$table->boolean('invalidated')->default(false);
					$table->float('confidence', 3, 2);
					$table->timestamp('created_at')->useCurrent();
				}
			);


		return [
			// Supported values here include: success, error or info
			'status' => 'success',
			'description' => 'Module activated successfully',
		];
	} catch (\Exception $e) {
		return [
			'status' => "error",
			'description' => 'Unable to create mod_visitorquery: ' . $e->getMessage(),
		];
	}
}

function visitorquery_deactivate() {
	try {
		Capsule::schema()->dropIfExists('mod_visitorquery_detections');

		return [
			// Supported values here include: success, error or info
			'status' => 'success',
			'description' => 'Module de-activated successfully',
		];
	} catch (\Exception $e) {
		return [
			// Supported values here include: success, error or info
			"status" => "error",
			"description" => "Unable to drop mod_visitorquery: {$e->getMessage()}",
		];
	}
}

function visitorquery_upgrade($vars) {
	$currentlyInstalledVersion = $vars['version'];
//
//	/// Perform SQL schema changes required by the upgrade to version 1.1 of your module
//	if ($currentlyInstalledVersion < 1.1) {
//		$schema = Capsule::schema();
//		// Alter the table and add a new text column called "demo2"
//		$schema->table('mod_visitorquery', function ($table) {
//			$table->text('demo2');
//		});
//	}
//
//	/// Perform SQL schema changes required by the upgrade to version 1.2 of your module
//	if ($currentlyInstalledVersion < 1.2) {
//		$schema = Capsule::schema();
//		// Alter the table and add a new text column called "demo3"
//		$schema->table('mod_visitorquery', function ($table) {
//			$table->text('demo3');
//		});
//	}
}

function visitorquery_output($vars) {
	// Get common module parameters
	$modulelink = $vars['modulelink']; // eg. visitorquerys.php?module=visitorquery
	$version = $vars['version']; // eg. 1.0
	$_lang = $vars['_lang']; // an array of the currently loaded language variables

	// Get module configuration parameters
	$message = $vars['message'];

	// Dispatch and handle request here. What follows is a demonstration of one
	// possible way of handling this using a very basic dispatcher implementation.

	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

	$dispatcher = new AdminDispatcher();
	$response = $dispatcher->dispatch($action, $vars);
	echo $response;
}

function visitorquery_sidebar($vars) {
	// Get common module parameters
	$modulelink = $vars['modulelink'];
	$version = $vars['version'];
	$_lang = $vars['_lang'];

	$sidebar = '<div class="sidebar-header">
		<i class="fas fa-flag-alt"></i>
		VisitorQuery
	</div>
	<ul class="menu">
        <li><a href="addonmodules.php?module=visitorquery&action=index">Detections</a></li>
    </ul>';
	return $sidebar;
}

function visitorquery_clientarea($vars) {
	// Get common module parameters
	$modulelink = $vars['modulelink']; // eg. index.php?m=visitorquery
	$version = $vars['version']; // eg. 1.0
	$_lang = $vars['_lang']; // an array of the currently loaded language variables

	/**
	 * Dispatch and handle request here. What follows is a demonstration of one
	 * possible way of handling this using a very basic dispatcher implementation.
	 */

//	$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
//
//	$dispatcher = new ClientDispatcher();
//	return $dispatcher->dispatch($action, $vars);
}
