<?php
/**
 * Sets or returns credentials as found in the 'Authorization' header
 * sent by the client.
 *
 * Have your client set a header like:
 * Authorization: TRUEREST username=john&password=xxx&apikey=247b5a2f72df375279573f2746686daa<
 * http://docs.amazonwebservices.com/AmazonS3/2006-03-01/index.html?RESTAuthentication.html
 */
class RestCredentials extends Object {
	public $auth;
	public $credentials;
	public $restComponent;

	public function __construct(&$component, $settings = array()) {
		$this->_set($settings);

		if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
			$parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
			$match = array_shift($parts);
			if ($match !== $this->auth['keyword']) {
				return false;
			}
			$str = join(' ', $parts);
			parse_str($str, $this->credentials);

			if (!isset($this->credentials[$this->auth['fields']['class']])) {
				$this->credentials[$this->auth['fields']['class']] = $this->ratelimit['default'];
			}

			if (isset($this->auth['fields']['username'])) {
				$username = $this->credentials[$this->auth['fields']['username']];
			}

			if (isset($this->auth['fields']['apikey'])) {
				$apikey = $this->credentials[$this->auth['fields']['apikey']];
			}

			if (isset($this->auth['fields']['class'])) {
				$class = $this->credentials[$this->auth['fields']['class']];
			}

			$component->log(compact('username', 'apikey', 'class'));
		}
	}

	public function get($field = null) {
		if (!$field) {
			return $this->credentials;
		} elseif (is_string($field)) {
			return $this->credentials[$field];
		} else {
			$message = __('Error attempting to return a non-string key from RequestComponentCredentials::$credentials.');
			throw new InternalErrorException($message);
		}
	}
}
