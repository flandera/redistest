<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\Http;

use Nette;


/**
 * Provides access to session sections as well as session settings and management methods.
 */
class Session
{
	use Nette\SmartObject;

	/** Default file lifetime */
	const DEFAULT_FILE_LIFETIME = 3 * Nette\Utils\DateTime::HOUR;
	/** @var bool  has been session started? */
	private static $started = false;
	/** @var bool  has been session ID regenerated? */
	private $regenerated = false;
	/** @var array default configuration */
	private $options = [
		// security
		'referer_check' => '',    // must be disabled because PHP implementation is invalid
		'use_cookies' => 1,       // must be enabled to prevent Session Hijacking and Fixation
		'use_only_cookies' => 1,  // must be enabled to prevent Session Fixation
		'use_trans_sid' => 0,     // must be disabled to prevent Session Hijacking and Fixation

		// cookies
		'cookie_lifetime' => 0,   // until the browser is closed
		'cookie_path' => '/',     // cookie is available within the entire domain
		'cookie_domain' => '',    // cookie is available on current subdomain only
		'cookie_secure' => false, // cookie is available on HTTP & HTTPS
		'cookie_httponly' => true, // must be enabled to prevent Session Hijacking

		// other
		'gc_maxlifetime' => self::DEFAULT_FILE_LIFETIME, // 3 hours
	];

	/** @var IRequest */
	private $request;

	/** @var IResponse */
	private $response;

	/** @var \SessionHandlerInterface */
	private $handler;


	public function __construct(IRequest $request, IResponse $response)
	{
		$this->request = $request;
		$this->response = $response;
	}

	/**
	 * Has been session started?
	 * @return bool
	 */
	public function isStarted()
	{
		return (bool) self::$started;
	}

	/**
	 * Ends the current session and store session data.
	 * @return void
	 */
	public function close()
	{
		if (self::$started) {
			$this->clean();
			session_write_close();
			self::$started = false;
		}
	}

	/**
	 * Cleans and minimizes meta structures. This method is called automatically on shutdown, do not call it directly.
	 * @internal
	 * @return void
	 */
	public function clean()
	{
		if (!self::$started || empty($_SESSION)) {
			return;
		}

		$nf = &$_SESSION['__NF'];
		if (isset($nf['META']) && is_array($nf['META'])) {
			foreach ($nf['META'] as $name => $foo) {
				if (empty($nf['META'][$name])) {
					unset($nf['META'][$name]);
				}
			}
		}

		if (empty($nf['META'])) {
			unset($nf['META']);
		}

		if (empty($nf['DATA'])) {
			unset($nf['DATA']);
		}
	}

	/**
	 * Destroys all data registered to a session.
	 * @return void
	 */
	public function destroy()
	{
		if (!self::$started) {
			throw new Nette\InvalidStateException('Session is not started.');
		}

		session_destroy();
		$_SESSION = null;
		self::$started = false;
		if (!$this->response->isSent()) {
			$params = session_get_cookie_params();
			$this->response->deleteCookie(session_name(), $params['path'], $params['domain'], $params['secure']);
		}
	}

	/**
	 * Returns the current session ID. Don't make dependencies, can be changed for each request.
	 * @return string
	 */
	public function getId()
	{
		return session_id();
	}

	/**
	 * Sets the session name to a specified one.
	 * @param  string
	 * @return static
	 */
	public function setName($name)
	{
		if (!is_string($name) || !preg_match('#[^0-9.][^.]*\z#A', $name)) {
			throw new Nette\InvalidArgumentException('Session name must be a string and cannot contain dot.');
		}

		session_name($name);
		return $this->setOptions([
			'name' => $name,
		]);
	}

	/**
	 * Returns specified session section.
	 * @param  string
	 * @param  string
	 * @return SessionSection
	 * @throws Nette\InvalidArgumentException
	 */
	public function getSection($section, $class = SessionSection::class)
	{
		return new $class($this, $section);
	}

	/**
	 * Checks if a session section exist and is not empty.
	 * @param  string
	 * @return bool
	 */
	public function hasSection($section)
	{
		if ($this->exists() && !self::$started) {
			$this->start();
		}

		return !empty($_SESSION['__NF']['DATA'][$section]);
	}

	/**
	 * Does session exists for the current request?
	 * @return bool
	 */
	public function exists()
	{
		return self::$started || $this->request->getCookie($this->getName()) !== null;
	}


	/********************* sections management ****************d*g**/

	/**
	 * Gets the session name.
	 * @return string
	 */
	public function getName()
	{
		return isset($this->options['name']) ? $this->options['name'] : session_name();
	}

	/**
	 * Starts and initializes session data.
	 * @throws Nette\InvalidStateException
	 * @return void
	 */
	public function start()
	{
		if (self::$started) {
			return;
		}

		$this->configure($this->options);

		if (!session_id()) {
			$id = $this->request->getCookie(session_name());
			if (is_string($id) && preg_match('#^[0-9a-zA-Z,-]{22,256}\z#i', $id)) {
				session_id($id);
			} else {
				unset($_COOKIE[session_name()]);
			}
		}

		try {
			// session_start returns false on failure only sometimes
			Nette\Utils\Callback::invokeSafe('session_start', [], function ($message) use (&$e) {
				$e = new Nette\InvalidStateException($message);
			});
		} catch (\Exception $e) {
		}

		if ($e) {
			@session_write_close(); // this is needed
			throw $e;
		}

		self::$started = true;

		/* structure:
			__NF: Data, Meta, Time
				DATA: section->variable = data
				META: section->variable = Timestamp
		*/
		$nf = &$_SESSION['__NF'];

		if (!is_array($nf)) {
			$nf = [];
		}

		// regenerate empty session
		if (empty($nf['Time'])) {
			$nf['Time'] = time();
			$this->regenerated = true;
		}

		// resend cookie
		$this->sendCookie();

		// process meta metadata
		if (isset($nf['META'])) {
			$now = time();
			// expire section variables
			foreach ($nf['META'] as $section => $metadata) {
				if (is_array($metadata)) {
					foreach ($metadata as $variable => $value) {
						if (!empty($value['T']) && $now > $value['T']) {
							if ($variable === '') { // expire whole section
								unset($nf['META'][$section], $nf['DATA'][$section]);
								continue 2;
							}
							unset($nf['META'][$section][$variable], $nf['DATA'][$section][$variable]);
						}
					}
				}
			}
		}

		if ($this->regenerated) {
			$this->regenerated = false;
			$this->regenerateId();
		}

		register_shutdown_function([$this, 'clean']);
	}

	/**
	 * Configures session environment.
	 * @param  array
	 * @return void
	 */
	private function configure(array $config)
	{
		$special = ['cache_expire' => 1, 'cache_limiter' => 1, 'save_path' => 1, 'name' => 1];
		$cookie = $origCookie = session_get_cookie_params();

		foreach ($config as $key => $value) {
			if ($value === null || ini_get("session.$key") == $value) { // intentionally ==
				continue;

			} elseif (strncmp($key, 'cookie_', 7) === 0) {
				$cookie[substr($key, 7)] = $value;

			} else {
				if (session_status() === PHP_SESSION_ACTIVE) {
					throw new Nette\InvalidStateException("Unable to set 'session.$key' to value '$value' when session has been started" . (self::$started ? '.' : ' by session.auto_start or session_start().'));
				}
				if (isset($special[$key])) {
					$key = "session_$key";
					$key($value);

				} elseif (function_exists('ini_set')) {
					ini_set("session.$key", (string) $value);

				} elseif (ini_get("session.$key") != $value) { // intentionally !=
					throw new Nette\NotSupportedException("Unable to set 'session.$key' to '$value' because function ini_set() is disabled.");
				}
			}
		}

		if ($cookie !== $origCookie) {
			session_set_cookie_params(
				$cookie['lifetime'], $cookie['path'], $cookie['domain'],
				$cookie['secure'], $cookie['httponly']
			);
			if (self::$started) {
				$this->sendCookie();
			}
		}

		if ($this->handler) {
			session_set_save_handler($this->handler);
		}
	}

	/**
	 * Sends the session cookies.
	 * @return void
	 */
	private function sendCookie()
	{
		$cookie = $this->getCookieParameters();
		$this->response->setCookie(
			session_name(), session_id(),
			$cookie['lifetime'] ? $cookie['lifetime'] + time() : 0,
			$cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']
		);
	}


	/********************* configuration ****************d*g**/

	/**
	 * Returns the session cookie parameters.
	 * @return array  containing items: lifetime, path, domain, secure, httponly
	 */
	public function getCookieParameters()
	{
		return session_get_cookie_params();
	}

	/**
	 * Regenerates the session ID.
	 * @throws Nette\InvalidStateException
	 * @return void
	 */
	public function regenerateId()
	{
		if (self::$started && !$this->regenerated) {
			if (headers_sent($file, $line)) {
				throw new Nette\InvalidStateException('Cannot regenerate session ID after HTTP headers have been sent' . ($file ? " (output started at $file:$line)." : '.'));
			}
			if (session_status() === PHP_SESSION_ACTIVE) {
				session_regenerate_id(true);
				session_write_close();
			}
			$backup = $_SESSION;
			session_start();
			$_SESSION = $backup;
		}
		$this->regenerated = true;
	}

	/**
	 * Iteration over all sections.
	 * @return \Iterator
	 */
	public function getIterator()
	{
		if ($this->exists() && !self::$started) {
			$this->start();
		}

		if (isset($_SESSION['__NF']['DATA'])) {
			return new \ArrayIterator(array_keys($_SESSION['__NF']['DATA']));

		} else {
			return new \ArrayIterator;
		}
	}

	/**
	 * Returns all session options.
	 * @return array
	 */
	public function getOptions()
	{
		return $this->options;
	}

	/**
	 * Sets session options.
	 * @param  array
	 * @return static
	 * @throws Nette\NotSupportedException
	 * @throws Nette\InvalidStateException
	 */
	public function setOptions(array $options)
	{
		$normalized = [];
		foreach ($options as $key => $value) {
			if (!strncmp($key, 'session.', 8)) { // back compatibility
				$key = substr($key, 8);
			}
			$key = strtolower(preg_replace('#(.)(?=[A-Z])#', '$1_', $key)); // camelCase -> snake_case
			$normalized[$key] = $value;
		}
		if (self::$started) {
			$this->configure($normalized);
		}
		$this->options = $normalized + $this->options;
		if (!empty($normalized['auto_start'])) {
			$this->start();
		}
		return $this;
	}

	/**
	 * Sets the amount of time allowed between requests before the session will be terminated.
	 * @param  string|int|\DateTimeInterface  time, value 0 means "until the browser is closed"
	 * @return static
	 */
	public function setExpiration($time)
	{
		if (empty($time)) {
			return $this->setOptions([
				'gc_maxlifetime' => self::DEFAULT_FILE_LIFETIME,
				'cookie_lifetime' => 0,
			]);

		} else {
			$time = Nette\Utils\DateTime::from($time)->format('U') - time();
			return $this->setOptions([
				'gc_maxlifetime' => $time,
				'cookie_lifetime' => $time,
			]);
		}
	}

	/**
	 * Sets the session cookie parameters.
	 * @param  string  path
	 * @param  string  domain
	 * @param  bool    secure
	 * @return static
	 */
	public function setCookieParameters($path, $domain = null, $secure = null)
	{
		return $this->setOptions([
			'cookie_path' => $path,
			'cookie_domain' => $domain,
			'cookie_secure' => $secure,
		]);
	}

	/**
	 * Sets path of the directory used to save session data.
	 * @return static
	 */
	public function setSavePath($path)
	{
		return $this->setOptions([
			'save_path' => $path,
		]);
	}

	/**
	 * @deprecated  use setHandler().
	 * @return static
	 */
	public function setStorage(ISessionStorage $storage)
	{
		if (self::$started) {
			throw new Nette\InvalidStateException('Unable to set storage when session has been started.');
		}
		session_set_save_handler(
			[$storage, 'open'], [$storage, 'close'], [$storage, 'read'],
			[$storage, 'write'], [$storage, 'remove'], [$storage, 'clean']
		);
		return $this;
	}

	/**
	 * Sets user session handler.
	 * @return static
	 */
	public function setHandler(\SessionHandlerInterface $handler)
	{
		if (self::$started) {
			throw new Nette\InvalidStateException('Unable to set handler when session has been started.');
		}
		$this->handler = $handler;
		return $this;
	}
}
