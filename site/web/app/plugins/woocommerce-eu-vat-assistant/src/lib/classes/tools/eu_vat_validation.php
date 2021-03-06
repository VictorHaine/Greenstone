<?php
namespace Aelia\WC\EU_VAT_Assistant;
if(!defined('ABSPATH')) exit; // Exit if accessed directly

use \nusoap_client;
use \wsdl;

/**
 * Handles the validation of EU VAT numbers using the VIES service.
 */
class EU_VAT_Validation extends \Aelia\WC\Base_Class {
	/**
	 * An associative array of country code => EU VAT prefix pairs.
	 * @var array
	 */
	protected static $vat_country_prefixes;

	/**
	 * The errors generated by the class.
	 * @var array
	 */
	protected $errors = array();
	/**
	 * The VAT prefix that will be passed for validation.
	 * @var string
	 */
	protected $vat_prefix;
	/**
	 * The VAT number that will be passed for validation.
	 * @var string
	 */
	protected $vat_number;

	// @var bool Indicates if debug mode is active.
	protected $debug_mode;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->logger = new Logger(Definitions::PLUGIN_SLUG);
		$this->text_domain = Definitions::TEXT_DOMAIN;
		$this->debug_mode = WC_Aelia_EU_VAT_Assistant::instance()->debug_mode();
	}

	/**
	 * Factory method.
	 */
	public static function factory() {
		return new static();
	}

	/**
	 * Returns a list of errors occurred during the validation of a VAT number.
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Returns sn associative array of country code => EU VAT prefix pairs.
	 *
	 * @return array
	 */
	protected static function get_vat_country_prefixes() {
		if(empty(self::$vat_country_prefixes)) {
			self::$vat_country_prefixes = array();
			foreach(WC_Aelia_EU_VAT_Assistant::instance()->get_eu_vat_countries() as $country_code) {
				self::$vat_country_prefixes[$country_code] = $country_code;
			}

			// Correct vat prefixes that don't match the country code and add some
			// extra ones
			// Greece
			self::$vat_country_prefixes['GR'] = 'EL';
			// Isle of Man
			self::$vat_country_prefixes['IM'] = 'GB';
			// Monaco
			self::$vat_country_prefixes['MC'] = 'FR';
		}

		return apply_filters('wc_aelia_euva_vat_country_prefixes', self::$vat_country_prefixes);
	}

	/**
	 * Parses a VAT number, removing special characters and the country prefix, if
	 * any.
	 */
	public function parse_vat_number($vat_number) {
		// Remove special characters
		$vat_number = strtoupper(str_replace(array(' ', '-', '_', '.'), '', $vat_number));

		// Remove country code if set at the begining
		$prefix = substr($vat_number, 0, 2);
		if(in_array($prefix, array_values(self::get_vat_country_prefixes()))) {
			$vat_number = substr($vat_number, 2);
		}
		if(empty($vat_number)) {
			return false;
		}
		return $vat_number;
	}

	/**
	 * Returns the VAT prefix used by a specific country.
	 *
	 * @param string country A country code.
	 * @return string|false
	 */
	public function get_vat_prefix($country) {
		$country_prefixes = self::get_vat_country_prefixes();
		return get_value($country, $country_prefixes, false);
	}

	/**
	 * Caches the validation result of a VAT number for a limited period of time.
	 * This will improve performances when customers will place new orders in a
	 * short timeframe, by reducing the amount of calls to the VIES service.
	 *
	 * @param string vat_prefix The VAT prefix.
	 * @param string vat_number The VAT number.
	 * @param array result The validation result.
	 */
	protected function cache_validation_result($vat_prefix, $vat_number, $result) {
		set_transient(Definitions::TRANSIENT_EU_NUMBER_VALIDATION_RESULT . $vat_prefix . $vat_number,
									$result, 1 * HOUR_IN_SECONDS);
	}

	/**
	 * Returns the cached result of a VAT number validation, if it exists.
	 *
	 * @param string vat_prefix The VAT prefix.
	 * @param string vat_number The VAT number.
	 * @return array|bool An array with the validatin result, or false if a cached
	 * result was not found.
	 */
	protected function get_cached_validation_result($vat_prefix, $vat_number) {
		// In debug mode, behave as if nothing was cached
		if($this->debug_mode) {
			return false;
		}
		return get_transient(Definitions::TRANSIENT_EU_NUMBER_VALIDATION_RESULT . $vat_prefix . $vat_number);
	}

	/**
	 * Validates the argument passed for validation, transforming a countr code
	 * into a VAT prefix and checking the VAT number before it's used for a VIES
	 * request.
	 *
	 * @param string country A country code. It will be used to determine the VAT
	 * number prefix.
	 * @param string vat_number A VAT number.
	 * @return bool
	 */
	protected function validate_request_arguments($country, $vat_number) {
		// Some preliminary formal validation, to prevent unnecessary requests with
		// clearly invalid data
		$this->vat_number = $this->parse_vat_number($vat_number);
		if($this->vat_number == false) {
			$this->errors[] = sprintf(__('An empty or invalid VAT number was passed for validation. ' .
																	 'The VAT number should contain several digits, without the ' .
																	 'country prefix. Received VAT number: "%s".',
																	 $this->text_domain),
																$vat_number);
		}

		$this->vat_prefix = $this->get_vat_prefix($country);
		if(empty($this->vat_prefix)) {
			$this->errors[] = sprintf(__('A VAT prefix could not be found for the specified country. ' .
																	 'Received country code: "%s".',
																	 $this->text_domain),
																$country);
		}

		return empty($this->errors);
	}

	/**
	 * Checks if a cached response is valid. In some older versions, an incorrect
	 * response was cached and, when returned, caused the plugin to consider invalid
	 * numbers that were actually valid.
	 *
	 * @param mixed $response The cached response.
	 * @return bool
	 */
	protected function valid_cached_response($response) {
		return ($response != false) &&
					 is_array($response) &&
					 (get_value('valid', $response) == 'true');
	}

	/**
	 * Validates a VAT number.
	 *
	 * @param string country The country code to which the VAT number belongs.
	 * @param string vat_number The VAT number to validate.
	 * @return array|bool An array with the validation response returned by the
	 * VIES service, or false when the request could not be sent for some reason.
	 * @link http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl
	 */
	public function validate_vat_number($country, $vat_number) {
		$this->errors = array();

		if(!$this->validate_request_arguments($country, $vat_number)) {
			return false;
		}

		// Return a cached response, if one exists. Faster than sending a SOAP request.
		$cached_response = $this->get_cached_validation_result($this->vat_prefix, $this->vat_number);
		if($this->valid_cached_response($cached_response)) {
			return $cached_response;
		}

		// Debug
		//var_dump($country, $vat_number, $this->vat_prefix, $this->vat_number);die();

		// Cache the WSDL
		$wsdl = get_transient('VIES_WSDL');
		if(empty($wsdl) || $this->debug_mode) {
			$wsdl = new wsdl('http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl', '', '', '', '', 5);
			// Cache the WSDL for one minute. Sometimes VIES returns an invalid WSDL,
			// caching it for too long could cause the whole validation system to fail
			set_transient('VIES_WSDL', $wsdl, 60);
		}

		// Create SOAP client
		$client = new nusoap_client($wsdl, 'wsdl');
		// Ensure that UTF-8 encoding is used, so that the client won't crash when
		// "odd" characters are used
		$client->decode_utf8 = false;
		$client->soap_defencoding = 'UTF-8';
		$client->setUseCurl(true);
		// Check if any error occurred initialising the SOAP client. We won't be able
		// to continue, in such case.
		$error = $client->getError();
		if($error) {
			$this->errors[] = sprintf(__('An error occurred initialising SOAP client. Error message: "%s".',
																	 $this->text_domain),
																$error);
			return false;
		}

		$response = $client->call('checkVat', array(
			'countryCode' => $this->vat_prefix,
			'vatNumber' => $this->vat_number,
		));

		if(is_array($response)) {
			$result = array(
				'valid' => ($response['valid'] === 'true'),
				'company_name' => get_arr_value('name', $response, ''),
				'company_address' => get_arr_value('address', $response, ''),
				'errors' => array(get_arr_value('FaultString', $response, '')),
				'raw_response' => $response,
			);
		}
		else {
			$result = array(
				'valid' => null,
				'company_name' => null,
				'company_address' => null,
				'errors' => $this->get_errors(),
				'raw_response' => null,
			);
		}

		// Cache response for valid VAT numbers
		if(($result['valid'] === 'true') && !$this->debug_mode) {
			$this->cache_validation_result($this->vat_prefix, $this->vat_number, $result);
		}
		return $result;
	}
}
