<?php

/**
 * @file include/account.php
 * @brief Some account related functions.
 */

use Zotlabs\Lib\Crypto;


function get_account_by_id($account_id) {
	$r = q("select * from account where account_id = %d",
		intval($account_id)
	);
	return (($r) ? array_shift($r) : false);
}

function check_account_email($email) {

	$email = punify($email);
	$result = [ 'error' => false, 'message' => '' ];

	// Caution: empty email isn't counted as an error in this function. 
	// Check for empty value separately. 

	if (! strlen($email)) {
		return $result;
	}

	if (! validate_email($email)) {
		$result['message'] .= t('Not a valid email address') . EOL;
	}
	elseif (! allowed_email($email)) {
		$result['message'] = t('Your email domain is not among those allowed on this site');
	}
	else {	
		$r = q("select account_email from account where account_email = '%s' limit 1",
			dbesc($email)
		);
		if ($r) {
			$result['message'] .= t('Your email address is already registered at this site.');
		}
	}
	if ($result['message']) {
		$result['error'] = true;
	}

	$arr = array('email' => $email, 'result' => $result);
	call_hooks('check_account_email', $arr);

	return $arr['result'];
}

function check_account_password($password) {
	$result = [ 'error' => false, 'message' => '' ];

	// The only validation we perform by default is pure Javascript to
	// check minimum length and that both entered passwords match.
	// Use hooked functions to perform complexity requirement checks.

	$arr = [ 'password' => $password, 'result' => $result ];
	call_hooks('check_account_password', $arr);

	return $arr['result'];
}

function check_account_invite($invite_code) {
	$result = [ 'error' => false, 'message' => '' ];

	$using_invites = get_config('system','invitation_only');

	if ($using_invites) {
		if (! $invite_code) {
			$result['message'] .= t('An invitation is required.') . EOL;
		}
		$r = q("select * from register where hash = '%s' limit 1", dbesc($invite_code));
		if (! $r) {
			$result['message'] .= t('Invitation could not be verified.') . EOL;
		}
	}
	if (strlen($result['message'])) {
		$result['error'] = true;
	}

	$arr = [ 'invite_code' => $invite_code, 'result' => $result ];
	call_hooks('check_account_invite', $arr);

	return $arr['result'];
}

function check_account_admin($arr) {
	if (is_site_admin()) {
		return true;
	}
	$admin_email = trim(get_config('system','admin_email'));
	if (strlen($admin_email) && $admin_email === trim($arr['email'])) {
		return true;
	}
	return false;
}

function account_total() {
	$r = q("select account_id from account where true");
	// Distinguish between an empty array and an error
	if (is_array($r)) {
		return count($r);
	}
	return false;
}


function account_store_lowlevel($arr) {

    $store = [
        'account_parent'           => ((array_key_exists('account_parent',$arr))           ? $arr['account_parent']           : '0'),
        'account_default_channel'  => ((array_key_exists('account_default_channel',$arr))  ? $arr['account_default_channel']  : '0'),
        'account_salt'             => ((array_key_exists('account_salt',$arr))             ? $arr['account_salt']             : ''),
        'account_password'         => ((array_key_exists('account_password',$arr))         ? $arr['account_password']         : ''),
        'account_email'            => ((array_key_exists('account_email',$arr))            ? $arr['account_email']            : ''),
        'account_external'         => ((array_key_exists('account_external',$arr))         ? $arr['account_external']         : ''),
        'account_language'         => ((array_key_exists('account_language',$arr))         ? $arr['account_language']         : 'en'),
        'account_created'          => ((array_key_exists('account_created',$arr))          ? $arr['account_created']          : '0001-01-01 00:00:00'),
        'account_lastlog'          => ((array_key_exists('account_lastlog',$arr))          ? $arr['account_lastlog']          : '0001-01-01 00:00:00'),
        'account_flags'            => ((array_key_exists('account_flags',$arr))            ? $arr['account_flags']            : '0'),
        'account_roles'            => ((array_key_exists('account_roles',$arr))            ? $arr['account_roles']            : '0'),
        'account_reset'            => ((array_key_exists('account_reset',$arr))            ? $arr['account_reset']            : ''),
        'account_expires'          => ((array_key_exists('account_expires',$arr))          ? $arr['account_expires']          : '0001-01-01 00:00:00'),
        'account_expire_notified'  => ((array_key_exists('account_expire_notified',$arr))  ? $arr['account_expire_notified']  : '0001-01-01 00:00:00'),
        'account_service_class'    => ((array_key_exists('account_service_class',$arr))    ? $arr['account_service_class']    : ''),
        'account_level'            => ((array_key_exists('account_level',$arr))            ? $arr['account_level']            : '0'),
        'account_password_changed' => ((array_key_exists('account_password_changed',$arr)) ? $arr['account_password_changed'] : '0001-01-01 00:00:00')
	];

	return create_table_from_array('account',$store);

}


function create_account($arr) {

	// Required: { email, password }

	$result = [ 'success' => false, 'email' => '', 'password' => '', 'message' => '' ];

	$invite_code = ((x($arr,'invite_code'))   ? notags(trim($arr['invite_code']))  : '');
	$email       = ((x($arr,'email'))         ? notags(punify(trim($arr['email']))) : '');
	$password    = ((x($arr,'password'))      ? trim($arr['password'])             : '');
	$password2   = ((x($arr,'password2'))     ? trim($arr['password2'])            : '');
	$parent      = ((x($arr,'parent'))        ? intval($arr['parent'])             : 0 );
	$flags       = ((x($arr,'account_flags')) ? intval($arr['account_flags'])      : ACCOUNT_OK);
	$roles       = ((x($arr,'account_roles')) ? intval($arr['account_roles'])      : 0 );
	$expires     = ((x($arr,'expires'))       ? intval($arr['expires'])            : NULL_DATE);

	$default_service_class = get_config('system','default_service_class', EMPTY_STR);

	if (! ($email && $password)) {
		$result['message'] = t('Please enter the required information.');
		return $result;
	}

	// prevent form hackery

	if (($roles & ACCOUNT_ROLE_ADMIN) && (! check_account_admin($arr))) {
		$roles = $roles - ACCOUNT_ROLE_ADMIN;
	}

	// allow the admin_email account to be admin, but only if it's the first account.

	$c = account_total();
	if (($c === 0) && (check_account_admin($arr))) {
		$roles |= ACCOUNT_ROLE_ADMIN;
	}

	// Ensure that there is a host keypair.

	if ((! get_config('system', 'pubkey')) && (! get_config('system', 'prvkey'))) {
		$hostkey = Crypto::new_keypair(4096);
		set_config('system', 'pubkey', $hostkey['pubkey']);
		set_config('system', 'prvkey', $hostkey['prvkey']);
	}

	$invite_result = check_account_invite($invite_code);
	if ($invite_result['error']) {
		$result['message'] = $invite_result['message'];
		return $result;
	}

	$email_result = check_account_email($email);

	if ($email_result['error']) {
		$result['message'] = $email_result['message'];
		return $result;
	}

	$password_result = check_account_password($password);

	if ($password_result['error']) {
		$result['message'] = $password_result['message'];
		return $result;
	}

	$salt = random_string(32);
	$password_encoded = hash('whirlpool', $salt . $password);

	$r = account_store_lowlevel(
		[
			'account_parent'        => intval($parent),
			'account_salt'          => $salt,
			'account_password'      => $password_encoded,
			'account_email'         => $email,
			'account_language'      => get_best_language(),
			'account_created'       => datetime_convert(),
			'account_flags'         => intval($flags),
			'account_roles'         => intval($roles),
			'account_expires'       => $expires,
			'account_service_class' => $default_service_class
		]
	);
	if (! $r) {
		logger('create_account: DB INSERT failed.');
		$result['message'] = t('Failed to store account information.');
		return($result);
	}

	$r = q("select * from account where account_email = '%s' and account_password = '%s' limit 1",
		dbesc($email),
		dbesc($password_encoded)
	);
	if ($r && count($r)) {
		$result['account'] = $r[0];
	}
	else {
		logger('create_account: could not retrieve newly created account');
	}

	// Set the parent record to the current record_id if no parent was provided

	if (! $parent) {
		$r = q("update account set account_parent = %d where account_id = %d",
			intval($result['account']['account_id']),
			intval($result['account']['account_id'])
		);
		if (! $r) {
			logger('create_account: failed to set parent');
		}
		$result['account']['parent'] = $result['account']['account_id'];
	}

	$result['success']  = true;
	$result['email']    = $email;
	$result['password'] = $password;

	call_hooks('register_account',$result);

	return $result;
}



function verify_email_address($arr) {

	if (array_key_exists('resend',$arr)) {
		$email = $arr['email'];
		$a = q("select * from account where account_email = '%s' limit 1",
			dbesc($arr['email'])
		);
		if (! ($a && ($a[0]['account_flags'] & ACCOUNT_UNVERIFIED))) {
			return false;
		}
		$account = array_shift($a);
		$v = q("select * from register where uid = %d and password = 'verify' limit 1",
			intval($account['account_id'])
		);
		if ($v) {
			$hash = $v[0]['hash'];
		}
		else {
			return false;
		}
	}
	else {
		$hash = random_string(24);

		$r = q("INSERT INTO register ( hash, created, uid, password, lang ) VALUES ( '%s', '%s', %d, '%s', '%s' ) ",
			dbesc($hash),
			dbesc(datetime_convert()),
			intval($arr['account']['account_id']),
			dbesc('verify'),
			dbesc($arr['account']['account_language'])
		);
		$account = $arr['account'];
	}

	push_lang(($account['account_language']) ? $account['account_language'] : 'en');

	$email_msg = replace_macros(get_intltext_template('register_verify_member.tpl'),
		[
			'$sitename' => get_config('system','sitename'),
			'$siteurl'  => z_root(),
			'$email'    => $arr['email'],
			'$uid'      => $account['account_id'],
			'$hash'     => $hash,
			'$details'  => $details
	 	]
	);

	$res = z_mail(
		[ 
		'toEmail' => $arr['email'], 
		'messageSubject' => sprintf( t('Registration confirmation for %s'), get_config('system','sitename')),
		'textVersion' => $email_msg,
		]
	);

	pop_lang();

	if ($res) {
		$delivered ++;
	}
	else {
		logger('send_reg_approval_email: failed to account_id: ' . $arr['account']['account_id']);
	}
	return $res;
}




function send_reg_approval_email($arr) {

	$r = q("select * from account where (account_roles & %d) >= 4096",
		 intval(ACCOUNT_ROLE_ADMIN)
	);
	if (! ($r && count($r))) {
		return false;
	}

	$admins = [];

	foreach ($r as $rr) {
		if (strlen($rr['account_email'])) {
			$admins[] = [ 'email' => $rr['account_email'], 'lang' => $rr['account_lang'] ];
		}
	}

	if (! count($admins)) {
		return false;
	}

	$hash = random_string();

	$r = q("INSERT INTO register ( hash, created, uid, password, lang ) VALUES ( '%s', '%s', %d, '%s', '%s' ) ",
		dbesc($hash),
		dbesc(datetime_convert()),
		intval($arr['account']['account_id']),
		dbesc(''),
		dbesc($arr['account']['account_language'])
	);

	$ip = $_SERVER['REMOTE_ADDR'];

	$details = (($ip) ? $ip . ' [' . gethostbyaddr($ip) . ']' : '[unknown or stealth IP]');

	$delivered = 0;

	foreach ($admins as $admin) {
		if (strlen($admin['lang'])) {
			push_lang($admin['lang']);
		}
		else {
			push_lang('en');
		}

		$email_msg = replace_macros(get_intltext_template('register_verify_eml.tpl'), [
			'$sitename' => get_config('system','sitename'),
			'$siteurl'  =>  z_root(),
			'$email'    => $arr['email'],
			'$uid'      => $arr['account']['account_id'],
			'$hash'     => $hash,
			'$details'  => $details
		 ]);

		$res = z_mail(
			[ 
			'toEmail' => $admin['email'], 
			'messageSubject' => sprintf( t('Registration request at %s'), get_config('system','sitename')),
			'textVersion' => $email_msg,
			]
		);

		if ($res) {
			$delivered ++;
		}
		else {
			logger('send_reg_approval_email: failed to ' . $admin['email'] . 'account_id: ' . $arr['account']['account_id']);
		}
		
		pop_lang();
	}

	return ($delivered ? true : false);
}

function send_register_success_email($email,$password) {

	$email_msg = replace_macros(get_intltext_template('register_open_eml.tpl'), [
		'$sitename' => get_config('system','sitename'),
		'$siteurl' =>  z_root(),
		'$email'    => $email,
		'$password' => t('your registration password'),
	]);

	$res = z_mail(
		[ 
			'toEmail' => $email,
			'messageSubject' => sprintf( t('Registration details for %s'), get_config('system','sitename')),
			'textVersion' => $email_msg,
		]
	);

	return ($res ? true : false);
}

/**
 * @brief Allows a user registration.
 *
 * @param string $hash
 * @return array|boolean
 */
function account_allow($hash) {

	$ret = array('success' => false);

	$register = q("SELECT * FROM register WHERE hash = '%s' LIMIT 1",
		dbesc($hash)
	);

	if (! $register) {
		return $ret;
	}

	$account = q("SELECT * FROM account WHERE account_id = %d LIMIT 1",
		intval($register[0]['uid'])
	);

	if (! $account)
		return $ret;

	$r = q("DELETE FROM register WHERE hash = '%s'",
		dbesc($register[0]['hash'])
	);

	$r = q("update account set account_flags = (account_flags & ~%d) where (account_flags & %d) > 0 and account_id = %d",
		intval(ACCOUNT_BLOCKED),
		intval(ACCOUNT_BLOCKED),
		intval($register[0]['uid'])
	);
	$r = q("update account set account_flags = (account_flags & ~%d) where (account_flags & %d) > 0 and account_id = %d",
		intval(ACCOUNT_PENDING),
		intval(ACCOUNT_PENDING),
		intval($register[0]['uid'])
	);

	push_lang($register[0]['lang']);

	$email_tpl = get_intltext_template("register_open_eml.tpl");
	$email_msg = replace_macros($email_tpl, [
		'$sitename' => get_config('system','sitename'),
		'$siteurl'  =>  z_root(),
		'$username' => $account[0]['account_email'],
		'$email'    => $account[0]['account_email'],
		'$password' => '',
		'$uid'      => $account[0]['account_id']
	]);

	$res = z_mail(
		[ 
		'toEmail' => $account[0]['account_email'],
		'messageSubject' => sprintf( t('Registration details for %s'), get_config('system','sitename')),
		'textVersion' => $email_msg,
		]
	);

	pop_lang();

	if (get_config('system','auto_channel_create')) {
		auto_channel_create($register[0]['uid']);
	}

	if ($res) {
		info( t('Account approved.') . EOL );
		return true;
	}
}


/**
 * @brief Denies an account registration.
 *
 * This does not have to go through user_remove() and save the nickname
 * permanently against re-registration, as the person was not yet
 * allowed to have friends on this system
 *
 * @param string $hash
 * @return boolean
 */

function account_deny($hash) {

	$register = q("SELECT * FROM register WHERE hash = '%s' LIMIT 1",
		dbesc($hash)
	);

	if(! $register) {
		return false;
	}

	$account = q("SELECT account_id, account_email FROM account WHERE account_id = %d LIMIT 1",
		intval($register[0]['uid'])
	);

	if (! $account) {
		return false;
	}

	$r = q("DELETE FROM account WHERE account_id = %d",
		intval($register[0]['uid'])
	);

	$r = q("DELETE FROM register WHERE id = %d",
		dbesc($register[0]['id'])
	);
	notice( sprintf(t('Registration revoked for %s'), $account[0]['account_email']) . EOL);

	return true;

}

// called from regver to activate an account from the email verification link

function account_approve($hash) {

	$ret = false;

	// Note: when the password in the register table is 'verify', the uid actually contains the account_id

	$register = q("SELECT * FROM register WHERE hash = '%s' and password = 'verify' LIMIT 1",
		dbesc($hash)
	);

	if (! $register) {
		return $ret;
	}

	$account = q("SELECT * FROM account WHERE account_id = %d LIMIT 1",
		intval($register[0]['uid'])
	);

	if (! $account) {
		return $ret;
	}

	$r = q("DELETE FROM register WHERE hash = '%s' and password = 'verify'",
		dbesc($register[0]['hash'])
	);

	$r = q("update account set account_flags = (account_flags & ~%d) where (account_flags & %d)>0 and account_id = %d",
		intval(ACCOUNT_BLOCKED),
		intval(ACCOUNT_BLOCKED),
		intval($register[0]['uid'])
	);
	$r = q("update account set account_flags = (account_flags & ~%d) where (account_flags & %d)>0 and account_id = %d",
		intval(ACCOUNT_PENDING),
		intval(ACCOUNT_PENDING),
		intval($register[0]['uid'])
	);
	$r = q("update account set account_flags = (account_flags & ~%d) where (account_flags & %d)>0 and account_id = %d",
		intval(ACCOUNT_UNVERIFIED),
		intval(ACCOUNT_UNVERIFIED),
		intval($register[0]['uid'])
	);

	// get a fresh copy after we've modified it.

	$account = q("SELECT * FROM account WHERE account_id = %d LIMIT 1",
		intval($register[0]['uid'])
	);

	if (! $account) {
		return $ret;
	}

	if(get_config('system','auto_channel_create')) {
		auto_channel_create($register[0]['uid']);
	}
	else {
		$_SESSION['login_return_url'] = 'new_channel';
		authenticate_success($account[0],null,true,true,false,true);
	}	

	return true;
}


/**
 * @brief Checks for accounts that have past their expiration date.
 *
 * If the account has a service class which is not the site default, 
 * the service class is reset to the site default and expiration reset to never.
 * If the account has no service class it is expired and subsequently disabled.
 * called from include/poller.php as a scheduled task.
 *
 * Reclaiming resources which are no longer within the service class limits is
 * not the job of this function, but this can be implemented by plugin if desired. 
 * Default behaviour is to stop allowing additional resources to be consumed. 
 */
function downgrade_accounts() {

	$r = q("select * from account where not ( account_flags & %d ) > 0 
		and account_expires > '%s' 
		and account_expires < %s ",
		intval(ACCOUNT_EXPIRED),
		dbesc(NULL_DATE),
		db_getfunc('UTC_TIMESTAMP')
	);

	if (! $r) {
		return;
	}

	$basic = get_config('system','default_service_class');

	foreach ($r as $rr) {
		if (($basic) && ($rr['account_service_class']) && ($rr['account_service_class'] != $basic)) {
			$x = q("UPDATE account set account_service_class = '%s', account_expires = '%s'
				where account_id = %d",
				dbesc($basic),
				dbesc(NULL_DATE),
				intval($rr['account_id'])
			);
			$ret = [ 'account' => $rr ];
			call_hooks('account_downgrade', $ret );
			logger('downgrade_accounts: Account id ' . $rr['account_id'] . ' downgraded.');
		}
		else {
			$x = q("UPDATE account SET account_flags = (account_flags | %d) where account_id = %d",
				intval(ACCOUNT_EXPIRED),
				intval($rr['account_id'])
			);
			$ret = [ 'account' => $rr ];
			call_hooks('account_downgrade', $ret);
			logger('downgrade_accounts: Account id ' . $rr['account_id'] . ' expired.');
		}
	}
}


/**
 * @brief Check service_class restrictions.
 *
 * If there are no service_classes defined, everything is allowed.
 * If $usage is supplied, we check against a maximum count and return true if
 * the current usage is less than the subscriber plan allows. Otherwise we
 * return boolean true or false if the property is allowed (or not) in this
 * subscriber plan. An unset property for this service plan means the property
 * is allowed, so it is only necessary to provide negative properties for each
 * plan, or what the subscriber is not allowed to do.
 *
 * Like account_service_class_allows() but queries directly by account rather
 * than channel. Service classes are set for accounts, so we look up the
 * account for the channel and fetch the service class restrictions of the
 * account.
 *
 * @see account_service_class_allows() if you have a channel_id already
 * @see service_class_fetch()
 *
 * @param int $uid The channel_id to check
 * @param string $property The service class property to check for
 * @param string|boolean $usage (optional) The value to check against
 * @return boolean
 */
function service_class_allows($uid, $property, $usage = false) {
	$limit = service_class_fetch($uid, $property);

	if ($limit === false) {
		return true; // No service class set => everything is allowed
	}
	
	$limit = engr_units_to_bytes($limit);
	if ($usage === false) {
		// We use negative values for not allowed properties in a subscriber plan
		return (($limit) ? (bool) $limit : true);
	} else {
		return (((intval($usage)) < intval($limit)) ? true : false);
	}
}

/**
 * @brief Check service class restrictions by account.
 *
 * If there are no service_classes defined, everything is allowed.
 * If $usage is supplied, we check against a maximum count and return true if
 * the current usage is less than the subscriber plan allows. Otherwise we
 * return boolean true or false if the property is allowed (or not) in this
 * subscriber plan. An unset property for this service plan means the property
 * is allowed, so it is only necessary to provide negative properties for each
 * plan, or what the subscriber is not allowed to do.
 *
 * Like service_class_allows() but queries directly by account rather than channel.
 *
 * @see service_class_allows() if you have a channel_id instead of an account_id
 * @see account_service_class_fetch()
 *
 * @param int $aid The account_id to check
 * @param string $property The service class property to check for
 * @param int|boolean $usage (optional) The value to check against
 * @return boolean
 */
function account_service_class_allows($aid, $property, $usage = false) {

	$limit = account_service_class_fetch($aid, $property);

	if ($limit === false) {
		return true; // No service class is set => everything is allowed
	}
	
	$limit = engr_units_to_bytes($limit);

	if ($usage === false) {
		// We use negative values for not allowed properties in a subscriber plan
		return (($limit) ? (bool) $limit : true);
	} else {
		return (((intval($usage)) < intval($limit)) ? true : false);
	}
}

/**
 * @brief Queries a service class value for a channel and property.
 *
 * Service classes are set for accounts, so look up the account for this channel
 * and fetch the service classe of the account.
 *
 * If no service class is available it returns false and everything should be
 * allowed.
 *
 * @see account_service_class_fetch()
 *
 * @param int $uid The channel_id to query
 * @param string $property The service property name to check for
 * @return boolean|int
 *
 * @todo Should we merge this with account_service_class_fetch()?
 */
function service_class_fetch($uid, $property) {


	if ($uid == local_channel()) {
		$service_class = App::$account['account_service_class'];
	}
	else {
		$r = q("select account_service_class 
			from channel c, account a 
			where c.channel_account_id = a.account_id and c.channel_id = %d limit 1",
			intval($uid)
		);
		if ($r) {
			$service_class = $r[0]['account_service_class'];
		}
	}
	if (! $service_class) {
		return false; // everything is allowed
	}
	$arr = get_config('service_class', $service_class);

	if (! is_array($arr) || (! count($arr))) {
		return false;
	}

	return((array_key_exists($property, $arr)) ? $arr[$property] : false);
}

/**
 * @brief Queries a service class value for an account and property.
 *
 * Like service_class_fetch() but queries by account rather than channel.
 *
 * @see service_class_fetch() if you have channel_id.
 * @see account_service_class_allows()
 *
 * @param int $aid The account_id to query
 * @param string $property The service property name to check for
 * @return boolean|int
 */
function account_service_class_fetch($aid, $property) {

	$r = q("select account_service_class as service_class from account where account_id = %d limit 1",
		intval($aid)
	);
	if($r !== false && count($r)) {
		$service_class = $r[0]['service_class'];
	}

	if(! x($service_class))
		return false; // everything is allowed

	$arr = get_config('service_class', $service_class);

	if(! is_array($arr) || (! count($arr)))
		return false;

	return((array_key_exists($property, $arr)) ? $arr[$property] : false);
}


function upgrade_link($bbcode = false) {
	$l = get_config('service_class', 'upgrade_link');
	if(! $l)
		return '';
	if($bbcode)
		$t = sprintf('[zrl=%s]' . t('Click here to upgrade.') . '[/zrl]', $l);
	else
		$t = sprintf('<a href="%s">' . t('Click here to upgrade.') . '</div>', $l);
	return $t;
}

function upgrade_message($bbcode = false) {
	$x = upgrade_link($bbcode);
	return t('This action exceeds the limits set by your subscription plan.') . (($x) ? ' ' . $x : '') ;
}

function upgrade_bool_message($bbcode = false) {
	$x = upgrade_link($bbcode);
	return t('This action is not available under your subscription plan.') . (($x) ? ' ' . $x : '') ;
}


