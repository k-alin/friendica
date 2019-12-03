<?php

/**
 * @file /src/Core/Authentication.php
 */

namespace Friendica\Core;

use Exception;
use Friendica\App;
use Friendica\Core\Config\Configuration;
use Friendica\Database\Database;
use Friendica\Database\DBA;
use Friendica\Model\User;
use Friendica\Network\HTTPException;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use LightOpenID;
use Friendica\Core\L10n\L10n;
use Psr\Log\LoggerInterface;

/**
 * Handle Authentification, Session and Cookies
 */
class Authentication
{
	/** @var Configuration */
	private $config;
	/** @var App\BaseURL */
	private $baseUrl;
	/** @var L10n */
	private $l10n;
	/** @var Database */
	private $dba;
	/** @var LoggerInterface */
	private $logger;

	/**
	 * Authentication constructor.
	 *
	 * @param Configuration   $config
	 * @param App\BaseURL     $baseUrl
	 * @param L10n            $l10n
	 * @param Database        $dba
	 * @param LoggerInterface $logger
	 */
	public function __construct(Configuration $config, App\BaseURL $baseUrl, L10n $l10n, Database $dba, LoggerInterface $logger)
	{
		$this->config  = $config;
		$this->baseUrl = $baseUrl;
		$this->l10n    = $l10n;
		$this->dba     = $dba;
		$this->logger  = $logger;
	}

	/**
	 * @brief Tries to auth the user from the cookie or session
	 *
	 * @param App   $a      The Friendica Application context
	 * @param array $cookie The $_COOKIE array
	 *
	 * @throws HttpException\InternalServerErrorException In case of Friendica internal exceptions
	 * @throws Exception In case of general exceptions (like SQL Grammar)
	 */
	public function withSession(App $a, array $cookie)
	{
		// When the "Friendica" cookie is set, take the value to authenticate and renew the cookie.
		if (isset($cookie["Friendica"])) {
			$data = json_decode($cookie["Friendica"]);
			if (isset($data->uid)) {

				$user = $this->dba->selectFirst(
					'user',
					[],
					[
						'uid'             => $data->uid,
						'blocked'         => false,
						'account_expired' => false,
						'account_removed' => false,
						'verified'        => true,
					]
				);
				if (DBA::isResult($user)) {
					if (!Session::checkCookie($data->hash, $user)) {
						$this->logger->notice("Hash doesn't fit.", ['user' => $data->uid]);
						Session::delete();
						$this->baseUrl->redirect();
					}

					// Renew the cookie
					// Expires after 7 days by default,
					// can be set via system.auth_cookie_lifetime
					$authcookiedays = $this->config->get('system', 'auth_cookie_lifetime', 7);
					Session::setCookie($authcookiedays * 24 * 60 * 60, $user);

					// Do the authentification if not done by now
					if (!Session::get('authenticated')) {
						$this->setForUser($a, $user);

						if ($this->config->get('system', 'paranoia')) {
							Session::set('addr', $data->ip);
						}
					}
				}
			}
		}

		if (Session::get('authenticated')) {
			if (Session::get('visitor_id') && !Session::get('uid')) {
				$contact = $this->dba->selectFirst('contact', [], ['id' => Session::get('visitor_id')]);
				if ($this->dba->isResult($contact)) {
					$a->contact = $contact;
				}
			}

			if (Session::get('uid')) {
				// already logged in user returning
				$check = $this->config->get('system', 'paranoia');
				// extra paranoia - if the IP changed, log them out
				if ($check && (Session::get('addr') != $_SERVER['REMOTE_ADDR'])) {
					$this->logger->notice('Session address changed. Paranoid setting in effect, blocking session. ', [
							'addr'        => Session::get('addr'),
							'remote_addr' => $_SERVER['REMOTE_ADDR']]
					);
					Session::delete();
					$this->baseUrl->redirect();
				}

				$user = $this->dba->selectFirst(
					'user',
					[],
					[
						'uid'             => Session::get('uid'),
						'blocked'         => false,
						'account_expired' => false,
						'account_removed' => false,
						'verified'        => true,
					]
				);
				if (!$this->dba->isResult($user)) {
					Session::delete();
					$this->baseUrl->redirect();
				}

				// Make sure to refresh the last login time for the user if the user
				// stays logged in for a long time, e.g. with "Remember Me"
				$login_refresh = false;
				if (!Session::get('last_login_date')) {
					Session::set('last_login_date', DateTimeFormat::utcNow());
				}
				if (strcmp(DateTimeFormat::utc('now - 12 hours'), Session::get('last_login_date')) > 0) {
					Session::set('last_login_date', DateTimeFormat::utcNow());
					$login_refresh = true;
				}

				$this->setForUser($a, $user, false, false, $login_refresh);
			}
		}
	}

	/**
	 * Attempts to authenticate using OpenId
	 *
	 * @param string $openid_url OpenID URL string
	 * @param bool   $remember   Whether to set the session remember flag
	 *
	 * @throws HttpException\InternalServerErrorException In case of Friendica internal exceptions
	 */
	public function withOpenId(string $openid_url, bool $remember)
	{
		$noid = $this->config->get('system', 'no_openid');

		// if it's an email address or doesn't resolve to a URL, fail.
		if ($noid || strpos($openid_url, '@') || !Network::isUrlValid($openid_url)) {
			notice($this->l10n->t('Login failed.') . EOL);
			$this->baseUrl->redirect();
		}

		// Otherwise it's probably an openid.
		try {
			$openid           = new LightOpenID($this->baseUrl->getHostname());
			$openid->identity = $openid_url;
			Session::set('openid', $openid_url);
			Session::set('remember', $remember);
			$openid->returnUrl = $this->baseUrl->get(true) . '/openid';
			$openid->optional  = ['namePerson/friendly', 'contact/email', 'namePerson', 'namePerson/first', 'media/image/aspect11', 'media/image/default'];
			System::externalRedirect($openid->authUrl());
		} catch (Exception $e) {
			notice($this->l10n->t('We encountered a problem while logging in with the OpenID you provided. Please check the correct spelling of the ID.') . '<br /><br >' . $this->l10n->t('The error message was:') . ' ' . $e->getMessage());
		}
	}

	/**
	 * Attempts to authenticate using login/password
	 *
	 * @param App    $a        The Friendica Application context
	 * @param string $username User name
	 * @param string $password Clear password
	 * @param bool   $remember Whether to set the session remember flag
	 *
	 * @throws HttpException\InternalServerErrorException In case of Friendica internal exceptions
	 * @throws Exception A general Exception (like SQL Grammar exceptions)
	 */
	public function withPassword(App $a, string $username, string $password, bool $remember)
	{
		$record = null;

		$addon_auth = [
			'username'      => $username,
			'password'      => $password,
			'authenticated' => 0,
			'user_record'   => null
		];

		/*
		 * An addon indicates successful login by setting 'authenticated' to non-zero value and returning a user record
		 * Addons should never set 'authenticated' except to indicate success - as hooks may be chained
		 * and later addons should not interfere with an earlier one that succeeded.
		 */
		Hook::callAll('authenticate', $addon_auth);

		try {
			if ($addon_auth['authenticated']) {
				$record = $addon_auth['user_record'];

				if (empty($record)) {
					throw new Exception($this->l10n->t('Login failed.'));
				}
			} else {
				$record = $this->dba->selectFirst(
					'user',
					[],
					['uid' => User::getIdFromPasswordAuthentication($username, $password)]
				);
			}
		} catch (Exception $e) {
			$this->logger->warning('authenticate: failed login attempt', ['action' => 'login', 'username' => Strings::escapeTags($username), 'ip' => $_SERVER['REMOTE_ADDR']]);
			info($this->l10n->t('Login failed. Please check your credentials.' . EOL));
			$this->baseUrl->redirect();
		}

		if (!$remember) {
			Session::setCookie(0); // 0 means delete on browser exit
		}

		// if we haven't failed up this point, log them in.
		Session::set('remember', $remember);
		Session::set('last_login_date', DateTimeFormat::utcNow());

		$openid_identity = Session::get('openid_identity');
		$openid_server   = Session::get('openid_server');

		if (!empty($openid_identity) || !empty($openid_server)) {
			$this->dba->update('user', ['openid' => $openid_identity, 'openidserver' => $openid_server], ['uid' => $record['uid']]);
		}

		$this->setForUser($a, $record, true, true);

		$return_path = Session::get('return_path', '');
		Session::remove('return_path');

		$this->baseUrl->redirect($return_path);
	}

	/**
	 * @brief Sets the provided user's authenticated session
	 *
	 * @param App   $a           The Friendica application context
	 * @param array $user_record The current "user" record
	 * @param bool  $login_initial
	 * @param bool  $interactive
	 * @param bool  $login_refresh
	 *
	 * @throws HTTPException\InternalServerErrorException In case of Friendica specific exceptions
	 * @throws Exception In case of general Exceptions (like SQL Grammar exceptions)
	 */
	public function setForUser(App $a, array $user_record, bool $login_initial = false, bool $interactive = false, bool $login_refresh = false)
	{
		Session::setMultiple([
			'uid'           => $user_record['uid'],
			'theme'         => $user_record['theme'],
			'mobile-theme'  => PConfig::get($user_record['uid'], 'system', 'mobile_theme'),
			'authenticated' => 1,
			'page_flags'    => $user_record['page-flags'],
			'my_url'        => $this->baseUrl->get() . '/profile/' . $user_record['nickname'],
			'my_address'    => $user_record['nickname'] . '@' . substr($this->baseUrl->get(), strpos($this->baseUrl->get(), '://') + 3),
			'addr'          => ($_SERVER['REMOTE_ADDR'] ?? '') ?: '0.0.0.0'
		]);

		Session::setVisitorsContacts();

		$member_since = strtotime($user_record['register_date']);
		Session::set('new_member', time() < ($member_since + (60 * 60 * 24 * 14)));

		if (strlen($user_record['timezone'])) {
			date_default_timezone_set($user_record['timezone']);
			$a->timezone = $user_record['timezone'];
		}

		$masterUid = $user_record['uid'];

		if (Session::get('submanage')) {
			$user = $this->dba->selectFirst('user', ['uid'], ['uid' => Session::get('submanage')]);
			if ($this->dba->isResult($user)) {
				$masterUid = $user['uid'];
			}
		}

		$a->identities = User::identities($masterUid);

		if ($login_initial) {
			$this->logger->info('auth_identities: ' . print_r($a->identities, true));
		}

		if ($login_refresh) {
			$this->logger->info('auth_identities refresh: ' . print_r($a->identities, true));
		}

		$contact = $this->dba->selectFirst('contact', [], ['uid' => $user_record['uid'], 'self' => true]);
		if ($this->dba->isResult($contact)) {
			$a->contact = $contact;
			$a->cid     = $contact['id'];
			Session::set('cid', $a->cid);
		}

		header('X-Account-Management-Status: active; name="' . $user_record['username'] . '"; id="' . $user_record['nickname'] . '"');

		if ($login_initial || $login_refresh) {
			$this->dba->update('user', ['login_date' => DateTimeFormat::utcNow()], ['uid' => $user_record['uid']]);

			// Set the login date for all identities of the user
			$this->dba->update('user', ['login_date' => DateTimeFormat::utcNow()],
				['parent-uid' => $masterUid, 'account_removed' => false]);
		}

		if ($login_initial) {
			/*
			 * If the user specified to remember the authentication, then set a cookie
			 * that expires after one week (the default is when the browser is closed).
			 * The cookie will be renewed automatically.
			 * The week ensures that sessions will expire after some inactivity.
			 */;
			if (Session::get('remember')) {
				$a->getLogger()->info('Injecting cookie for remembered user ' . $user_record['nickname']);
				Session::setCookie(604800, $user_record);
				Session::remove('remember');
			}
		}

		$this->twoFactorCheck($user_record['uid'], $a);

		if ($interactive) {
			if ($user_record['login_date'] <= DBA::NULL_DATETIME) {
				info($this->l10n->t('Welcome %s', $user_record['username']));
				info($this->l10n->t('Please upload a profile photo.'));
				$this->baseUrl->redirect('profile_photo/new');
			} else {
				info($this->l10n->t("Welcome back %s", $user_record['username']));
			}
		}

		$a->user = $user_record;

		if ($login_initial) {
			Hook::callAll('logged_in', $a->user);

			if ($a->module !== 'home' && Session::exists('return_path')) {
				$this->baseUrl->redirect(Session::get('return_path'));
			}
		}
	}

	/**
	 * @param int $uid The User Identified
	 * @param App $a   The Friendica Application context
	 *
	 * @throws HTTPException\ForbiddenException In case the two factor authentication is forbidden (e.g. for AJAX calls)
	 */
	private function twoFactorCheck(int $uid, App $a)
	{
		// Check user setting, if 2FA disabled return
		if (!PConfig::get($uid, '2fa', 'verified')) {
			return;
		}

		// Check current path, if 2fa authentication module return
		if ($a->argc > 0 && in_array($a->argv[0], ['2fa', 'view', 'help', 'api', 'proxy', 'logout'])) {
			return;
		}

		// Case 1: 2FA session present and valid: return
		if (Session::get('2fa')) {
			return;
		}

		// Case 2: No valid 2FA session: redirect to code verification page
		if ($a->isAjax()) {
			throw new HTTPException\ForbiddenException();
		} else {
			$a->internalRedirect('2fa');
		}
	}
}
