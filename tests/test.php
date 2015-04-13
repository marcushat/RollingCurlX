#!/usr/bin/php
<?php

/*
 * Adapted example from the code on https://github.com/petewarden/ParallelCurl
 */


require dirname(__DIR__) . '/vendor/autoload.php'; // Autoload files using Composer autoload

/**
 * A test script for the RollingCurlX class
 *
 * This example fetches the value of €1 on different currencies through the freecurrencyconverterapi.com
 *
 * NOTE: freecurrencyconverterapi.com IS A FREE SERVICE, PLEASE, BE CONSIDERATE AND DON'T THROW TOO MANY REQUESTS
 * AGAINST THEIR SERVERS.
 *
 * By Julio Foulquie <jfoulquie@gmail.com>, freely reusable.
 */

use marcushat\RollingCurlX;

$base_url = 'http://www.freecurrencyconverterapi.com/api/v3/';
$currencies = array();

if (isset($argv[1]) && is_numeric($argv[1])) {
    $max_requests = (int)$argv[1];
} else {
    $max_requests = 10;
}

$curl_options = array(
    CURLOPT_SSL_VERIFYPEER => FALSE,
    CURLOPT_SSL_VERIFYHOST => FALSE,
    CURLOPT_USERAGENT, 'RollingCurlX test script',
);

echo "Using $max_requests concurrent requests at max." . PHP_EOL;
$rolling_curl = new RollingCurlX($max_requests);
$rolling_curl->setOptions($curl_options);


// Retrieve currencies
$currencies = $rolling_curl->addRequest($base_url . 'currencies', NULL, 'process_currencies');
$rolling_curl->execute();

// After running 'execute' the number of max_requests gets overwritten to the number of actual requests so reset it.
///TODO: Fix this on the class, _maxConcurrent should go back to its original value after an 'execute' call.
$rolling_curl->setMaxConcurrent($max_requests);
$rolling_curl->setOptions($curl_options);

foreach ($currencies as $currency => $data) {
	$user_data = array("1€ in $currency", $currency);
	$search_url = $base_url . 'convert?q=EUR_'.$currency. '&compact=y';
	$rolling_curl->addRequest($search_url, NULL, 'on_request_done', $user_data);
}

$rolling_curl->execute();


/**
 * CALLBACKS
 */

function process_currencies($response, $url, $request_info, $user_data, $time) {
	global $currencies;

	if ($request_info['http_code'] !== 200) {
		print "Fetch error {$request_info['http_code']} for '$url'\n";
		return;
	}

	$currencies = json_decode($response, true);
	if (!is_array($currencies)) {
		print "No results found for '{$user_data[1]}'\n";
		print_r($responseobject);
		return;
	}

	$currencies = isset($currencies['results']) ? $currencies['results'] : array();
	echo "Total of " . count($currencies) . " currencies found." . PHP_EOL;
}

// This function gets called back for each request that completes
function on_request_done($response, $url, $request_info, $user_data, $time) {

	if ($request_info['http_code'] !== 200) {
		print "Fetch error {$request_info['http_code']} for '$url'\n";
		return;
	}

	$currency_key = 'EUR_' . $user_data[1];
	$responseobject = json_decode($response, true);
	if (empty($responseobject[$currency_key]['val'])) {
		print "No results found for '{$user_data[1]}'\n";
		return;
	}

	if (empty($responseobject)) {
		print "No results found for '{$user_data[1]}'\n";
		return;
	}

	$value = isset($responseobject[$currency_key]['val']) ? $responseobject[$currency_key]['val'] : 'NOT_FOUND' ;

	print "********\n";
	print "{$user_data[0]}: $value\n";
	print "********\n";

}
