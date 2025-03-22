<?php

namespace WHMCS\Module\Addon\VisitorQuery\Util;

class Util {
	/**
	 * Modifies the 'page' parameter in the current URL
	 *
	 * @param int $pageNumber The new page number to set
	 * @param string|null $url The URL to modify (defaults to current URL if null)
	 * @return string The modified URL with the new page number
	 */
	public static function modifyPageParam($pageNumber, $url = null): string {
		// Use provided URL or get current URL if not provided
		if ($url === null) {
			$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}

		// Convert HTML entities to their corresponding characters
		$url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Parse the URL into components
		$urlParts = parse_url($url);

		// Extract query parameters to an associative array
		$queryParams = [];
		if (isset($urlParts['query'])) {
			parse_str($urlParts['query'], $queryParams);
		}

		// Set the page parameter
		$queryParams['page'] = $pageNumber;

		// Build the base URL without query string
		$baseUrl = '';
		$baseUrl .= isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
		$baseUrl .= isset($urlParts['host']) ? $urlParts['host'] : '';
		$baseUrl .= isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
		$baseUrl .= isset($urlParts['path']) ? $urlParts['path'] : '';

		// Add the query string
		$queryString = http_build_query($queryParams);
		$url = $baseUrl . '?' . $queryString;

		// Add fragment if it exists
		if (isset($urlParts['fragment'])) {
			$url .= '#' . $urlParts['fragment'];
		}

		return $url;
	}
}