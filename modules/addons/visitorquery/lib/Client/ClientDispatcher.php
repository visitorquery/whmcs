<?php

namespace WHMCS\Module\Addon\VisitorQuery\Client;

/**
 * Sample Client Area Dispatch Handler
 */
class ClientDispatcher {

	public function dispatch($action, $parameters) {
		if (!$action) {
			// Default to index if no action specified
			$action = 'index';
		}

		$controller = new Controller();

		// Verify requested action is valid and callable
		if (is_callable(array($controller, $action))) {
			return $controller->$action($parameters);
		}
	}
}
