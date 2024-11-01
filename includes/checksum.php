<?php

class CommonConstants {
	const ALGO = "sha256";
	const ENCODING = "UTF-8";
}

class ChecksumUtil {
	public static function generateChecksumForJson($json_decode, $merchantKey) {

		// Remove null and empty values from the json and sort keys in alphabetical order
		$sanitizedInput = ChecksumUtil::sanitizeInput($json_decode, $merchantKey);

		// Append merchant Key
		$serializedObj = $sanitizedInput . $merchantKey;

		// Calculate Checksum for the serialized string
		return ChecksumUtil::calculateChecksum($serializedObj);
	}
	private static function calculateChecksum($serializedObj) {
		// Use 'sha-265' for hashing
		$checksum = hash(CommonConstants::ALGO, $serializedObj, false);
		return $checksum;
	}
	private static function recur_ksort(&$array) {
		// Sort json object keys alphabetically recursively
		foreach ($array as &$value) {
			if (is_array($value)) {
				ChecksumUtil::recur_ksort($value);
			}

		}
		return ksort($array);
	}
	private static function sanitizeInput(array $json_decode, $merchantKey) {
		$reqWithoutNull = array_filter($json_decode, function ($k) {

			if (is_null($k)) {
				return false;
			}
			if (is_array($k)) {
				return true;
			}

			return !(trim($k) == "");
		});

		ChecksumUtil::recur_ksort($reqWithoutNull);
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		return json_encode($reqWithoutNull, $flags);
	}
}