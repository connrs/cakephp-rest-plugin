<?php
App::uses('RequestHandlerComponent', 'Controller/Component');
App::uses('RestCredentials', 'Rest.Lib');
Class RestComponent extends RequestHandlerComponent {
	public $Controller;
	public $controllerAction;

	protected $jsonCallbackKeys = array('jsoncallback', 'callback');
	protected $_RestLog;
	protected $_View;
	protected $_logData = array();
	protected $_feedback = array();
	protected $_aborting = false;

	/**
	 * Sensible defaults 
	 * 
	 * @var array
	 * @access public
	 */
	public $__defaultSettings = array(
		// Component options
		'callbacks' => array(
			'cbRestlogBeforeSave' => 'restlogBeforeSave',
			'cbRestlogAfterSave' => 'restlogAfterSave',
			'cbRestlogBeforeFind' => 'restlogBeforeFind',
			'cbRestlogAfterFind' => 'restlogAfterFind',
			'cbRestlogFilter' => 'restlogFilter',
			'cbRestRatelimitMax' => 'restRatelimitMax',
		),
		'extensions' => array('xml', 'json'),
		'viewsFromPlugin' => true,
		'skipControllers' => array( // Don't show these as actual rest controllers even though they have the component attached
			'App',
			'Defaults',
		),
		'auth' => array(
			'requireSecure' => false,
			'keyword' => 'TRUEREST',
			'fields' => array(
				'class' => 'class',
				'apikey' => 'apikey',
				'username' => 'username',
			),
		),
		'exposeVars' => array(
			'*' => array(
				'method' => 'get|post|put|delete',
				'id' => 'true|false',
			),
			'index' => array(
				'scopeVar' => 'scope|rack_name|any_other_varname_to_specify_scope',
			),
		),
		'defaultVars' => array(
			'index' => array(
				'scopeVar' => 'scope',
				'method' => 'get',
				'id' => false,
			),
			'view' => array(
				'scopeVar' => 'scope',
				'method' => 'get',
				'id' => true,
			),
			'edit' => array(
				'scopeVar' => 'scope',
				'method' => 'put',
				'id' => true,
			),
			'add' => array(
				'scopeVar' => 'scope',
				'method' => 'put',
				'id' => false,
			),
			'delete' => array(
				'scopeVar' => 'scope',
				'method' => 'delete',
				'id' => true,
			),
		),
		'log' => array(
			'model' => 'Rest.RestLog',
			'pretty' => true,
			// Optionally, choose to store some log fields on disk, instead of in the database
			'fields' => array(
				'data_in' => '{LOGS}rest-{date_Y}_{date_m}/{username}_{id}_1_{field}.log',
				'meta' => '{LOGS}rest-{date_Y}_{date_m}/{username}_{id}_2_{field}.log',
				'data_out' => '{LOGS}rest-{date_Y}_{date_m}/{username}_{id}_3_{field}.log',
			),
		),
		'meta' => array(
			'enable' => true,
			'requestKeys' => array(
				'HTTP_HOST',
				'HTTP_USER_AGENT',
				'REMOTE_ADDR',
				'REQUEST_METHOD',
				'REQUEST_TIME',
				'REQUEST_URI',
				'SERVER_ADDR',
				'SERVER_PROTOCOL',
			),
		),
		'ratelimit' => array(
			'enable' => true,
			'default' => 'Customer',
			'classlimits' => array(
				'Employee' => array('-1 hour', 1000),
				'Customer' => array('-1 hour', 100),
			),
			'identfield' => 'apikey',
			'ip_limit' => array('-1 hour', 60),  // For those not logged in
		),
		'version' => '0.3',
		'actions' => array(
			'view' => array(
				'extract' => array(),
			),
		),
		'debug' => 0,
		'onlyActiveWithAuth' => false,
		'paginate' => false
	);
	public $callbacks;
	public $extensions;
	public $viewsFromPlugin;
	public $skipControllers;
	public $auth;
	public $exposeVars;
	public $defaultVars;
	public $log;
	public $meta;
	public $ratelimit;
	public $version;
	public $actions;
	public $debug;
	public $onlyActiveWithAuth;
	public $paginate;

	/**
	 * Should the rest plugin be active?
	 *
	 * @var string
	 */
	protected $isRestful = null;

	public function __construct(ComponentCollection $collection, $settings = array()) {
		$this->Controller = $collection->getController();
		$this->controllerAction = $this->Controller->action;
		$settings = $this->initializeSettings($settings);

		parent::__construct($collection, $settings);
		$this->addInputType('json', array(array($this, 'unrootModelData'), $this->Controller->modelClass));
	}

	public function initializeSettings($settings) {
		$_settings = $this->__defaultSettings;
		if (is_array($config = Configure::read('Rest.settings'))) {
			$_settings = Set::merge($_settings, $config);
		}
		return Set::merge($_settings, $settings);
	}

	public function initialize (&$Controller) {
		parent::initialize($Controller);
		if (!$this->isRestful()) {
			return;
		}

		$this->setDebugLevel();
		$this->Credentials = new RestCredentials($this, array('auth' => $this->auth, 'ratelimit' => $this->ratelimit));
		$this->initializeLog();
		$this->initializeSecurity();
		$this->setExtensionForAjaxRequest();
	}

	/**
	 * Catch & fire callbacks. You can map callbacks to different places
	 * using the value parts in $this->callbacks.
	 * If the resolved callback is a string we assume it's in
	 * the controller.
	 *
	 * @param string $name
	 * @param array  $arguments
	 */
	public function  __call ($name, $arguments) {
		if (!isset($this->callbacks[$name])) {
			$message = __('Function does not exist: %s', $name);
			throw new BadRequestException($message);
		}

		$cb = $this->callbacks[$name];
		if (is_string($cb)) {
			$cb = array($this->Controller, $cb);
		}

		if (is_callable($cb)) {
			array_unshift($arguments, $this);
			return call_user_func_array($cb, $arguments);
		}
	}


	/**
	 * Write the accumulated logentry
	 *
	 * @param <type> $Controller
	 */
	public function shutdown (&$Controller) {
		if (!$this->isRestful()) {
			return;
		}

		$this->log(array(
			'responded' => date('Y-m-d H:i:s'),
		));

		$this->log(true);
	}

	/**
	 * Controls layout & view files
	 *
	 * @param <type> $Controller
	 * @return <type>
	 */
	public function startup (&$Controller) {
		parent::startup($Controller);
		if (!$this->isRestful()) {
			return;
		}

		// Rate Limit
		if ($this->ratelimit['enable']) {
			$credentials = $this->Credentials->get();
			if (!array_key_exists('class', $credentials)) {
				$this->warning('Unable to establish class');
			} else {
				$class = $credentials['class'];
				list($time, $max) = $this->ratelimit['classlimits'][$class];

				$cbMax = $this->cbRestRatelimitMax($credentials);
				if ($cbMax) {
					$max = $cbMax;
				}

				if (true !== ($count = $this->ratelimit($time, $max))) {
					$message = __('You have reached your ratelimit (%s is more than the allowed %s requests in %s)', $count, $max, str_replace('-', '', $time));
					$this->log('ratelimited', 1);
					throw new TooManyRequestsException($message, 429);
				}
			}
		}
		if ($this->viewsFromPlugin) {
			// Setup the controller so it can use
			// the view inside this plugin
			$this->Controller->viewClass = 'Rest.' . $this->View(false);
		}

		// Dryrun
		if(array_key_exists('meta', $_POST)) {
			$this->Controller->_restMeta['dryrun'] = $_POST['meta'];
			$message = __('Dryrun active, not really executing your command.');
			throw new OKException($message, 200);
		}
	}

	/**
	 * Collects viewVars, reformats, and makes them available as
	 * viewVar: response for use in REST serialization
	 *
	 * @param <type> $Controller
	 *
	 * @return <type>
	 */
	public function beforeRender (&$Controller) {
		parent::beforeRender($Controller);
		if (!$this->isRestful()) {
			return;
		}

		if (!$extract = $this->getActionSetting('extract')) {
			$data = $this->Controller->viewVars;
		} else {
			$data = $this->inject((array)$extract, $this->Controller->viewVars);
		}

		$response = $this->response($data);

		$this->Controller->set(compact('response'));

	}

	/**
	 * Determines is an array is numerically indexed
	 *
	 * @param array $array
	 *
	 * @return boolean
	 */
	public function numeric ($array = array()) {
		if (empty($array)) {
			return null;
		}
		$keys = array_keys($array);
		foreach ($keys as $key) {
			if (!is_numeric($key)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Works together with Logging to ratelimit incomming requests by
	 * identfield
	 *
	 * @return <type>
	 */
	public function ratelimit($time, $max) {
		// No rate limit active
		if (empty($this->ratelimit)) {
			return true;
		}

		// Need logging
		if (empty($this->log['model'])) {
			$message = __('Logging is required for any ratelimiting to work.');
			throw new InternalErrorException($message);
		}

		// Need identfield
		if (empty($this->ratelimit['identfield'])) {
			$message = __('Need an identfield or I will not know what to ratelimit on');
			throw new InternalErrorException($message);
		}

		$userField = $this->ratelimit['identfield'];
		$userId = $this->Credentials->get($userField);

		$this->cbRestlogBeforeFind();
		if ($userId) {
			// If you're logged in
			$logs = $this->RestLog()->find('list', array(
				'fields' => array('id', $userField),
				'conditions' => array(
					$this->RestLog()->alias . '.requested >' => date('Y-m-d H:i:s', strtotime($time)),
					$this->RestLog()->alias . '.' . $userField => $userId,
				),
			));
		} else {
			// IP based rate limiting
			$max  = $this->ratelimit['ip_limit'];
			$logs = $this->RestLog()->find('list', array(
				'fields' => array('id', $userField),
				'conditions' => array(
					$this->RestLog()->alias . '.requested >' => date('Y-m-d H:i:s', strtotime($time)),
					$this->RestLog()->alias . '.ip' => $this->_logData['ip'],
				),
			));
		}
		$this->cbRestlogAfterFind();

		$count = count($logs);
		if ($count >= $max) {
			return $count;
		}

		return true;
	}

	/**
	 * Return an instance of the log model
	 *
	 * @return object
	 */
	public function RestLog () {
		if (!$this->_RestLog) {
			$this->_RestLog = ClassRegistry::init($this->log['model']);
			$this->_RestLog->restLogSettings = $this->log;
			$this->_RestLog->restLogSettings['controller'] = $this->Controller->name;
			$this->_RestLog->Encoder = $this->View(true);
		}

		return $this->_RestLog;
	}

	/**
	 * log(true) writes log to disk. otherwise stores key-value
	 * pairs in memory for later saving. Can also work recursively
	 * by giving an array as the key
	 *
	 * @param mixed $key
	 * @param mixed $val
	 *
	 * @return boolean
	 */
	public function log($key, $val = null) {
		// Write log
		if ($key === true && func_num_args() === 1) {
			if (empty($this->log['model'])) {
				return true;
			}

			$this->RestLog()->create();
			$this->cbRestlogBeforeSave();

			$log = array(
				$this->RestLog()->alias => $this->_logData,
			);
			$log = $this->cbRestlogFilter($log);

			if (is_array($log)) {
				$res = $this->RestLog()->save($log);
			} else {
				$res = null;
			}

			$this->cbRestlogAfterSave();

			return $res;
		}

		// Multiple values: recurse
		if (is_array($key)) {
			foreach ($key as $k=>$v) {
				$this->log($k, $v);
			}
			return true;
		}

		// Single value, save
		$this->_logData[$key] = $val;
		return true;
	}

	/**
	 * Sets CakePHP debug mode for request based upon debug setting passed through
	 * from controller. Checks if debug level is set system wide and honours that setting
	 * 
	 * @access public
	 * @return void
	 */
	public function setDebugLevel() {
		if (null === $this->debug) {
			return;
		}

		Configure::write('debug', (int) $this->debug);
	}

	/**
	 * Returns a list of Controllers where Rest component has been activated
	 * uses Cache::read & Cache::write by default to tackle performance
	 * issues.
	 *
	 * @param boolean $cached
	 *
	 * @return array
	 */
	public function controllers($cached = true) {
		$ckey = sprintf('%s.%s', __CLASS__, __FUNCTION__);

		if (!$cached || !($restControllers = Cache::read($ckey))) {
			$restControllers = array();

			$controllers = App::objects('controller', null, false);

			// Unlist some controllers by default
			foreach ($this->skipControllers as $skipController) {
				if (false !== ($key = array_search($skipController, $controllers))) {
					unset($controllers[$key]);
				}
			}

			// Instantiate all remaining controllers and check components
			foreach ($controllers as $controller) {
				$className = $controller;
				$controller = substr($controller, 0, -10);

				$debug = false;
				if (!class_exists($className)) {
					if (!App::import('Controller', $controller)) {
						continue;
					}
				}
				$Controller = new $className();


				if (isset($Controller->components['Rest.Rest']['actions']) && is_array($Controller->components['Rest.Rest']['actions'])) {
					$exposeActions = array();
					foreach ($Controller->components['Rest.Rest']['actions'] as $action => $vars) {
						if (!in_array($action, $Controller->methods)) {
							$this->debug(sprintf(
								'Rest component is expecting a "%s" action but got "%s" instead. ' .
								'You probably upgraded your component without reading the backward compatiblity ' .
								'warnings in the readme file, or just did not implement the "%s" action in the "%s" controller yet',
								$Controller->name,
								$action,
								$action,
								$Controller->name
							));
							continue;
						}
						$saveVars = array();

						$exposeVars = array_merge(
							$this->exposeVars['*'],
							isset($this->exposeVars[$action]) ? $this->exposeVars[$action] : array()
						);

						foreach ($exposeVars as $exposeVar => $example) {
							if (isset($vars[$exposeVar])) {
								$saveVars[$exposeVar] = $vars[$exposeVar];
							} else {
								if (isset($this->defaultVars[$action][$exposeVar])) {
									$saveVars[$exposeVar] = $this->defaultVars[$action][$exposeVar];
								} else {
									return $this->abort(sprintf(
										'Rest maintainer needs to set "%s" for %s using ' .
										'%s->components->Rest.Rest->actions[\'%s\'][\'%s\'] = %s',
										$exposeVar,
										$action,
										$className,
										$action,
										$exposeVar,
										$example
									));
								}
							}
						}
						$exposeActions[$action] = $saveVars;
					}

					$restControllers[$controller] = $exposeActions;
				}
				unset($Controller);
			}

			ksort($restControllers);

			if ($cached) {
				Cache::write($ckey, $restControllers);
			}
		}

		return $restControllers;
	}

	/**
	 * Determine if this action should trigger this component's magic goodness
	 * 
	 * @access public
	 * @return void
	 */
	public function isRestful () {
		if ($this->isRestful === null) {
			if (!isset($this->Controller) || !is_object($this->Controller)) {
				return false;
			}

			$this->isRestful = false;
			if ($this->onlyActiveWithAuth === true && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
				$keyword = $this->auth['keyword'];
				if ($keyword && strpos($_SERVER['HTTP_AUTHORIZATION'], $keyword) === 0) {
					$this->isRestful = true;
				}
			} elseif (!empty($this->ext) && in_array($this->ext, $this->extensions)) {
				$this->isRestful = true;
			} elseif ($this->request->is('ajax')) {
				$this->isRestful = true;
			}
		}
		return $this->isRestful;
	}

	public function validate ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 0) $format = array_shift($args);
		if (count($args) > 0) $format = vsprintf($format, $args);
		$this->_feedback['error'][] = 'validation: ' . $format;
		return false;
	}

	public function error ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 0) $format = array_shift($args);
		if (count($args) > 0) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return false;
	}

	public function debug ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 0) $format = array_shift($args);
		if (count($args) > 0) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return true;
	}

	public function info ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 0) $format = array_shift($args);
		if (count($args) > 0) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return true;
	}

	public function warning ($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();
		if (count($args) > 0) $format = array_shift($args);
		if (count($args) > 0) $format = vsprintf($format, $args);
		$this->_feedback[__FUNCTION__][] = $format;
		return false;
	}

	/**
	 * Returns (optionally) formatted feedback.
	 *
	 * @param boolean $format
	 *
	 * @return array
	 */
	public function getFeedBack($format = false) {
		if (!$format) {
			return $this->_feedback;
		}

		$feedback = array();
		foreach ($this->_feedback as $level => $messages) {
			foreach ($messages as $i => $message) {
				$feedback[] = array(
					'message' => $message,
					'level' => $level,
				);
			}
		}

		return $feedback;
	}

	/**
	 * Reformats data according to Xpaths in $take
	 *
	 * @param array $take
	 * @param array $viewVars
	 *
	 * @return array
	 */
	public function inject($take, $viewVars) {
		$data = array();
		foreach ($take as $path => $dest) {
			if (is_numeric($path)) {
				$path = $dest;
			}

			$data = Set::insert($data, $dest, Set::extract($path, $viewVars));
		}

		return $data;
	}

	/**
	 * Get an array of everything that needs to go into the Xml / Json
	 *
	 * @param array $data optional. Data collected by cake
	 *
	 * @return array
	 */
	public function response ($data = array()) {
		$feedback = $this->getFeedBack(true);

		$hasErrors           = count(@$this->_feedback['error']);
		$hasValidationErrors = count(@$this->_feedback['validate']);

		$time   = time();
		$status = ($hasErrors || $hasValidationErrors) ? 'error' : 'ok';

		if ($this->paginate) {
			$data = $this->paginate($data);
		}

		if (false === ($embed = @$this->actions[$this->Controller->action]['embed'])) {
			$response = $data;
		} else {
			$response = compact('data');
		}       

		if ($this->meta['enable']) {
			$serverKeys = array_flip($this->meta['requestKeys']);
			$server = array_intersect_key($_SERVER, $serverKeys);
			foreach ($server as $k=>$v) {
				if ($k === ($lc = strtolower($k))) {
					continue;
				}
				$server[$lc] = $v;
				unset($server[$k]);
			}

			$response['meta'] = array(
				'status' => $status,
				'feedback' => $feedback,
				'request' => $server,
				'credentials' => array(),
				'time_epoch' => gmdate('U', $time),
				'time_local' => date('r', $time),
			);
			if (!empty($this->version)) {
				$response['meta']['version'] = $this->version;
			}

			foreach ($this->auth['fields'] as $field) {
				$response['meta']['credentials'][$field] = $this->credentials($field);
			}
		}

		$dump = array(
			'data_in' => $this->postData,
			'data_out' => $data,
		);
		if ($this->meta['enable']) {
			$dump['meta'] = $response['meta'];
		}
		$this->log($dump);

		return $response;
	}

	/**
	 * Returns either string or reference to active View object
	 *
	 * @param boolean $object
	 * @param string  $ext
	 *
	 * @return mixed object or string
	 */
	public function View ($object = true, $ext = null) {
		if (!$this->isRestful()) {
			return $this->abort(
				'Rest not activated. Maybe try correct extension.'
			);
		}

		if ($ext === null) {
			$ext = $this->ext;
		}

		$base = Inflector::camelize($ext);
		if (!$object) {
			return $base;
		}

		// Keep 1 instance of the active View in ->_View
		if (!$this->_View) {
			$className = $base . 'View';

			if (!class_exists($className)) {
				$pluginRoot = dirname(dirname(dirname(__FILE__)));
				$viewFile   = $pluginRoot . '/View/' . $className . '.php';
				require_once $viewFile;
			}

			$this->_View = ClassRegistry::init('Rest.' . $className);
			if (empty($this->_View->params)) {
				$this->_View->params = $this->params;
			}
		}

		return $this->_View;
	}

	/**
	 * Could be called by e.g. ->redirect to dump
	 * an error & stop further execution.
	 *
	 * @param <type> $params
	 * @param <type> $data
	 */
	public function abort ($params = array(), $data = array()) {
		if ($this->_aborting) {
			return;
		}
		$this->_aborting = true;

		if (is_string($params)) {
			$code  = '403';
			$error = $params;
		} else {
			$code  = '200';
			$error = '';

			if (is_object($this->Controller->Session) && @$this->Controller->Session->read('Message.auth')) {
				// Automatically fetch Auth Component Errors
				$code  = '403';
				$error = $this->Controller->Session->read('Message.auth.message');
				$this->Controller->Session->delete('Message.auth');
			}

			if (!empty($params['status'])) {
				$code = $params['status'];
			}
			if (!empty($params['error'])) {
				$error = $params['error'];
			}

			if (empty($error) && !empty($params['redirect'])) {
				$this->debug('Redirect prevented by rest component. ');
			}
		}
		if ($error) {
			$this->error($error);
		}
		$this->Controller->response->statusCode($code);

		$encoded = $this->View()->encode($this->response($data));

		// Die.. ugly. but very safe. which is what we need
		// or all Auth & Acl work could be circumvented
		$this->log(array(
			'httpcode' => $code,
			'error' => $error,
		));
		$this->shutdown($this->Controller);
		die($encoded);
	}

	/**
	 * Ensure that the Security component is active when this component has requireSecure
	 * set to true.
	 * 
	 * @access public
	 * @return void
	 */
	public function initializeSecurity() {
		if (!empty($this->auth['requireSecure'])) {
			if (!isset($this->Controller->Security) || !is_object($this->Controller->Security)) {
				$message = __('You need to enable the Security component first');
				throw new InternalErrorException($message);
			}
			$this->Controller->Security->requireSecure($this->auth['requireSecure']);
		}
	}

	public function paginate($data) {
		$Controller =& $this->Controller;
		$action = $Controller->action;
		$modelClass = $Controller->modelClass;
		$extract = (array)@$this->actions[$action]['extract'];
		$key = Inflector::tableize($modelClass);
		if (in_array($key, array_values($extract))) {
			if (isset($Controller->params['paging']) && array_key_exists($modelClass, $Controller->params['paging'])) {
				$page = $Controller->params['paging'][$modelClass]['page'];
				$total = $Controller->params['paging'][$modelClass]['count'];
				$per_page = $Controller->params['paging'][$modelClass]['limit'];
				$models = $data[$key];
				$data = compact('models', 'page', 'total', 'per_page');
			} else {
				$data = $data[$key];
			}
		}
		return $data;
	}

	/**
	 * Create initial log during initialization of RestComponent
	 * during a restful operation. 
	 * 
	 * @access public
	 * @return void
	 */
	public function initializeLog() {
		$controller = $this->Controller->name;
		$action = $this->controllerAction;
		if (isset($this->Controller->passedArgs[0])) {
			$model_id = $this->Controller->passedArgs[0];
		} else {
			$model_id = 0;
		}
		$ratelimited = 0;
		$requested = date('Y-m-d H:i:s');
		$ip = $_SERVER['REMOTE_ADDR'];
		$httpcode = 200;

		$this->log(compact('controller', 'action', 'model_id', 'ratelimited', 'requested', 'ip', 'httpcode'));
	}

	/**
	 * Get the setting for the current controller action listed in $key 
	 * 
	 * @param mixed $key 
	 * @access public
	 * @return mixed
	 */
	public function getActionSetting($key) {
		$action = $this->controllerAction;
		if (array_key_exists($action, $this->actions) && array_key_exists($key, $this->actions[$action])) {
			return $this->actions[$action][$key];
		} else {
			return null;
		}
	}

	/**
	 * Move any lowercase keys in POST data to a key representing the model. 
	 * 
	 * @param mixed $input 
	 * @param mixed $modelClass 
	 * @access public
	 * @return void
	 */
	public function unrootModelData($input, $modelClass) {
		$input = json_decode($input, true);
		if (!array_key_exists($modelClass, $input)) {
			$input[$modelClass] = array();
			foreach ($input as $key => $val) {
				if (!preg_match('/^[A-Z]{1}/', $key)) {
					if (!($key == 'created' && $key == 'modified')) {
						$input[$modelClass][$key] = $val;
					}
					unset($input[$key]);
				}
			}
		}
		return $input;
	}

	/**
	 * Sets callbackFunc variable in viewVars when a callback is requested
	 * and when it is safe (XSS) to do so. The following query params will
	 * trigger the callback: jsoncallback & callback based upon the public
	 * class variable $jsonCallbackKeys
	 * 
	 * @access public
	 * @return void
	 */
	public function setCallbackResponse() {
		$callback = false;
		foreach ($this->jsonCallbackKeys as $key) {
			if (array_key_exists($key, $this->params['url'])) {
				$callback = $this->params['url'][$key];
			}
		}
		if ($callback) {
			// Checks for callback keys which are vulnerable to XSS
			if (preg_match('/\W/', $callback)) {
				$message = __('Prevented request. Your callback is vulnerable to XSS attacks.');
				throw new MethodNotAllowedException($message);
			}
			$this->Controller->set('callbackFunc', $callback);
		}
	}

	/**
	 * When the request is extensionless but the request is an AJAX request
	 * an extension is added to $this->ext dependent upon whether the client
	 * prefers XML or JSON 
	 * 
	 * @access public
	 * @return void
	 */
	public function setExtensionForAjaxRequest() {
		if (empty($this->ext) && $this->request->is('ajax')) {
			if ($this->prefers('json')) {
				$this->ext = 'json';
			} elseif ($this->prefers('xml')) {
				$this->ext = 'xml';
			}
		}
	}
}
