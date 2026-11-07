<?php
// ==============================================================
// addr.bar Configuration & Database Options
// ==============================================================
$date_format    = 'Y-m-d H:i T';

// Enforce SSL / HTTPS (unencrypted plain HTTP is rejected, except for localhost development)
$current_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$is_local_dev = in_array(preg_replace('/:[0-9]+$/', '', $current_host), ['localhost', '127.0.0.1', '::1', ''], true);
$is_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

if (!$is_https && !$is_local_dev) {
	if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
		header('Location: https://' . $current_host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
		exit;
	} else {
		http_response_code(426); // Upgrade Required
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			'success' => false,
			'error'   => 'SSL/HTTPS is strictly required. Unencrypted HTTP transmissions are not permitted.'
		]);
		exit;
	}
}

// SQLite Database Configuration (Direct file access, zero server daemon)
$db_file = getenv('SQLITE_PATH') ?: (getenv('DB_PATH') ?: (getenv('MESSAGES_DIR') ? rtrim(getenv('MESSAGES_DIR'), '/\\') . '/contact.sqlite' : (getenv('DATA_DIR') ? rtrim(getenv('DATA_DIR'), '/\\') . '/contact.sqlite' : __DIR__ . '/contact.sqlite')));

if (file_exists(__DIR__ . '/db_config.php')) {
	include __DIR__ . '/db_config.php';
	if (!empty($sqlite_path)) $db_file = $sqlite_path;
	elseif (!empty($db_path)) $db_file = $db_path;
	elseif (!empty($messages_dir)) $db_file = rtrim($messages_dir, '/\\') . '/contact.sqlite';
	elseif (!empty($data_dir)) $db_file = rtrim($data_dir, '/\\') . '/contact.sqlite';
}

if (!defined('SESSION_COOKIE_LIFETIME')) {
	define('SESSION_COOKIE_LIFETIME', 86400 * 365 * 15); // 15 years in seconds
}

if (session_status() === PHP_SESSION_NONE) {
	@ini_set('session.gc_maxlifetime', (string)SESSION_COOKIE_LIFETIME);
	@session_set_cookie_params([
		'lifetime' => SESSION_COOKIE_LIFETIME,
		'path'     => '/',
		'httponly' => true,
		'samesite' => 'Lax'
	]);
	@session_start();
}

// --------------------------------------------------------------
// Handle Download Actions (ZIP Package, PHP Script, SQL Schema)
// --------------------------------------------------------------
if (isset($_GET['action'])) {
	$act = $_GET['action'];
	$dir = __DIR__;

	if ($act === 'download_zip' || $act === 'download_package') {
		$zip_file = $dir . '/contact.zip';
		// If contact.zip does not exist or index.php/schema.sql are newer than zip, rebuild it
		$rebuild = !file_exists($zip_file);
		if (!$rebuild && file_exists($zip_file)) {
			$zip_mtime = filemtime($zip_file);
			if (filemtime(__FILE__) > $zip_mtime || (file_exists($dir . '/schema.sql') && filemtime($dir . '/schema.sql') > $zip_mtime)) {
				$rebuild = true;
			}
		}
		if ($rebuild && file_exists('/usr/bin/zip')) {
			$cmd = sprintf(
				'cd %s && /usr/bin/zip -r -q %s index.php db.php auth.php signup.html schema.sql set_password.php set_user_password.php create_user.php install.sh INSTALL.md -x "*.log" "db_config.php"',
				escapeshellarg($dir),
				escapeshellarg($zip_file)
			);
			@shell_exec($cmd);
		}
		if (!file_exists($zip_file) && file_exists($dir . '/remote_server_directory_package.zip')) {
			$zip_file = $dir . '/remote_server_directory_package.zip';
		}
		if (file_exists($zip_file)) {
			header('Content-Description: File Transfer');
			header('Content-Type: application/zip');
			header('Content-Disposition: attachment; filename="contact.zip"');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . filesize($zip_file));
			readfile($zip_file);
			exit;
		}
	}

	if ($act === 'download_sql') {
		$sql_file = $dir . '/schema.sql';
		if (file_exists($sql_file)) {
			header('Content-Description: File Transfer');
			header('Content-Type: application/sql');
			header('Content-Disposition: attachment; filename="schema.sql"');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . filesize($sql_file));
			readfile($sql_file);
			exit;
		}
	}

	if ($act === 'download_script') {
		$file_path = __FILE__;
		if (file_exists($file_path)) {
			header('Content-Description: File Transfer');
			header('Content-Type: application/x-php');
			header('Content-Disposition: attachment; filename="index.php"');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . filesize($file_path));
			readfile($file_path);
			exit;
		}
	}
}

// --------------------------------------------------------------
// Database Connection & Auto-Schema Initialization (SQLite)
// --------------------------------------------------------------
if (!function_exists('get_db')) {
function get_db() {
	static $pdo = null;
	if ($pdo !== null) return $pdo;

	// Use shared db.php if available
	if (file_exists(__DIR__ . '/db.php')) {
		require_once __DIR__ . '/db.php';
		global $pdo;
		if ($pdo) return $pdo;
	}

	$db_file = __DIR__ . '/contact.sqlite';
	if (getenv('SQLITE_PATH')) {
		$db_file = getenv('SQLITE_PATH');
	} elseif (getenv('DB_PATH')) {
		$db_file = getenv('DB_PATH');
	} elseif (getenv('MESSAGES_DIR')) {
		$db_file = rtrim(getenv('MESSAGES_DIR'), '/\\') . '/contact.sqlite';
	} elseif (getenv('DATA_DIR')) {
		$db_file = rtrim(getenv('DATA_DIR'), '/\\') . '/contact.sqlite';
	}

	if (file_exists(__DIR__ . '/db_config.php')) {
		include __DIR__ . '/db_config.php';
		if (!empty($sqlite_path)) $db_file = $sqlite_path;
		elseif (!empty($db_path)) $db_file = $db_path;
		elseif (!empty($messages_dir)) $db_file = rtrim($messages_dir, '/\\') . '/contact.sqlite';
		elseif (!empty($data_dir)) $db_file = rtrim($data_dir, '/\\') . '/contact.sqlite';
	}

	$db_dir = dirname($db_file);
	if (!is_dir($db_dir)) {
		@mkdir($db_dir, 0755, true);
	}

	try {
		$pdo = new PDO("sqlite:" . $db_file, null, null, [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_TIMEOUT            => 10,
		]);
		$pdo->exec("PRAGMA journal_mode = WAL;");
		$pdo->exec("PRAGMA foreign_keys = ON;");
		$pdo->exec("PRAGMA busy_timeout = 5000;");
	} catch (PDOException $e) {
		error_log("Database initialization error: Unable to open SQLite file '{$db_file}': " . $e->getMessage());
		return null;
	}

	try {
		$pdo->exec("
			CREATE TABLE IF NOT EXISTS users (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				domain TEXT DEFAULT NULL,
				username TEXT NOT NULL,
				password TEXT NOT NULL,
				email TEXT DEFAULT NULL,
				session_token TEXT DEFAULT NULL,
				reset_token TEXT DEFAULT NULL,
				reset_expires_at TEXT DEFAULT NULL,
				created_at TEXT DEFAULT (datetime('now'))
			);
			CREATE INDEX IF NOT EXISTS idx_user_domain ON users(domain);

			CREATE TABLE IF NOT EXISTS login_attempts (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				domain TEXT DEFAULT NULL,
				ip_address TEXT NOT NULL,
				attempted_at TEXT DEFAULT (datetime('now'))
			);
			CREATE INDEX IF NOT EXISTS idx_ip_domain_attempted ON login_attempts(ip_address, domain, attempted_at);

			CREATE TABLE IF NOT EXISTS site_settings (
				setting_key TEXT NOT NULL PRIMARY KEY,
				setting_value TEXT NOT NULL,
				updated_at TEXT DEFAULT (datetime('now'))
			);

			CREATE TABLE IF NOT EXISTS blocked_sites (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				domain_or_url TEXT NOT NULL UNIQUE,
				ip_address TEXT DEFAULT NULL,
				reason TEXT DEFAULT NULL,
				is_blocked INTEGER DEFAULT 1,
				created_at TEXT DEFAULT (datetime('now'))
			);

			CREATE TABLE IF NOT EXISTS incoming_messages (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				sender_url TEXT NOT NULL,
				sender_host TEXT NOT NULL,
				sender_ip TEXT NOT NULL,
				sender_rdns TEXT DEFAULT NULL,
				dns_verification_status TEXT NOT NULL,
				dns_verification_details TEXT DEFAULT NULL,
				sender_name TEXT DEFAULT NULL,
				sender_email TEXT DEFAULT NULL,
				subject TEXT DEFAULT '',
				message_body TEXT NOT NULL,
				is_read INTEGER DEFAULT 0,
				received_at TEXT DEFAULT (datetime('now')),
				recipient_user TEXT DEFAULT NULL,
				is_sent_copy INTEGER DEFAULT 0,
				all_recipients TEXT DEFAULT NULL,
				deleted_by_users TEXT DEFAULT NULL,
				is_delayed INTEGER DEFAULT 0,
				retry_after TEXT DEFAULT NULL,
				destination_url TEXT DEFAULT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_sender_host ON incoming_messages(sender_host);
			CREATE INDEX IF NOT EXISTS idx_received_at ON incoming_messages(received_at);
			CREATE INDEX IF NOT EXISTS idx_is_delayed ON incoming_messages(is_delayed);
			CREATE INDEX IF NOT EXISTS idx_recipient_user ON incoming_messages(recipient_user);

			CREATE TABLE IF NOT EXISTS sent_messages (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				destination_url TEXT NOT NULL,
				subject TEXT DEFAULT '',
				message_body TEXT NOT NULL,
				delivery_status TEXT NOT NULL,
				response_code INTEGER DEFAULT NULL,
				response_message TEXT DEFAULT NULL,
				sent_at TEXT DEFAULT (datetime('now')),
				recipient_users TEXT DEFAULT NULL,
				all_recipients TEXT DEFAULT NULL
			);
			CREATE INDEX IF NOT EXISTS idx_delivery_status ON sent_messages(delivery_status);
			CREATE INDEX IF NOT EXISTS idx_sent_at ON sent_messages(sent_at);
		");

		// Dynamic column check via SQLite PRAGMA table_info
		try {
			$user_cols_raw = $pdo->query("PRAGMA table_info(users)")->fetchAll();
			$user_cols = array_column($user_cols_raw, 'name');
			if (!in_array("domain", $user_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN domain TEXT DEFAULT NULL");
			if (!in_array("reset_token", $user_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN reset_token TEXT DEFAULT NULL");
			if (!in_array("reset_expires_at", $user_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires_at TEXT DEFAULT NULL");

			$la_cols_raw = $pdo->query("PRAGMA table_info(login_attempts)")->fetchAll();
			$la_cols = array_column($la_cols_raw, 'name');
			if (!in_array("domain", $la_cols)) $pdo->exec("ALTER TABLE login_attempts ADD COLUMN domain TEXT DEFAULT NULL");

			$in_cols_raw = $pdo->query("PRAGMA table_info(incoming_messages)")->fetchAll();
			$in_cols = array_column($in_cols_raw, 'name');
			if (!in_array("recipient_user", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN recipient_user TEXT DEFAULT NULL");
			if (!in_array("is_sent_copy", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN is_sent_copy INTEGER DEFAULT 0");
			if (!in_array("all_recipients", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN all_recipients TEXT DEFAULT NULL");
			if (!in_array("deleted_by_users", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN deleted_by_users TEXT DEFAULT NULL");
			if (!in_array("is_delayed", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN is_delayed INTEGER DEFAULT 0");
			if (!in_array("retry_after", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN retry_after TEXT DEFAULT NULL");
			if (!in_array("destination_url", $in_cols)) $pdo->exec("ALTER TABLE incoming_messages ADD COLUMN destination_url TEXT DEFAULT NULL");

			$sent_cols_raw = $pdo->query("PRAGMA table_info(sent_messages)")->fetchAll();
			$sent_cols = array_column($sent_cols_raw, 'name');
			if (!in_array("recipient_users", $sent_cols)) $pdo->exec("ALTER TABLE sent_messages ADD COLUMN recipient_users TEXT DEFAULT NULL");
			if (!in_array("all_recipients", $sent_cols)) $pdo->exec("ALTER TABLE sent_messages ADD COLUMN all_recipients TEXT DEFAULT NULL");
		} catch (Exception $e) {}

		// Seed initial default settings if empty
		$stmt = $pdo->prepare("INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES 
			('allow_incoming_communications', '1'), 
			('rate_limiting_enabled', '1'),
			('rate_limit_outside_per_day', '10'),
			('rate_limit_inside_per_day', '100'),
			('rate_limit_per_website_per_day', '10'),
			('site_title', 'addr.bar Remote Server Directory'),
			('default_mail_destinations', '')
		");
		$stmt->execute();

		// Seed default admin account if users table is empty
		$cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
		if ($cnt === 0) {
			$hash = password_hash('admin123', PASSWORD_BCRYPT);
			$stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
			$stmt->execute(['admin', $hash]);
		}

	} catch (PDOException $e) {
		error_log("Database initialization error: " . $e->getMessage());
		return null;
	}
	return $pdo;
}
}

// Settings Helpers (domain-scoped with global fallback)
if (!function_exists('get_setting')) {
	function get_setting($key, $default = '', $domain = null) {
		$pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
		if (!$pdo) return $default;
		$dom = ($domain !== null && $domain !== false) ? $domain : (function_exists('get_current_domain') ? get_current_domain() : null);
		try {
			if ($dom) {
				$stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
				$stmt->execute([$dom . '::' . $key]);
				$val = $stmt->fetchColumn();
				if ($val !== false && $val !== null) return $val;
			}
			$stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
			$stmt->execute([$key]);
			$val = $stmt->fetchColumn();
			return ($val !== false && $val !== null) ? $val : $default;
		} catch (Exception $e) {
			return $default;
		}
	}
}

if (!function_exists('set_setting')) {
	function set_setting($key, $value, $domain = null) {
		$pdo = function_exists('get_db') ? get_db() : ($GLOBALS['pdo'] ?? null);
		if (!$pdo) return false;
		$dom = ($domain === false) ? null : (($domain !== null) ? $domain : (function_exists('get_current_domain') ? get_current_domain() : null));
		$save_key = $dom ? ($dom . '::' . $key) : $key;
		try {
			$stmt = $pdo->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
			return $stmt->execute([$save_key, (string)$value]);
		} catch (Exception $e) {
			return false;
		}
	}
}

// Multi-Domain Helpers & Authentication
if (!function_exists('get_current_domain')) {
	function get_current_domain($default = null) {
		$raw = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
		if ($raw !== '') {
			$host = strtolower(trim(preg_replace('/:\d+$/', '', $raw)));
			if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
				return $host;
			}
		}
		if ($default !== null && $default !== '') {
			return strtolower(trim($default));
		}
		return 'addressbar.net';
	}
}


if (!function_exists('get_or_init_domain_user')) {
	function get_or_init_domain_user($pdo, $domain = null) {
		if (!$pdo) return null;
		$dom = $domain ?: get_current_domain();
		try {
			// 1. Look for user specifically tied to this domain
			$stmt = $pdo->prepare("SELECT * FROM users WHERE domain = ? LIMIT 1");
			$stmt->execute([$dom]);
			$user = $stmt->fetch();
			if ($user) return $user;

			// 2. Look for legacy user with NULL or empty domain
			$stmt = $pdo->prepare("SELECT * FROM users WHERE (domain IS NULL OR domain = '') ORDER BY (username = 'admin') DESC, id ASC LIMIT 1");
			$stmt->execute();
			$fallback = $stmt->fetch();
			if ($fallback) {
				$upd = $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?");
				$upd->execute([$dom, $fallback['id']]);
				$fallback['domain'] = $dom;
				return $fallback;
			}

			// 3. Auto-initialize single user for this domain with unguessable random password
			$default_user = 'admin';
			$default_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
			$ins = $pdo->prepare("INSERT INTO users (domain, username, password) VALUES (?, ?, ?)");
			$ins->execute([$dom, $default_user, $default_hash]);
			$new_id = (int)$pdo->lastInsertId();

			$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
			$stmt->execute([$new_id]);
			return $stmt->fetch() ?: null;
		} catch (Exception $e) {
			return null;
		}
	}
}

if (!function_exists('get_single_domain_user')) {
	function get_single_domain_user($domain = null) {
		$pdo = get_db();
		return get_or_init_domain_user($pdo, $domain);
	}
}

function is_logged_in() {
	$current_domain = get_current_domain();
	$logged_in = false;
	$token_to_refresh = null;
	$username_to_refresh = null;

	if (!empty($_SESSION['auth_user'])) {
		$sess_domain = $_SESSION['auth_user']['domain'] ?? $current_domain;
		if ($sess_domain === $current_domain) {
			$logged_in = true;
			$username_to_refresh = $_SESSION['auth_user']['username'] ?? null;
		}
	}

	// Check persistent session cookie scoped to the current domain's single user
	if (!empty($_COOKIE['session_token'])) {
		$token = trim($_COOKIE['session_token']);
		if (strlen($token) >= 32) {
			$domain_user = get_single_domain_user($current_domain);
			if ($domain_user && !empty($domain_user['session_token']) && hash_equals($domain_user['session_token'], $token)) {
				$_SESSION['auth_user'] = [
					'id'       => (int)$domain_user['id'],
					'domain'   => $current_domain,
					'username' => $domain_user['username']
				];
				$logged_in = true;
				$token_to_refresh = $token;
				$username_to_refresh = $domain_user['username'];
			}
		}
	} elseif ($logged_in) {
		// Session active in memory: retrieve persistent token to ensure cookies are maintained
		$domain_user = get_single_domain_user($current_domain);
		if ($domain_user && !empty($domain_user['session_token'])) {
			$token_to_refresh = $domain_user['session_token'];
			$username_to_refresh = $domain_user['username'];
		}
	}

	// Rolling 15-year session renewal: extend cookie expiration on active visits
	if ($logged_in && $token_to_refresh && !headers_sent()) {
		static $refreshed_this_request = false;
		if (!$refreshed_this_request) {
			$refreshed_this_request = true;
			$cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
			setcookie('session_token', $token_to_refresh, [
				'expires'  => time() + SESSION_COOKIE_LIFETIME,
				'path'     => $cookie_path,
				'httponly' => false,
				'samesite' => 'Lax'
			]);
			if ($username_to_refresh) {
				setcookie('dw_user', $username_to_refresh, [
					'expires'  => time() + SESSION_COOKIE_LIFETIME,
					'path'     => $cookie_path,
					'httponly' => false,
					'samesite' => 'Lax'
				]);
			}
		}
	}

	return $logged_in;
}

function get_logged_in_username() {
	if (!empty($_SESSION['auth_user']['username'])) {
		return $_SESSION['auth_user']['username'];
	}
	$u = get_single_domain_user();
	return $u['username'] ?? 'Host';
}

function verify_and_rehash_password($password_input, &$user, $pdo) {
	$stored = $user['password'] ?? '';
	if ($stored === '') return false;
	$matched = false;

	// 1. Standard PHP password_hash (bcrypt / argon2)
	if (password_verify($password_input, $stored)) {
		$matched = true;
	}
	// 2. Plain text match (in case password was stored without hashing)
	elseif ($password_input === $stored) {
		$matched = true;
	}
	// 3. MySQL native PASSWORD() hash: *40HEX
	elseif (strlen($stored) === 41 && $stored[0] === '*' && strtoupper($stored) === strtoupper('*' . sha1(sha1($password_input, true)))) {
		$matched = true;
	}
	// 4. SHA-256 hash (64 hex chars)
	elseif (strlen($stored) === 64 && hash('sha256', $password_input) === strtolower($stored)) {
		$matched = true;
	}
	// 5. MD5 hash (32 hex chars)
	elseif (strlen($stored) === 32 && md5($password_input) === strtolower($stored)) {
		$matched = true;
	}

	if ($matched) {
		// Automatically upgrade legacy / plaintext / non-bcrypt hash to secure bcrypt in database
		if (password_needs_rehash($stored, PASSWORD_BCRYPT) || !str_starts_with($stored, '$2y$')) {
			$new_hash = password_hash($password_input, PASSWORD_BCRYPT);
			try {
				$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
				$stmt->execute([$new_hash, $user['id']]);
				$user['password'] = $new_hash;
			} catch (Exception $e) {}
		}
		return true;
	}

	return false;
}

function authenticate_user($login_input, $password_input) {
	$pdo = get_db();
	if (!$pdo) return false;

	$password_input = trim($password_input);
	if ($password_input === '') return false;

	$cur_dom = get_current_domain();

	// 1. Single user per domain: verify password against the current domain user account ONLY
	$user = get_single_domain_user($cur_dom);
	if ($user && verify_and_rehash_password($password_input, $user, $pdo)) {
		return $user;
	}

	// 2. Lookup candidate user by username specifically for THIS domain only
	$login_input = trim($login_input);
	if ($login_input !== '') {
		try {
			$stmt = $pdo->prepare("SELECT * FROM users WHERE domain = ? AND username = ? LIMIT 1");
			$stmt->execute([$cur_dom, $login_input]);
			$candidate = $stmt->fetch();
			if ($candidate && verify_and_rehash_password($password_input, $candidate, $pdo)) {
				return $candidate;
			}
		} catch (Exception $e) {}
	}

	return false;
}

// Multi-byte / UTF-8 Polyfills if ext-mbstring is not installed
if (!function_exists('mb_strlen')) {
	function mb_strlen($string, $encoding = null) {
		if (function_exists('iconv_strlen')) {
			$enc = $encoding ?: 'UTF-8';
			$len = @iconv_strlen($string, $enc);
			if ($len !== false) return $len;
		}
		return strlen($string);
	}
}

if (!function_exists('mb_substr')) {
	function mb_substr($string, $start, $length = null, $encoding = null) {
		if (function_exists('iconv_substr')) {
			$enc = $encoding ?: 'UTF-8';
			if ($length === null) {
				$sub = @iconv_substr($string, $start, iconv_strlen($string, $enc), $enc);
			} else {
				$sub = @iconv_substr($string, $start, $length, $enc);
			}
			if ($sub !== false) return $sub;
		}
		return ($length === null) ? substr($string, $start) : substr($string, $start, $length);
	}
}

// Database Column Length Limits (utf8mb4 / 4-byte Emoji & Multi-Byte Safe)
define('MAX_LEN_TEXT_UTF8MB4', 15000); // Message body maximum length (15,000 utf8mb4 / emoji chars)
define('MAX_LEN_TEXT_BYTES', 65535);   // MySQL TEXT raw byte limit
define('MAX_LEN_VARCHAR_255', 255);    // MySQL VARCHAR(255) utf8mb4 characters
define('MAX_LEN_VARCHAR_100', 100);    // MySQL VARCHAR(100) utf8mb4 characters
define('MAX_LEN_VARCHAR_50', 50);      // MySQL VARCHAR(50) utf8mb4 characters

// Login Attempt Rate Limiting (Max 3 Login Attempts Per IP Address, 6-Hour Lock Timer)
define('MAX_LOGIN_ATTEMPTS_PER_IP', 3);
define('LOGIN_LOCK_DURATION_SECONDS', 21600); // 6 hours = 21,600 seconds

function get_client_ip() {
	return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function format_lock_timer($seconds) {
	$seconds = max(0, (int)$seconds);
	$h = floor($seconds / 3600);
	$m = floor(($seconds % 3600) / 60);
	$s = $seconds % 60;
	return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function purge_expired_login_attempts() {
	$pdo = get_db();
	if (!$pdo) return;
	try {
		$pdo->exec("
			DELETE FROM login_attempts
			WHERE (strftime('%s', 'now') - strftime('%s', attempted_at)) >= " . LOGIN_LOCK_DURATION_SECONDS . "
		");
	} catch (Exception $e) {}
}

function get_login_lock_status($ip_address, $domain = null) {
	$pdo = get_db();
	$default = [
		'attempts'          => 0,
		'remaining'         => MAX_LOGIN_ATTEMPTS_PER_IP,
		'locked'            => false,
		'seconds_remaining' => 0,
		'unlock_at'         => null
	];
	if (!$pdo) return $default;
	$ip = trim($ip_address);
	$dom = $domain ?: get_current_domain();
	try {
		purge_expired_login_attempts();
		$stmt = $pdo->prepare("
			SELECT 
				COUNT(*) AS attempt_count,
				MAX(attempted_at) AS last_attempt,
				datetime(MAX(attempted_at), '+6 hours') AS unlock_at,
				MAX(0, " . LOGIN_LOCK_DURATION_SECONDS . " - (strftime('%s', 'now') - strftime('%s', MAX(attempted_at)))) AS seconds_remaining
			FROM login_attempts
			WHERE ip_address = ? AND (domain = ? OR domain IS NULL)
		");
		$stmt->execute([$ip, $dom]);
		$row = $stmt->fetch();
		$attempts = (int)($row['attempt_count'] ?? 0);
		$remaining = max(0, MAX_LOGIN_ATTEMPTS_PER_IP - $attempts);
		$sec_rem = (int)($row['seconds_remaining'] ?? 0);
		$locked = ($attempts >= MAX_LOGIN_ATTEMPTS_PER_IP && $sec_rem > 0);
		return [
			'attempts'          => $attempts,
			'remaining'         => $remaining,
			'locked'            => $locked,
			'seconds_remaining' => $locked ? $sec_rem : 0,
			'unlock_at'         => $locked ? $row['unlock_at'] : null
		];
	} catch (Exception $e) {
		return $default;
	}
}

function get_failed_login_attempts($ip_address) {
	$status = get_login_lock_status($ip_address);
	return $status['attempts'];
}

function record_failed_login_attempt($ip_address, $domain = null) {
	$pdo = get_db();
	if (!$pdo) return;
	$dom = $domain ?: get_current_domain();
	try {
		$stmt = $pdo->prepare("INSERT INTO login_attempts (domain, ip_address) VALUES (?, ?)");
		$stmt->execute([$dom, trim($ip_address)]);
	} catch (Exception $e) {}
}

function clear_failed_login_attempts($ip_address, $domain = null) {
	$pdo = get_db();
	if (!$pdo) return;
	$dom = $domain ?: get_current_domain();
	try {
		$stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND (domain = ? OR domain IS NULL)");
		$stmt->execute([trim($ip_address), $dom]);
	} catch (Exception $e) {}
}

// Canonical Public URL of this site
function get_canonical_site_url() {
	$is_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
	            (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
	            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
	$protocol = $is_https ? "https://" : "http://";
	$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
	$uri  = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
	return $protocol . $host . $uri;
}

/**
 * Determines whether a sender website is on the local server ("inside") or remote ("outside").
 * Checks /etc/hosts for local loopback / server mappings, local IPs, and /etc/tricurse installations.
 */
function is_local_sender($sender_host, $sender_ip = '') {
	$sender_host = strtolower(trim($sender_host));
	if ($sender_host === '' || $sender_host === 'localhost' || $sender_host === '127.0.0.1' || $sender_host === '::1') {
		return true;
	}

	$sender_ip = trim($sender_ip);
	$server_ip = $_SERVER['SERVER_ADDR'] ?? '';

	// 1. Check if connecting from local loopback or server self-IP
	if ($sender_ip === '127.0.0.1' || $sender_ip === '::1' || ($server_ip !== '' && $sender_ip === $server_ip)) {
		return true;
	}

	// 2. Check /etc/hosts to see if sender_host resolves to loopback (127.*, ::1) or server IP
	if (is_readable('/etc/hosts')) {
		$lines = @file('/etc/hosts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines) {
			foreach ($lines as $line) {
				$line = trim(preg_replace('/#.*$/', '', $line));
				if ($line === '') continue;
				$parts = preg_split('/\s+/', $line);
				if (count($parts) >= 2) {
					$ip = $parts[0];
					$is_loopback_ip = (
						$ip === '127.0.0.1' ||
						strpos($ip, '127.') === 0 ||
						$ip === '::1' ||
						($server_ip !== '' && $ip === $server_ip)
					);
					if ($is_loopback_ip) {
						for ($i = 1; $i < count($parts); $i++) {
							if (strtolower($parts[$i]) === $sender_host) {
								return true;
							}
						}
					}
				}
			}
		}
	}

	// 3. Check gethostbyname resolution
	$resolved = @gethostbyname($sender_host);
	if ($resolved && ($resolved === '127.0.0.1' || strpos($resolved, '127.') === 0 || $resolved === '::1' || ($server_ip !== '' && $resolved === $server_ip))) {
		return true;
	}

	// 4. Check if /etc/tricurse has a registered configuration for this domain
	if (file_exists("/etc/tricurse/{$sender_host}/contact.conf") || file_exists("/etc/tricurse/{$sender_host}.conf")) {
		return true;
	}

	// 5. Check if /etc/tricurse/installations.json lists this domain
	if (file_exists('/etc/tricurse/installations.json')) {
		$installs = @json_decode(@file_get_contents('/etc/tricurse/installations.json'), true);
		if (is_array($installs)) {
			foreach ($installs as $inst) {
				if (!empty($inst['domain']) && strtolower($inst['domain']) === $sender_host) {
					return true;
				}
			}
		}
	}

	return false;
}

// Blocklist Check
function is_site_blocked($domain_or_host, $ip_address) {
	$pdo = get_db();
	if (!$pdo) return false;

	$domain_or_host = strtolower(trim($domain_or_host));
	$ip_address     = trim($ip_address);

	try {
		$stmt = $pdo->prepare("SELECT * FROM blocked_sites WHERE is_blocked = 1");
		$stmt->execute();
		$blocks = $stmt->fetchAll();

		foreach ($blocks as $b) {
			$entry = strtolower(trim($b['domain_or_url']));
			$b_ip  = trim($b['ip_address'] ?? '');

			if ($b_ip !== '' && $b_ip === $ip_address) {
				return true;
			}

			// Clean entry if it has protocol
			$entry_host = parse_url($entry, PHP_URL_HOST);
			if (!$entry_host) $entry_host = $entry;
			$entry_host = ltrim($entry_host, '*.');

			if ($entry_host === $domain_or_host) return true;
			if (str_ends_with($domain_or_host, '.' . $entry_host)) return true;
		}
	} catch (Exception $e) {}
	return false;
}

// Outbound HTTP Dispatcher for Federated Mail (using stream_context_create)
function dispatch_inter_site_mail($destination_url, $payload) {
	// Strictly enforce HTTPS/SSL for federated inter-site delivery
	$destination_url = trim($destination_url);
	if (preg_match('#^http://#i', $destination_url)) {
		$destination_url = preg_replace('#^http://#i', 'https://', $destination_url);
	} elseif (!preg_match('#^https://#i', $destination_url)) {
		$destination_url = 'https://' . ltrim($destination_url, '/');
	}

	$post_data = http_build_query($payload);

	$opts = [
		'http' => [
			'method'          => 'POST',
			'header'          => "Content-type: application/x-www-form-urlencoded\r\n" .
			                     "Content-Length: " . strlen($post_data) . "\r\n" .
			                     "User-Agent: addr.bar-Federated-Mail/2.0\r\n" .
			                     "Accept: application/json\r\n" .
			                     "Connection: close\r\n",
			'content'         => $post_data,
			'timeout'         => 12,
			'ignore_errors'   => true,
			'follow_location' => 0,
		],
		'ssl' => [
			'verify_peer'      => false,
			'verify_peer_name' => false,
		],
	];

	$ctx = stream_context_create($opts);
	$raw_response = @file_get_contents($destination_url, false, $ctx);
	$status_code = 0;
	$redirect_url = null;

	if (isset($http_response_header) && is_array($http_response_header)) {
		foreach ($http_response_header as $hdr) {
			if (preg_match('#^HTTP/[0-9\.]+\s+([0-9]{3})#i', $hdr, $m)) {
				$status_code = (int)$m[1];
			} elseif (preg_match('#^Location:\s*(.+)$#i', $hdr, $m)) {
				$redirect_url = trim($m[1]);
			}
		}
	}

	// Preserve POST method across directory slash redirects (e.g. https://addressbar.net/contact -> https://addressbar.net/contact/)
	if (in_array($status_code, [301, 302, 307, 308], true) && $redirect_url) {
		if (!preg_match('#^https?://#i', $redirect_url)) {
			$parsed = parse_url($destination_url);
			$scheme = $parsed['scheme'] ?? 'https';
			$host   = $parsed['host'] ?? 'localhost';
			$port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
			$redirect_url = $scheme . '://' . $host . $port . '/' . ltrim($redirect_url, '/');
		}
		$ctx = stream_context_create($opts);
		$raw_response = @file_get_contents($redirect_url, false, $ctx);
		$status_code = 0;
		if (isset($http_response_header) && is_array($http_response_header)) {
			foreach ($http_response_header as $hdr) {
				if (preg_match('#^HTTP/[0-9\.]+\s+([0-9]{3})#i', $hdr, $m)) {
					$status_code = (int)$m[1];
				}
			}
		}
	}

	$json = null;
	if ($raw_response) {
		$json = json_decode($raw_response, true);
	}

	return [
		'status_code'  => $status_code,
		'raw_response' => $raw_response,
		'json'         => $json,
	];
}

function format_bytes($bytes, $precision = 1) {
	if ($bytes <= 0) return '0 B';
	$units = ['B', 'KB', 'MB', 'GB'];
	$pow = floor(log($bytes, 1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

// --------------------------------------------------------------
// Browser Push Notifications & PWA Web Push AJAX Endpoints
// --------------------------------------------------------------
$ajax_action = $_POST['action'] ?? $_GET['action'] ?? '';
if (empty($ajax_action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$raw_json = @file_get_contents('php://input');
	if ($raw_json) {
		$parsed_json = @json_decode($raw_json, true);
		if (isset($parsed_json['action'])) {
			$ajax_action = $parsed_json['action'];
			$_POST = array_merge($_POST, $parsed_json);
		}
	}
}

// --------------------------------------------------------------
// Dynamic Site-Aware PWA Manifest
// Configures PWA for ANY domain and ANY directory where installed
// --------------------------------------------------------------
if ($ajax_action === 'manifest' || $ajax_action === 'manifest_json') {
	header('Content-Type: application/manifest+json; charset=utf-8');
	$dom = get_current_domain();
	$base_domain = preg_replace('/:[0-9]+$/', '', $dom);
	$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
	if ($script_dir === '' || $script_dir === '.') $script_dir = '';
	$app_scope = ($script_dir === '') ? '/' : ($script_dir . '/');
	$dir_name = trim($script_dir, '/');
	$app_name = $base_domain . ($dir_name !== '' ? " ({$dir_name})" : "");

	$manifest = [
		'name'             => $app_name,
		'short_name'       => $base_domain,
		'description'      => "Federated Messaging Desktop for {$base_domain}",
		'id'               => $app_scope,
		'start_url'        => 'index.php',
		'scope'            => './',
		'display'          => 'standalone',
		'orientation'      => 'any',
		'background_color' => '#ffffff',
		'theme_color'      => '#000000',
		'icons'            => [
			[
				'src'     => 'icon-192.png',
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any maskable'
			],
			[
				'src'     => 'icon-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any maskable'
			]
		]
	];
	echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

if ($ajax_action === 'get_vapid_public_key') {
	header('Content-Type: application/json; charset=utf-8');
	$pdo = get_db();
	$keys = function_exists('get_or_create_vapid_keys') ? get_or_create_vapid_keys($pdo) : null;
	if ($keys && !empty($keys['publicKey'])) {
		echo json_encode(['success' => true, 'publicKey' => $keys['publicKey']]);
	} else {
		http_response_code(500);
		echo json_encode(['success' => false, 'error' => 'Unable to generate VAPID keys']);
	}
	exit;
}

if ($ajax_action === 'save_push_subscription') {
	header('Content-Type: application/json; charset=utf-8');
	$pdo = get_db();
	$endpoint = trim($_POST['endpoint'] ?? '');
	$p256dh   = trim($_POST['p256dh'] ?? ($_POST['keys']['p256dh'] ?? ''));
	$auth     = trim($_POST['auth'] ?? ($_POST['keys']['auth'] ?? ''));
	$ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
	$dom      = get_current_domain();
	$is_standalone = !empty($_POST['is_standalone']) || !empty($_POST['installed_app']) 
	              || !empty($_SERVER['HTTP_X_INSTALLED_APP']) || !empty($_SERVER['HTTP_X_STANDALONE']);

	// When installed as an app, the visitor must be logged in before push notifications can be activated
	if ($is_standalone && !is_logged_in()) {
		http_response_code(403);
		echo json_encode([
			'success' => false,
			'error'   => 'When installed as an app, the visitor must be logged in before push notifications can be activated.'
		]);
		exit;
	}

	if ($endpoint !== '' && $pdo) {
		$stmt = $pdo->prepare("INSERT OR REPLACE INTO push_subscriptions (domain, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
		$stmt->execute([$dom, $endpoint, $p256dh, $auth, $ua]);
		echo json_encode(['success' => true]);
	} else {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'Missing subscription endpoint']);
	}
	exit;
}

if ($ajax_action === 'remove_push_subscription') {
	header('Content-Type: application/json; charset=utf-8');
	$pdo = get_db();
	$endpoint = trim($_POST['endpoint'] ?? '');
	if ($endpoint !== '' && $pdo) {
		$stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
		$stmt->execute([$endpoint]);
	}
	echo json_encode(['success' => true]);
	exit;
}

if ($ajax_action === 'test_push_notification') {
	header('Content-Type: application/json; charset=utf-8');
	$pdo = get_db();
	$dom = get_current_domain();
	$base_domain = preg_replace('/:[0-9]+$/', '', $dom);
	$sent = false;
	if (function_exists('send_browser_push_notification')) {
		$sent = send_browser_push_notification($pdo, $dom, [
			'sender_name'  => 'System',
			'sender_host'  => $base_domain,
			'subject'      => 'Test Push Notification',
			'message_body' => "Web push notifications are working properly on {$base_domain}!"
		]);
	}
	echo json_encode(['success' => true, 'sent' => $sent]);
	exit;
}

if ($ajax_action === 'get_latest_notification') {
	header('Content-Type: application/json; charset=utf-8');
	$pdo = get_db();
	$dom = get_current_domain();
	$base_domain = preg_replace('/:[0-9]+$/', '', $dom);
	$stmt = $pdo->prepare("SELECT sender_name, sender_host, subject, message_body FROM incoming_messages WHERE is_sent_copy = 0 ORDER BY id DESC LIMIT 1");
	$stmt->execute();
	$latest = $stmt->fetch();
	if ($latest) {
		$sender = $latest['sender_name'] ?: ($latest['sender_host'] ?: 'Peer Website');
		$preview = mb_substr(strip_tags($latest['message_body']), 0, 100);
		$subj_str = (!empty($latest['subject']) && trim($latest['subject']) !== '') ? ($latest['subject'] . ' — ') : '';
		echo json_encode([
			'success' => true,
			'title'   => $base_domain,
			'body'    => "New message from {$sender}: " . $subj_str . ($preview ?: 'Click to view inbox'),
			'url'     => 'index.php?tab=inbox'
		]);
	} else {
		echo json_encode([
			'success' => true,
			'title'   => $base_domain,
			'body'    => 'You have received a new message in your host inbox.',
			'url'     => 'index.php?tab=inbox'
		]);
	}
	exit;
}

// --------------------------------------------------------------
// Handle Inbound Federated Mail (Inter-Site Server-to-Server Endpoint)
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'inter_site_mail')) {
	header('Content-Type: application/json; charset=utf-8');

	// 1. Check Global Communication Toggle
	$comms_enabled = get_setting('allow_incoming_communications', '1');
	if ($comms_enabled !== '1') {
		http_response_code(403);
		echo json_encode([
			'success' => false,
			'error'   => 'Incoming communications are currently disabled by the host of this site.'
		]);
		exit;
	}

	$sender_url      = trim($_POST['sender_url'] ?? '');
	$subject         = trim($_POST['subject'] ?? '');
	$message         = trim($_POST['message'] ?? '');
	$sender_name     = trim($_POST['sender_name'] ?? '');
	$recipient_users = trim($_POST['recipient_users'] ?? '');
	$all_recipients  = trim($_POST['all_recipients'] ?? '');

	if ($sender_url === '' || $message === '') {
		http_response_code(400);
		echo json_encode([
			'success' => false,
			'error'   => 'Missing required fields: sender_url and message are required.'
		]);
		exit;
	}

	// Enforce utf8mb4 multi-byte / emoji column length limits
	$sender_url   = mb_substr($sender_url, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
	$subject      = mb_substr($subject, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
	$sender_name  = mb_substr($sender_name, 0, MAX_LEN_VARCHAR_100, 'UTF-8');
	if (mb_strlen($message, 'UTF-8') > MAX_LEN_TEXT_UTF8MB4 || strlen($message) > MAX_LEN_TEXT_BYTES) {
		http_response_code(400);
		echo json_encode([
			'success' => false,
			'error'   => 'Message exceeds maximum length of ' . number_format(MAX_LEN_TEXT_UTF8MB4) . ' utf8mb4 characters (' . number_format(MAX_LEN_TEXT_BYTES) . ' bytes).'
		]);
		exit;
	}

	$sender_host = parse_url($sender_url, PHP_URL_HOST);
	if (!$sender_host) {
		$sender_host = $sender_url;
	}

	$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	// 2. Check Site Blocklist
	if (is_site_blocked($sender_host, $client_ip)) {
		http_response_code(403);
		echo json_encode([
			'success' => false,
			'error'   => 'Communications from this website or IP have been toggled off by the host.'
		]);
		exit;
	}

	// 3. Rate Limiting & Message Delay Evaluation
	$pdo = get_db();
	if (!$pdo) {
		http_response_code(500);
		echo json_encode(['success' => false, 'error' => 'Database storage failure.']);
		exit;
	}

	$rate_limit_enabled = (get_setting('rate_limiting_enabled', '1') === '1');
	$is_inside = is_local_sender($sender_host, $client_ip);
	$scope_label = $is_inside ? 'inside (local server)' : 'outside (remote)';
	if ($is_inside) {
		$daily_limit = (int)get_setting('rate_limit_inside_per_day', '100');
		if ($daily_limit < 1) $daily_limit = 100;
	} else {
		$daily_limit = (int)get_setting('rate_limit_outside_per_day', get_setting('rate_limit_per_website_per_day', '10'));
		if ($daily_limit < 1) $daily_limit = 10;
	}

	$current_count = 0;
	$reset_timestamp = time() + 86400;
	$delay_time_str = date('Y-m-d H:i:s', $reset_timestamp);

	if ($rate_limit_enabled) {
		// "The limit will be applied not only to them, but all users at that website if it has been implemented."
		// "If one of the recipients' limits is used for the effect, it applies to all recipients."
		$count_stmt = $pdo->prepare("
			SELECT COUNT(*) FROM incoming_messages 
			WHERE sender_host = ? 
			  AND is_sent_copy = 0 
			  AND received_at >= datetime('now', '-24 hours')
		");
		$count_stmt->execute([$sender_host]);
		$current_count = (int)$count_stmt->fetchColumn();

		// Calculate reset time based on oldest message within the rolling 24-hour window
		$oldest_stmt = $pdo->prepare("
			SELECT received_at FROM incoming_messages 
			WHERE sender_host = ? 
			  AND is_sent_copy = 0 
			  AND received_at >= datetime('now', '-24 hours')
			ORDER BY received_at ASC 
			LIMIT 1
		");
		$oldest_stmt->execute([$sender_host]);
		$oldest_received = $oldest_stmt->fetchColumn();
		if ($oldest_received) {
			$reset_timestamp = strtotime($oldest_received) + 86400;
			if ($reset_timestamp <= time()) {
				$reset_timestamp = time() + 3600;
			}
			$delay_time_str = date('Y-m-d H:i:s', $reset_timestamp);
		}

		// "If a limit is applied so that the message is not sent, the message is saved in the sender's inbox as delayed. If it is not deleted from there, it can be sent after the delay."
		if ($current_count >= $daily_limit) {
			$delay_seconds = max(60, $reset_timestamp - time());
			http_response_code(429);
			echo json_encode([
				'success'               => false,
				'status'                => 'RATE_LIMITED_DELAY',
				'error'                 => 'RATE_LIMITED_DELAY',
				'limit'                 => $daily_limit,
				'scope'                 => $is_inside ? 'inside' : 'outside',
				'current_count'         => $current_count,
				'retry_after'           => $reset_timestamp,
				'retry_after_formatted' => $delay_time_str,
				'delay_seconds'         => $delay_seconds,
				'message'               => "Daily {$scope_label} limit of {$daily_limit} messages reached for website '{$sender_host}'. A delay is in effect on future messages. Message was not accepted. It can be sent after the delay ({$delay_time_str})."
			]);
			exit;
		}
	}

	// 4. Save to Inbox (incoming_messages)
	$verification_status  = 'STREAM_CONTEXT_POST';
	$verification_details = "Received via stream_context_create() HTTP POST request from {$sender_url} ({$client_ip}).";
	$current_domain = get_current_domain();

	$stmt = $pdo->prepare("
		INSERT INTO incoming_messages 
		(sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status, dns_verification_details, sender_name, sender_email, subject, message_body, recipient_user, all_recipients, is_sent_copy, target_domain) 
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
	");
	$stmt->execute([
		$sender_url,
		$sender_host,
		$client_ip,
		null,
		$verification_status,
		$verification_details,
		$sender_name ?: null,
		null,
		$subject,
		$message,
		null,
		$all_recipients ?: null,
		$current_domain
	]);
	$msg_id = $pdo->lastInsertId();

	// 4b. Send browser push notification to host and all subscribed clients
	// Push delivery options (outside sources, main broadcast, etc.) are evaluated inside send_browser_push_notification
	$is_main_point_sender = !empty($_POST['is_main_point_sender']) || ($sender_host !== '' && strtolower($sender_host) === strtolower(get_setting('subscribed_to_main_domain', '', $current_domain)));
	$main_included_itself = !empty($_POST['main_included_itself']);
	$suppress_push = !empty($_POST['suppress_push']) || ($is_main_point_sender && !$main_included_itself);

	if (function_exists('send_browser_push_notification') && !$suppress_push) {
		send_browser_push_notification($pdo, $current_domain, [
			'sender_name'  => $sender_name,
			'sender_url'   => $sender_url,
			'sender_host'  => $sender_host,
			'subject'      => $subject,
			'message_body' => $message,
			'received_at'  => date('Y-m-d H:i:s T')
		], [
			'is_inside'            => $is_inside,
			'sender_host'          => $sender_host,
			'sender_url'           => $sender_url,
			'sender_ip'            => $client_ip,
			'is_main_point_sender' => $is_main_point_sender,
			'main_included_itself' => $main_included_itself,
			'is_main_broadcast'    => !empty($_POST['is_main_broadcast'])
		]);
	}

	// 4c. Forward to any remote client subscribers (on external servers)
	try {
		$r_stmt = $pdo->prepare("SELECT client_url FROM main_subscriptions WHERE main_domain = ? AND is_local = 0 AND client_url != ''");
		$r_stmt->execute([$current_domain]);
		$remote_urls = $r_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
		foreach ($remote_urls as $r_url) {
			$clean_r = function_exists('format_address_display') ? format_address_display($r_url) : $r_url;
			// Skip if remote subscriber was already directly addressed in all_recipients or is the sender
			if ($all_recipients && stripos($all_recipients, $clean_r) !== false) {
				continue;
			}
			if ($sender_url && stripos($sender_url, $clean_r) !== false) {
				continue;
			}
			$f_payload = http_build_query([
				'action'               => 'inter_site_mail',
				'sender_url'           => $sender_url,
				'sender_name'          => $sender_name,
				'sender_host'          => $sender_host,
				'subject'              => $subject,
				'message'              => $message,
				'all_recipients'       => $all_recipients ?: null,
				'is_main_point_sender' => $is_main_point_sender ? 1 : 0,
				'main_included_itself' => $main_included_itself ? 1 : 0,
				'suppress_push'        => $suppress_push ? 1 : 0
			]);
			$f_ctx = stream_context_create([
				'http' => [
					'method'  => 'POST',
					'header'  => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
					'content' => $f_payload,
					'timeout' => 3
				],
				'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
			]);
			@file_get_contents($r_url, false, $f_ctx);
		}
	} catch (Exception $e) {}

	// 5. Construct Response with Warning / Delay Notice if applicable
	$resp = [
		'success'      => true,
		'message_id'   => $msg_id,
		'verification' => $verification_status,
		'note'         => 'Message received via stream_context_create() HTTP POST and accepted into host inbox.'
	];

	if ($rate_limit_enabled) {
		$new_count = $current_count + 1;
		$resp['limit'] = $daily_limit;
		$resp['scope'] = $is_inside ? 'inside' : 'outside';
		$resp['current_count'] = $new_count;

		// "A message will be accepted, but a response will be given that there will be a delay on future messages."
		if ($new_count >= $daily_limit) {
			$delay_notice = "Daily {$scope_label} limit of {$daily_limit} messages reached for your website. There will be a delay on future messages until {$delay_time_str}.";
			$resp['warning'] = $delay_notice;
			$resp['limit_reached'] = true;
			$resp['future_delay'] = true;
			$resp['retry_after'] = $reset_timestamp;
			$resp['retry_after_formatted'] = $delay_time_str;
			$resp['note'] .= " {$delay_notice}";
		}
		// "A warning message must be sent in reply to a message being sent if the next message will reach the limit."
		elseif ($new_count === $daily_limit - 1) {
			$warning_msg = "Warning: The next message from your website will reach the daily {$scope_label} limit of {$daily_limit} messages. Subsequent messages will experience a delay.";
			$resp['warning'] = $warning_msg;
			$resp['next_message_reaches_limit'] = true;
			$resp['note'] .= " {$warning_msg}";
		}
	}

	http_response_code(200);
	echo json_encode($resp);
	exit;
}

// --------------------------------------------------------------
// Handle Authentication Actions (Login, Logout)
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
	$current_domain = get_current_domain();
	$client_ip      = get_client_ip();
	$lock_status    = get_login_lock_status($client_ip, $current_domain);

	if ($lock_status['locked']) {
		$timer_str = format_lock_timer($lock_status['seconds_remaining']);
		$_SESSION['auth_feedback'] = [
			'success' => false,
			'error'   => "Maximum of " . MAX_LOGIN_ATTEMPTS_PER_IP . " login attempts reached. Login is locked for 6 hours (Time remaining: {$timer_str}, unlocks at {$lock_status['unlock_at']})."
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME']);
		exit;
	}

	$username_input = trim($_POST['username'] ?? '');
	$password_input = trim($_POST['password'] ?? '');

	$user = authenticate_user($username_input, $password_input);
	if ($user) {
		clear_failed_login_attempts($client_ip, $current_domain);
		$_SESSION['auth_user'] = [
			'id'       => (int)$user['id'],
			'domain'   => $current_domain,
			'username' => $user['username']
		];

		// Generate session token and set cookies (matching auth.php and signup.html)
		$session_token = bin2hex(random_bytes(32));
		$pdo = get_db();
		if ($pdo) {
			try {
				$stmt = $pdo->prepare("UPDATE users SET session_token = ? WHERE id = ?");
				$stmt->execute([$session_token, $user['id']]);
			} catch (Exception $e) {}
		}

		$cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
		setcookie('session_token', $session_token, [
			'expires'  => time() + SESSION_COOKIE_LIFETIME,
			'path'     => $cookie_path,
			'httponly' => false,
			'samesite' => 'Lax'
		]);
		setcookie('dw_user', $user['username'], [
			'expires'  => time() + SESSION_COOKIE_LIFETIME,
			'path'     => $cookie_path,
			'httponly' => false,
			'samesite' => 'Lax'
		]);

		$_SESSION['auth_feedback'] = ['success' => true, 'message' => "Welcome back, " . htmlspecialchars($user['username']) . "! You are logged in to {$current_domain}."];
		header('Location: ' . $_SERVER['SCRIPT_NAME']);
		exit;
	} else {
		record_failed_login_attempt($client_ip, $current_domain);
		$updated_lock = get_login_lock_status($client_ip, $current_domain);
		if ($updated_lock['locked']) {
			$timer_str = format_lock_timer($updated_lock['seconds_remaining']);
			$err_msg = "Invalid password. Maximum of " . MAX_LOGIN_ATTEMPTS_PER_IP . " login attempts reached. Login is now locked for 6 hours (Time remaining: {$timer_str}, unlocks at {$updated_lock['unlock_at']}).";
		} else {
			$err_msg = "Invalid password. {$updated_lock['remaining']} of " . MAX_LOGIN_ATTEMPTS_PER_IP . " login attempt(s) remaining.";
		}
		$_SESSION['auth_feedback'] = ['success' => false, 'error' => $err_msg];
		header('Location: ' . $_SERVER['SCRIPT_NAME']);
		exit;
	}
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
	unset($_SESSION['auth_user']);
	$cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
	setcookie('session_token', '', ['expires' => time() - 3600, 'path' => $cookie_path]);
	setcookie('dw_user', '', ['expires' => time() - 3600, 'path' => $cookie_path]);
	$_SESSION['auth_feedback'] = ['success' => true, 'message' => "You have been logged out."];
	header('Location: ' . $_SERVER['SCRIPT_NAME']);
	exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'reset_password') {
	$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
	$cur_dom = get_current_domain();
	$valid_user = null;
	$reset_error = '';

	if (!empty($token)) {
		$stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND (domain = ? OR domain IS NULL OR domain = '') AND (reset_expires_at IS NULL OR reset_expires_at > datetime('now')) LIMIT 1");
		$stmt->execute([$token, $cur_dom]);
		$valid_user = $stmt->fetch();
	}

	if (!$valid_user) {
		$reset_error = 'This password setup link is invalid or has expired.';
	} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$new_pass = trim($_POST['new_password'] ?? '');
		$confirm_pass = trim($_POST['confirm_password'] ?? '');
		if ($new_pass === '') {
			$reset_error = 'Password cannot be empty.';
		} elseif ($new_pass !== $confirm_pass) {
			$reset_error = 'Passwords do not match.';
		} else {
			$hash = password_hash($new_pass, PASSWORD_BCRYPT);
			$new_session_token = bin2hex(random_bytes(32));
			$upd = $pdo->prepare("UPDATE users SET domain = ?, password = ?, session_token = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
			$upd->execute([$cur_dom, $hash, $new_session_token, $valid_user['id']]);

			try {
				$pdo->prepare("DELETE FROM login_attempts WHERE domain = ? OR domain IS NULL")->execute([$cur_dom]);
			} catch (Exception $e) {}

			$_SESSION['auth_user'] = [
				'id'       => (int)$valid_user['id'],
				'username' => $valid_user['username'],
				'domain'   => $cur_dom
			];
			$cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/';
			setcookie('session_token', $new_session_token, [
				'expires'  => time() + SESSION_COOKIE_LIFETIME,
				'path'     => $cookie_path,
				'httponly' => true,
				'samesite' => 'Lax'
			]);
			$_SESSION['auth_feedback'] = ['success' => true, 'message' => "Password successfully set. You are now signed in."];
			header('Location: ' . $_SERVER['SCRIPT_NAME']);
			exit;
		}
	}

	header('Content-Type: text/html; charset=utf-8');
	?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
	<title>Set Password — <?= htmlspecialchars($cur_dom) ?></title>
	<style>
		:root {
			--furnace-core: #0d0b0a;
			--chassis-iron: #1a1614;
			--crucible-charcoal: #28201b;
			--brass-stamped: #543d2b;
			--filament-amber: #ffb347;
			--hot-letter-orange: #ff5c26;
			--on-air-crimson: #9c2415;
			--incandescent-white: #fff4e6;
		}
		*, *::before, *::after { font-size: 12pt !important; line-height: 1.45; box-sizing: border-box; font-family: 'Courier New', monospace; }
		body { background: var(--furnace-core); color: var(--filament-amber); margin: 0; padding: 24px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
		.card { background: var(--chassis-iron); border: 2px solid var(--brass-stamped); padding: 20px; max-width: 440px; width: 100%; box-shadow: 0 4px 16px rgba(0,0,0,0.7); }
		h2 { color: var(--incandescent-white); margin-top: 0; border-bottom: 2px solid var(--brass-stamped); padding-bottom: 8px; }
		.error { color: #ff6b6b; background: rgba(156,36,21,0.25); border: 1px solid var(--on-air-crimson); padding: 10px; margin-bottom: 14px; }
		.field { margin-bottom: 14px; }
		label { display: block; margin-bottom: 4px; color: var(--filament-amber); }
		input[type="password"] { width: 100%; padding: 8px; background: var(--crucible-charcoal); border: 1px solid var(--brass-stamped); color: var(--incandescent-white); }
		button { width: 100%; padding: 10px; background: var(--hot-letter-orange); color: #000; border: none; font-weight: bold; cursor: pointer; }
		button:hover { background: #ff7849; }
		a { color: var(--hot-letter-orange); }
	</style>
</head>
<body>
	<div class="card">
		<h2>Set Password — <?= htmlspecialchars($cur_dom) ?></h2>
		<?php if (!empty($reset_error)): ?>
			<div class="error"><?= htmlspecialchars($reset_error) ?></div>
		<?php endif; ?>
		<?php if ($valid_user): ?>
			<p>Setting password for user <strong><?= htmlspecialchars($valid_user['username']) ?></strong> at <strong><?= htmlspecialchars($cur_dom) ?></strong>.</p>
			<form method="POST" action="?action=reset_password&token=<?= urlencode($token) ?>">
				<input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
				<div class="field">
					<label for="new_password">New Password:</label>
					<input type="password" id="new_password" name="new_password" required autofocus>
				</div>
				<div class="field">
					<label for="confirm_password">Confirm New Password:</label>
					<input type="password" id="confirm_password" name="confirm_password" required>
				</div>
				<button type="submit">Save Password &amp; Sign In</button>
			</form>
		<?php else: ?>
			<p><a href="<?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?>">&larr; Return to Sign In</a></p>
		<?php endif; ?>
	</div>
</body>
</html>
	<?php
	exit;
}

// --------------------------------------------------------------
// Handle Host Control Actions (Only for Logged-In Host)
// --------------------------------------------------------------
if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';

	// Toggle Global Communications ON / OFF
	if ($action === 'toggle_communications') {
		$current = get_setting('allow_incoming_communications', '1');
		$next = ($current === '1') ? '0' : '1';
		set_setting('allow_incoming_communications', $next);
		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => ($next === '1') ? 'Incoming communications are now ENABLED.' : 'Incoming communications have been TOGGLED OFF.'
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Toggle Browser Push Notifications ON / OFF for Incoming Messages
	if ($action === 'toggle_push_notifications') {
		$dom = get_current_domain();
		$current = get_setting('push_notifications_enabled', '1', $dom);
		$next = ($current === '1') ? '0' : '1';
		set_setting('push_notifications_enabled', $next, $dom);
		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => ($next === '1') ? "Browser push notifications for incoming messages are now ENABLED on {$dom}." : "Browser push notifications for incoming messages have been TOGGLED OFF on {$dom}."
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Save Granular Push Notification Delivery Options
	if ($action === 'save_push_options') {
		$dom = get_current_domain();
		$enabled        = isset($_POST['push_notifications_enabled']) ? '1' : '0';
		$outside        = isset($_POST['push_notifications_outside_enabled']) ? '1' : '0';
		$inside         = isset($_POST['push_notifications_inside_enabled']) ? '1' : '0';
		$main_broadcast = isset($_POST['push_notifications_main_broadcast_enabled']) ? '1' : '0';

		set_setting('push_notifications_enabled', $enabled, $dom);
		set_setting('push_notifications_outside_enabled', $outside, $dom);
		set_setting('push_notifications_inside_enabled', $inside, $dom);
		set_setting('push_notifications_main_broadcast_enabled', $main_broadcast, $dom);

		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => "Push notification options for {$dom} updated successfully."
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Disable Push Notifications for an Individual Source
	if ($action === 'disable_push_source') {
		$dom = get_current_domain();
		$src = trim($_POST['source'] ?? '');
		$return_tab = (($_POST['return_tab'] ?? '') === 'inbox') ? 'inbox' : 'comms';
		if ($src !== '') {
			$pdo = get_db();
			if (function_exists('disable_push_for_source')) {
				disable_push_for_source($pdo, $dom, $src);
			}
			$_SESSION['comms_feedback'] = [
				'success' => true,
				'message' => "Push notifications disabled for individual source '{$src}'. Incoming messages will still arrive in your inbox and will be present in the unread count until marked as read."
			];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=' . $return_tab);
		exit;
	}

	// Re-enable Push Notifications for an Individual Source
	if ($action === 'enable_push_source') {
		$dom = get_current_domain();
		$src = trim($_POST['source'] ?? '');
		$return_tab = (($_POST['return_tab'] ?? '') === 'inbox') ? 'inbox' : 'comms';
		if ($src !== '') {
			$pdo = get_db();
			if (function_exists('enable_push_for_source')) {
				enable_push_for_source($pdo, $dom, $src);
			}
			$_SESSION['comms_feedback'] = [
				'success' => true,
				'message' => "Push notifications re-enabled for individual source '{$src}'."
			];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=' . $return_tab);
		exit;
	}

	// Update Website Message Rate Limit Controls (Outside vs Inside)
	if ($action === 'update_rate_limit') {
		$enabled = isset($_POST['rate_limiting_enabled']) ? '1' : '0';
		$limit_outside = (int)($_POST['rate_limit_outside_per_day'] ?? ($_POST['rate_limit_per_website_per_day'] ?? 10));
		$limit_inside = (int)($_POST['rate_limit_inside_per_day'] ?? 100);
		if ($limit_outside < 1) $limit_outside = 10;
		if ($limit_inside < 1) $limit_inside = 100;
		set_setting('rate_limiting_enabled', $enabled);
		set_setting('rate_limit_outside_per_day', (string)$limit_outside);
		set_setting('rate_limit_inside_per_day', (string)$limit_inside);
		set_setting('rate_limit_per_website_per_day', (string)$limit_outside);
		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => "Message rate limits updated: " . ($enabled === '1' ? "Active (Outside: {$limit_outside} msg/day | Inside: {$limit_inside} msg/day)." : "Disabled.")
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Block Site / Domain / IP
	if ($action === 'block_site') {
		$target = mb_substr(trim($_POST['target'] ?? ''), 0, MAX_LEN_VARCHAR_255, 'UTF-8');
		$reason = mb_substr(trim($_POST['reason'] ?? 'Toggled off by host'), 0, MAX_LEN_VARCHAR_255, 'UTF-8');
		if ($target !== '') {
			$pdo = get_db();
			if ($pdo) {
				$stmt = $pdo->prepare("INSERT INTO blocked_sites (domain_or_url, reason, is_blocked) VALUES (?, ?, 1) ON CONFLICT(domain_or_url) DO UPDATE SET is_blocked = 1, reason = excluded.reason");
				$stmt->execute([$target, $reason]);
			}
			$_SESSION['comms_feedback'] = ['success' => true, 'message' => "Site '{$target}' has been blocked/toggled off."];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Unblock Site
	if ($action === 'unblock_site') {
		$block_id = (int)($_POST['block_id'] ?? 0);
		if ($block_id > 0) {
			$pdo = get_db();
			if ($pdo) {
				$stmt = $pdo->prepare("DELETE FROM blocked_sites WHERE id = ?");
				$stmt->execute([$block_id]);
			}
			$_SESSION['comms_feedback'] = ['success' => true, 'message' => "Site removed from blocklist."];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Send Delayed Message (After Rate Limit Delay)
	if ($action === 'send_delayed_message') {
		$msg_id = (int)($_POST['message_id'] ?? 0);
		$current_user = get_logged_in_username();
		if ($msg_id > 0) {
			$pdo = get_db();
			if ($pdo) {
				$stmt = $pdo->prepare("SELECT * FROM incoming_messages WHERE id = ? AND is_delayed = 1");
				$stmt->execute([$msg_id]);
				$msg = $stmt->fetch(PDO::FETCH_ASSOC);

				if ($msg) {
					$raw_dest = $msg['destination_url'] ?: $msg['sender_url'];
					$canonical_url = get_canonical_site_url();

					$dest_entries = array_filter(array_map('trim', explode(';', $raw_dest)));
					$target_urls = [];
					foreach ($dest_entries as $entry) {
						$u = trim(preg_replace('/\s*\([^)]*\)/', '', $entry));
						if ($u !== '' && !in_array($u, $target_urls, true)) {
							$target_urls[] = $u;
						}
					}
					if (empty($target_urls)) {
						$target_urls[] = trim(preg_replace('/\s*\([^)]*\)/', '', $raw_dest));
					}

					$all_ok = true;
					$retry_ts = null;
					$retry_fmt = null;
					$delivered_notes = [];

					foreach ($target_urls as $target_url) {
						$payload = [
							'action'         => 'inter_site_mail',
							'sender_url'     => $canonical_url,
							'sender_name'    => $msg['sender_name'] ?: $current_user,
							'subject'        => $msg['subject'],
							'message'        => $msg['message_body'],
							'all_recipients' => $msg['all_recipients'] ?: '',
							'timestamp'      => time(),
						];

						$res = dispatch_inter_site_mail($target_url, $payload);
						$is_ok = ($res['status_code'] >= 200 && $res['status_code'] < 300 && !empty($res['json']['success']));

						if ($is_ok) {
							$delivered_notes[] = "{$target_url}: Delivered";
						} else {
							$all_ok = false;
							if (!empty($res['json']['retry_after'])) {
								$retry_ts = date('Y-m-d H:i:s', $res['json']['retry_after']);
								$retry_fmt = $res['json']['retry_after_formatted'] ?? $retry_ts;
							}
							break;
						}
					}

					if ($all_ok) {
						$upd = $pdo->prepare("
							UPDATE incoming_messages 
							SET is_delayed = 0, 
							    dns_verification_status = 'SENT_COPY_STORED', 
							    dns_verification_details = 'Dispatched and delivered after delay',
							    received_at = CURRENT_TIMESTAMP
							WHERE id = ?
						");
						$upd->execute([$msg_id]);

						$stmt_sent = $pdo->prepare("
							INSERT INTO sent_messages 
							(destination_url, subject, message_body, delivery_status, response_code, response_message, recipient_users, all_recipients)
							VALUES (?, ?, ?, 'DELIVERED', 200, ?, NULL, ?)
						");
						$stmt_sent->execute([
							implode('; ', $target_urls),
							$msg['subject'],
							$msg['message_body'],
							"Delivered after delay: " . implode('; ', $delivered_notes),
							$msg['all_recipients']
						]);

						$_SESSION['comms_feedback'] = [
							'success' => true,
							'message' => "✓ Delayed message #{$msg_id} was successfully dispatched and delivered to destination website(s)!"
						];
					} else {
						if ($retry_ts) {
							$upd = $pdo->prepare("UPDATE incoming_messages SET retry_after = ? WHERE id = ?");
							$upd->execute([$retry_ts, $msg_id]);
						}
						$_SESSION['comms_feedback'] = [
							'success' => false,
							'error' => "Remote website rate limit is still active until " . ($retry_fmt ?? 'later') . ". Message remains in inbox as delayed."
						];
					}
				}
			}
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
		exit;
	}

	// Delete Message (Supports single-copy broadcast and server group letter preservation)
	if ($action === 'delete_message') {
		$msg_id = (int)($_POST['message_id'] ?? 0);
		$cur_dom = get_current_domain();
		$cur_base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $cur_dom)));
		if ($msg_id > 0) {
			$pdo = get_db();
			if ($pdo) {
				$stmt = $pdo->prepare("SELECT sender_host, target_domain, is_sent_copy FROM incoming_messages WHERE id = ?");
				$stmt->execute([$msg_id]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);

				// Determine if this message is shared across the server group
				$is_shared = false;
				if ($row) {
					$t_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($row['target_domain'] ?: '', PHP_URL_HOST) ?: ($row['target_domain'] ?? ''))));
					$s_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($row['sender_host'] ?: '', PHP_URL_HOST) ?: ($row['sender_host'] ?? ''))));

					// If viewing domain is not the target domain
					if ($t_clean !== '' && $t_clean !== $cur_base) {
						$is_shared = true;
					}

					// If sender is in server group
					if (function_exists('is_sender_in_server_group') && is_sender_in_server_group($s_clean, '', $pdo)) {
						$is_shared = true;
					}

					// If target is in main_subscriptions (transmitter broadcast)
					$sub_mains = [];
					try {
						$sub_mains = $pdo->query("SELECT DISTINCT main_domain FROM main_subscriptions")->fetchAll(PDO::FETCH_COLUMN) ?: [];
					} catch (Exception $e) {}
					if (in_array($t_clean, $sub_mains, true)) {
						$is_shared = true;
					}
				}

				// Record deletion for this client domain
				try {
					$del_rec = $pdo->prepare("INSERT OR IGNORE INTO message_deletions (message_id, domain) VALUES (?, ?)");
					$del_rec->execute([$msg_id, $cur_dom]);
					if ($cur_base !== $cur_dom) {
						$del_rec->execute([$msg_id, $cur_base]);
					}
				} catch (Exception $e) {}

				// Only remove the database row if this message was strictly private to this domain
				if (!$is_shared) {
					$del_stmt = $pdo->prepare("DELETE FROM incoming_messages WHERE id = ? AND (target_domain = ? OR target_domain = ? OR target_domain = '' OR target_domain IS NULL)");
					$del_stmt->execute([$msg_id, $cur_dom, $cur_base]);
				}

				$_SESSION['comms_feedback'] = [
					'success' => true,
					'message' => "Message #{$msg_id} deleted from your inbox."
				];
			}
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
		exit;
	}

	// Toggle Read / Unread Status on a Single Message (Per-domain tracking)
	if ($action === 'toggle_read') {
		$msg_id = (int)($_POST['message_id'] ?? 0);
		$cur_dom = get_current_domain();
		$cur_base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $cur_dom)));
		if ($msg_id > 0) {
			$pdo = get_db();
			if ($pdo) {
				$stmt = $pdo->prepare("SELECT sender_host, target_domain, is_read FROM incoming_messages WHERE id = ?");
				$stmt->execute([$msg_id]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);

				$is_shared = false;
				if ($row) {
					$t_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($row['target_domain'] ?: '', PHP_URL_HOST) ?: ($row['target_domain'] ?? ''))));
					$s_clean = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($row['sender_host'] ?: '', PHP_URL_HOST) ?: ($row['sender_host'] ?? ''))));

					if ($t_clean !== '' && $t_clean !== $cur_base) {
						$is_shared = true;
					}
					if (function_exists('is_sender_in_server_group') && is_sender_in_server_group($s_clean, '', $pdo)) {
						$is_shared = true;
					}
				}

				$read_check = $pdo->prepare("SELECT count(*) FROM message_reads WHERE message_id = ? AND (domain = ? OR domain = ?)");
				$read_check->execute([$msg_id, $cur_dom, $cur_base]);
				$already_read = ($read_check->fetchColumn() > 0) || (!$is_shared && !empty($row['is_read']));

				if ($already_read) {
					$pdo->prepare("DELETE FROM message_reads WHERE message_id = ? AND (domain = ? OR domain = ?)")->execute([$msg_id, $cur_dom, $cur_base]);
					if (!$is_shared) {
						$pdo->prepare("UPDATE incoming_messages SET is_read = 0 WHERE id = ?")->execute([$msg_id]);
					}
				} else {
					$pdo->prepare("INSERT OR IGNORE INTO message_reads (message_id, domain) VALUES (?, ?)")->execute([$msg_id, $cur_dom]);
					if ($cur_base !== $cur_dom) {
						$pdo->prepare("INSERT OR IGNORE INTO message_reads (message_id, domain) VALUES (?, ?)")->execute([$msg_id, $cur_base]);
					}
					if (!$is_shared) {
						$pdo->prepare("UPDATE incoming_messages SET is_read = 1 WHERE id = ?")->execute([$msg_id]);
					}
				}
			}
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
		exit;
	}

	// Mark All Messages in Inbox as Read for This Domain
	if ($action === 'mark_all_read') {
		$pdo = get_db();
		$cur_dom = get_current_domain();
		$cur_base = strtolower(trim(preg_replace('/:[0-9]+$/', '', $cur_dom)));
		if ($pdo) {
			if (function_exists('get_inbox_messages_for_domain')) {
				$msgs = get_inbox_messages_for_domain($pdo, $cur_dom);
				$ins_read = $pdo->prepare("INSERT OR IGNORE INTO message_reads (message_id, domain) VALUES (?, ?)");
				foreach ($msgs as $m) {
					$ins_read->execute([$m['id'], $cur_dom]);
					if ($cur_base !== $cur_dom) {
						$ins_read->execute([$m['id'], $cur_base]);
					}
					// Only update incoming_messages if private direct
					if (empty($m['is_server_group_letter']) && empty($m['is_broadcast_from_main']) && !empty($m['target_domain']) && (strcasecmp($m['target_domain'], $cur_dom) === 0 || strcasecmp($m['target_domain'], $cur_base) === 0)) {
						$pdo->prepare("UPDATE incoming_messages SET is_read = 1 WHERE id = ?")->execute([$m['id']]);
					}
				}
			}
			$_SESSION['comms_feedback'] = ['success' => true, 'message' => 'All inbox messages marked as read.'];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
		exit;
	}

	// Subscribe Client to Main Website
	if ($action === 'subscribe_main') {
		$main_dom = trim($_POST['main_domain'] ?? '');
		$cur_dom = get_current_domain();
		if ($main_dom !== '') {
			$main_dom = preg_replace('/^https?:\/\//i', '', $main_dom);
			$main_dom = preg_replace('/\/.*$/', '', $main_dom);
			set_setting('subscribed_to_main_domain', $main_dom);
			set_setting('server_role', 'client');
			$pdo = get_db();
			if ($pdo && function_exists('subscribe_client_to_main')) {
				subscribe_client_to_main($pdo, $cur_dom, $main_dom, '', 1);
			}
			$_SESSION['comms_feedback'] = [
				'success' => true,
				'message' => "Successfully subscribed to messages from {$main_dom}. All incoming messages to {$main_dom} are now shared with your inbox."
			];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Unsubscribe Client from Main Website
	if ($action === 'unsubscribe_main') {
		$main_dom = trim($_POST['main_domain'] ?? '');
		$cur_dom = get_current_domain();
		set_setting('subscribed_to_main_domain', '');
		$pdo = get_db();
		if ($pdo && function_exists('unsubscribe_client_from_main')) {
			unsubscribe_client_from_main($pdo, $cur_dom, $main_dom);
		}
		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => "Unsubscribed from main website messages."
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Save Default Messaging Form Presets
	if ($action === 'save_default_destinations') {
		$d_urls = $_POST['default_urls'] ?? [];
		$presets = [];
		for ($i = 0; $i < count($d_urls); $i++) {
			$u = trim($d_urls[$i] ?? '');
			if ($u !== '') {
				if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
				$u = mb_substr($u, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
				$presets[] = ['url' => $u];
			}
		}
		set_setting('default_mail_destinations', json_encode($presets));
		$_SESSION['mail_feedback'] = ['success' => true, 'message' => "Default messaging form destinations updated (" . count($presets) . " saved)."];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=mail');
		exit;
	}

	// Send Outbound Federated Mail Form Dispatch
	if ($action === 'send_mail') {
		$destination_urls = $_POST['destination_urls'] ?? [];
		$include_all      = !empty($_POST['include_all_recipients']);
		$subject          = mb_substr(trim($_POST['subject'] ?? ''), 0, MAX_LEN_VARCHAR_255, 'UTF-8');
		$message          = trim($_POST['message'] ?? '');
		$sender_name      = mb_substr(trim($_POST['sender_name'] ?? get_logged_in_username()), 0, MAX_LEN_VARCHAR_100, 'UTF-8');
		$canonical_url    = get_canonical_site_url();

		if (mb_strlen($message, 'UTF-8') > MAX_LEN_TEXT_UTF8MB4 || strlen($message) > MAX_LEN_TEXT_BYTES) {
			$_SESSION['mail_feedback'] = [
				'success' => false,
				'error'   => 'Message body exceeds the maximum limit of ' . number_format(MAX_LEN_TEXT_UTF8MB4) . ' characters (based on 4-byte utf8mb4 emojis / ' . number_format(MAX_LEN_TEXT_BYTES) . ' bytes).'
			];
			header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=mail');
			exit;
		}

		// Parse and deduplicate destination URLs (enforce HTTPS, allow omitting https://)
		$target_urls = [];
		$target_norm_keys = [];
		for ($i = 0; $i < count($destination_urls); $i++) {
			$raw_u = trim($destination_urls[$i] ?? '');
			if ($raw_u === '') continue;
			$clean_key = strtolower(function_exists('format_address_display') ? format_address_display($raw_u) : $raw_u);
			if ($clean_key === '' || in_array($clean_key, $target_norm_keys, true)) {
				continue;
			}
			$target_norm_keys[] = $clean_key;

			$u = ltrim($raw_u, '/');
			if (preg_match('#^http://#i', $u)) {
				$u = preg_replace('#^http://#i', 'https://', $u);
			} elseif (!preg_match('#^https://#i', $u)) {
				$u = 'https://' . $u;
			}
			$u = preg_replace('#/index\.php$#i', '', $u);
			$u = mb_substr($u, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
			$target_urls[] = $u;
		}

		if (empty($target_urls) || $message === '') {
			$_SESSION['mail_feedback'] = ['success' => false, 'error' => 'At least one To destination and Message are required.'];
			header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=mail');
			exit;
		}

		$current_domain = get_current_domain();
		$is_main_point = (function_exists('get_setting') && get_setting('server_role', 'main', $current_domain) === 'main');
		$canonical_clean = function_exists('format_address_display') ? format_address_display($canonical_url) : $canonical_url;
		$main_host = preg_replace('/:[0-9]+$/', '', parse_url($canonical_url, PHP_URL_HOST) ?: $current_domain);

		// If sender is main contact point, check if it explicitly included itself in recipient list
		$main_included_itself = false;
		if ($is_main_point) {
			foreach ($target_urls as $tu) {
				$tu_clean = function_exists('format_address_display') ? format_address_display($tu) : $tu;
				$tu_host = preg_replace('/:[0-9]+$/', '', parse_url('https://' . ltrim($tu_clean, '/'), PHP_URL_HOST) ?: $tu_clean);
				if (strcasecmp($tu_host, $main_host) === 0 || strcasecmp($tu_clean, $canonical_clean) === 0) {
					$main_included_itself = true;
					break;
				}
			}
		}

		$all_recipients_str = implode('; ', array_map('format_address_display', $target_urls));

		$dispatch_results = [];
		$success_count = 0;
		$delayed_count = 0;
		$total_servers = count($target_urls);
		$remote_warnings = [];
		$delayed_servers_info = [];

		foreach ($target_urls as $target_url) {
			$tu_clean = function_exists('format_address_display') ? format_address_display($target_url) : $target_url;
			// If main contact point included itself in recipient list, handle self-broadcast locally without redundant failing loopback HTTP request
			if ($is_main_point && (strcasecmp($tu_clean, $canonical_clean) === 0 || strcasecmp(preg_replace('/:[0-9]+$/', '', parse_url($target_url, PHP_URL_HOST) ?: ''), $main_host) === 0)) {
				$success_count++;
				$dispatch_results[] = [
					'url'         => $target_url,
					'status_code' => 200,
					'success'     => true,
					'delayed'     => false,
					'verif'       => 'SELF_BROADCAST',
					'error'       => '',
					'note'        => 'Self included by main contact point: will notify all subscribers.'
				];
				continue;
			}

			$payload = [
				'action'               => 'inter_site_mail',
				'sender_url'           => $canonical_url,
				'sender_name'          => $sender_name,
				'subject'              => $subject,
				'message'              => $message,
				'all_recipients'       => $include_all ? $all_recipients_str : '',
				'is_main_point_sender' => $is_main_point ? 1 : 0,
				'main_included_itself' => $main_included_itself ? 1 : 0,
				'suppress_push'        => ($is_main_point && !$main_included_itself) ? 1 : 0,
				'timestamp'            => time(),
			];

			$res = dispatch_inter_site_mail($target_url, $payload);
			$is_ok = ($res['status_code'] >= 200 && $res['status_code'] < 300 && !empty($res['json']['success']));
			$is_delayed = ($res['status_code'] === 429 || (!empty($res['json']['status']) && $res['json']['status'] === 'RATE_LIMITED_DELAY'));

			// Local server group delivery fallback if loopback HTTP failed
			$dest_host = strtolower(trim(preg_replace('/:[0-9]+$/', '', parse_url($target_url, PHP_URL_HOST) ?: $target_url)));
			$pdo_local = get_db();
			if (!$is_ok && !$is_delayed && $pdo_local && function_exists('is_sender_in_server_group') && is_sender_in_server_group($dest_host, '', $pdo_local)) {
				try {
					$local_ins = $pdo_local->prepare("
						INSERT INTO incoming_messages 
						(sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status, dns_verification_details, sender_name, sender_email, subject, message_body, recipient_user, all_recipients, is_sent_copy, target_domain) 
						VALUES (?, ?, ?, ?, 'LOCAL_SERVER_DELIVERY', 'Delivered locally within server group', ?, NULL, ?, ?, NULL, ?, 0, ?)
					");
					$local_ins->execute([
						$canonical_url,
						$main_host,
						'127.0.0.1',
						'local-server-group',
						$sender_name ?: null,
						$subject,
						$message,
						$include_all ? $all_recipients_str : null,
						$dest_host
					]);
					$is_ok = true;
					$res['status_code'] = 200;
					$res['verification'] = 'LOCAL_SERVER_DELIVERY';
					$res['json'] = ['success' => true, 'verification' => 'LOCAL_SERVER_DELIVERY', 'note' => 'Delivered locally within server group.'];
				} catch (Exception $e) {}
			}

			if ($is_ok) $success_count++;
			if ($is_delayed) {
				$delayed_count++;
				$delayed_servers_info[] = [
					'url'                   => $target_url,
					'retry_after'           => $res['json']['retry_after'] ?? null,
					'retry_after_formatted' => $res['json']['retry_after_formatted'] ?? null,
					'msg'                   => $res['json']['message'] ?? 'Rate limit delay in effect'
				];
			}
			if (!empty($res['json']['warning'])) {
				$remote_warnings[] = $res['json']['warning'];
			}

			$dispatch_results[] = [
				'url'         => $target_url,
				'status_code' => $res['status_code'],
				'success'     => $is_ok,
				'delayed'     => $is_delayed,
				'verif'       => $res['json']['verification'] ?? '',
				'error'       => $res['json']['error'] ?? ("HTTP status " . $res['status_code']),
				'note'        => $res['json']['note'] ?? ''
			];
		}

		$combined_dest_url = implode('; ', array_map('format_address_display', $target_urls));

		if ($success_count === $total_servers) {
			$delivery_status = 'DELIVERED';
		} elseif ($delayed_count === $total_servers) {
			$delivery_status = 'DELAYED';
		} elseif ($success_count > 0 || $delayed_count > 0) {
			$delivery_status = 'PARTIALLY_DELIVERED';
		} else {
			$delivery_status = 'FAILED';
		}

		$resp_summaries = [];
		foreach ($dispatch_results as $dr) {
			$dr_clean = function_exists('format_address_display') ? format_address_display($dr['url']) : $dr['url'];
			if ($dr['success']) {
				$resp_summaries[] = "{$dr_clean}: ✓ Delivered ({$dr['verif']})";
			} elseif (!empty($dr['delayed'])) {
				$resp_summaries[] = "{$dr_clean}: ⏳ Delayed (Rate limit reached on remote site)";
			} else {
				$resp_summaries[] = "{$dr_clean}: ✕ Failed ({$dr['error']})";
			}
		}
		$combined_resp_message = implode("\n", $resp_summaries);

		$pdo = get_db();
		if ($pdo) {
			// 1. Store single message in sent outbox
			$stmt = $pdo->prepare("
				INSERT INTO sent_messages 
				(destination_url, subject, message_body, delivery_status, response_code, response_message, recipient_users, all_recipients) 
				VALUES (?, ?, ?, ?, ?, ?, NULL, ?)
			");
			$stmt->execute([
				$combined_dest_url,
				$subject,
				$message,
				$delivery_status,
				($total_servers === 1 ? $dispatch_results[0]['status_code'] : ($delivery_status === 'DELAYED' ? 429 : 200)),
				$combined_resp_message,
				$include_all ? $all_recipients_str : null
			]);

			// 2. Store in Sender's Inbox (strictly scoped to current domain)
			$is_msg_delayed = ($delivery_status === 'DELAYED' || ($delayed_count > 0 && $success_count === 0));
			$retry_ts = null;
			$retry_formatted = null;
			if ($is_msg_delayed && !empty($delayed_servers_info)) {
				$retry_ts = $delayed_servers_info[0]['retry_after'] ? date('Y-m-d H:i:s', $delayed_servers_info[0]['retry_after']) : date('Y-m-d H:i:s', time() + 86400);
				$retry_formatted = $delayed_servers_info[0]['retry_after_formatted'] ?? $retry_ts;
			}

			$stmt_inbox = $pdo->prepare("
				INSERT INTO incoming_messages 
				(sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status, dns_verification_details, sender_name, sender_email, subject, message_body, recipient_user, is_sent_copy, all_recipients, is_read, is_delayed, retry_after, destination_url, target_domain) 
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?, 1, ?, ?, ?, ?)
			");
			$stmt_inbox->execute([
				$canonical_url,
				$main_host,
				$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
				'self-outbox',
				$is_msg_delayed ? 'DELAYED' : 'SENT_COPY_STORED',
				$is_msg_delayed ? ("Delayed by remote website rate limit. Can be sent after: {$retry_formatted}.") : ("Dispatched to {$total_servers} destination(s). Delivery: {$delivery_status}."),
				$sender_name,
				null,
				$subject,
				$message,
				$include_all ? $all_recipients_str : null,
				$is_msg_delayed ? 1 : 0,
				$retry_ts,
				$combined_dest_url,
				$current_domain
			]);

			// 3. For any message sent from the main contact point, all subscribers get a copy in their inbox!
			if ($is_main_point) {
				// 3a. Shared SQLite: Store single broadcast copy accessible by all local subscribers via sub_mains
				$stmt_b = $pdo->prepare("
					INSERT INTO incoming_messages 
					(sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status, dns_verification_details, sender_name, sender_email, subject, message_body, recipient_user, is_sent_copy, all_recipients, is_read, is_delayed, retry_after, destination_url, target_domain) 
					VALUES (?, ?, ?, ?, 'MAIN_BROADCAST', 'Message from main contact point to subscribers', ?, NULL, ?, ?, NULL, 0, ?, 0, 0, NULL, ?, ?)
				");
				$stmt_b->execute([
					$canonical_url,
					$main_host,
					$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
					'main-broadcast',
					$sender_name,
					$subject,
					$message,
					$include_all ? $all_recipients_str : null,
					$combined_dest_url,
					$current_domain
				]);

				// 3b. Remote Subscribers (is_local = 0): Dispatch copy over HTTP if not already in target_urls
				try {
					$r_stmt = $pdo->prepare("SELECT client_url FROM main_subscriptions WHERE main_domain = ? AND is_local = 0 AND client_url != ''");
					$r_stmt->execute([$current_domain]);
					$remotes = $r_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
					foreach ($remotes as $r_url) {
						$r_clean = function_exists('format_address_display') ? format_address_display($r_url) : $r_url;
						$in_targets = false;
						foreach ($target_urls as $tu) {
							if (strcasecmp(function_exists('format_address_display') ? format_address_display($tu) : $tu, $r_clean) === 0) {
								$in_targets = true;
								break;
							}
						}
						if (!$in_targets) {
							$payload_rem = [
								'action'               => 'inter_site_mail',
								'sender_url'           => $canonical_url,
								'sender_name'          => $sender_name,
								'sender_host'          => $main_host,
								'subject'              => $subject,
								'message'              => $message,
								'all_recipients'       => $all_recipients_str,
								'is_main_point_sender' => 1,
								'main_included_itself' => $main_included_itself ? 1 : 0,
								'suppress_push'        => $main_included_itself ? 0 : 1,
								'timestamp'            => time()
							];
							dispatch_inter_site_mail($r_url, $payload_rem);
						}
					}
				} catch (Exception $e) {}

				// 3c. Push Notification to Subscribers:
				// Only if the main contact point included itself in the recipient list!
				if ($main_included_itself && function_exists('send_browser_push_notification')) {
					send_browser_push_notification($pdo, $current_domain, [
						'sender_name'  => $sender_name,
						'sender_url'   => $canonical_url,
						'sender_host'  => $main_host,
						'subject'      => $subject,
						'message_body' => $message,
						'received_at'  => date('Y-m-d H:i:s T')
					], [
						'is_main_point_sender' => true,
						'main_included_itself' => true,
						'is_main_broadcast'    => true,
						'is_inside'            => true,
						'sender_host'          => $main_host,
						'sender_url'           => $canonical_url
					]);
				}
			}
		}

		if ($delivery_status === 'DELIVERED') {
			$msg_text = "✓ Mail delivered successfully to {$total_servers} destination(s)! Copy stored in your inbox.";
			if (!empty($remote_warnings)) {
				$msg_text .= " ⚠ Notice from recipient: " . implode('; ', array_unique($remote_warnings));
			}
			$_SESSION['mail_feedback'] = [
				'success' => true,
				'message' => $msg_text
			];
		} elseif ($delivery_status === 'DELAYED') {
			$_SESSION['mail_feedback'] = [
				'success' => false,
				'warning' => true,
				'message' => "⚠ Destination daily message limit reached. The message was not sent, but has been saved in your inbox as DELAYED. You can send it after the delay."
			];
		} elseif ($delivery_status === 'PARTIALLY_DELIVERED') {
			$_SESSION['mail_feedback'] = [
				'success' => true,
				'message' => "⚠ Partially delivered ({$success_count}/{$total_servers} servers succeeded" . ($delayed_count > 0 ? ", {$delayed_count} delayed" : "") . "). Copy stored in your inbox."
			];
		} else {
			$_SESSION['mail_feedback'] = [
				'success' => false,
				'error' => "Delivery failed to all destinations. Copy stored in your inbox. " . ($dispatch_results[0]['error'] ?? '')
			];
		}

		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=mail');
		exit;
	}

	// Clear Failed Login Attempts for a Session (or all on this domain)
	if ($action === 'clear_login_attempts') {
		$target_ip = trim($_POST['ip_address'] ?? '');
		$cur_dom = get_current_domain();
		$pdo = get_db();
		if ($pdo) {
			if ($target_ip !== '') {
				$stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND (domain = ? OR domain IS NULL)");
				$stmt->execute([$target_ip, $cur_dom]);
				$_SESSION['comms_feedback'] = ['success' => true, 'message' => "Cleared failed login attempts for session on domain '{$cur_dom}'."];
			} else {
				$stmt = $pdo->prepare("DELETE FROM login_attempts WHERE domain = ? OR domain IS NULL");
				$stmt->execute([$cur_dom]);
				$_SESSION['comms_feedback'] = ['success' => true, 'message' => "Cleared all failed login attempt records for domain '{$cur_dom}'."];
			}
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}
}

// Prepare Data for Rendering
$pdo = get_db();
$current_domain = get_current_domain();
$domain_user = get_single_domain_user($current_domain);
$active_tab = $_GET['tab'] ?? 'mail';
if (!in_array($active_tab, ['mail', 'inbox', 'sent', 'comms'], true)) {
	$active_tab = 'mail';
}
$canonical_url = get_canonical_site_url();
$comms_enabled = (get_setting('allow_incoming_communications', '1') === '1');
$default_destinations_array = function_exists('get_default_mail_destinations') ? get_default_mail_destinations($current_domain) : [['url' => 'https://tricurse.com/contact']];
$default_destinations_json = json_encode($default_destinations_array);
$primary_default_endpoint = !empty($default_destinations_array[0]['url']) ? $default_destinations_array[0]['url'] : (function_exists('get_main_contact_point_url') ? get_main_contact_point_url($current_domain) : 'https://tricurse.com/contact');

$incoming_messages = [];
$sent_messages = [];
$blocked_sites_list = [];
$login_attempts_list = [];
$unread_count = 0;

if (is_logged_in() && $pdo) {
	try {
		$incoming_messages = function_exists('get_inbox_messages_for_domain') ? get_inbox_messages_for_domain($pdo, $current_domain, 50) : [];
		$unread_count = 0;

		foreach ($incoming_messages as $m) {
			$is_sent = !empty($m['is_sent_copy']);
			if (empty($m['is_read']) && !$is_sent && empty($m['is_delayed'])) {
				$unread_count++;
			}
		}

		purge_expired_login_attempts();
		$sent_messages = $pdo->query("SELECT * FROM sent_messages ORDER BY id DESC LIMIT 50")->fetchAll();
		$blocked_sites_list = $pdo->query("SELECT * FROM blocked_sites ORDER BY id DESC")->fetchAll();
		$stmt_la = $pdo->prepare("
			SELECT 
				ip_address, 
				COUNT(*) AS attempt_count, 
				MAX(attempted_at) AS last_attempt,
				datetime(MAX(attempted_at), '+6 hours') AS unlock_at,
				MAX(0, " . LOGIN_LOCK_DURATION_SECONDS . " - (strftime('%s', 'now') - strftime('%s', MAX(attempted_at)))) AS seconds_remaining
			FROM login_attempts 
			WHERE domain = ? OR domain IS NULL
			GROUP BY ip_address 
			ORDER BY last_attempt DESC
		");
		$stmt_la->execute([$current_domain]);
		$login_attempts_list = $stmt_la->fetchAll();
	} catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, nosnippet">
<title><?php echo ($unread_count > 0 ? "({$unread_count}) " : ""); ?>addr.bar Desktop — Federated Contact &amp; Mail</title>
<link rel="manifest" href="index.php?action=manifest">
<meta name="theme-color" content="#0d0b0a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(preg_replace('/:[0-9]+$/', '', get_current_domain())); ?>">
<link rel="apple-touch-icon" href="icon-192.png">
<style>
	:root {
		/* Theme: The Thermionic Foundry & Broadcast Band (hotletter.band) */
		--furnace-core: #0d0b0a;         /* Deep obsidian background; the dark interior of a foundry */
		--chassis-iron: #1a1614;         /* Heavy radio console casing and directory banner bar */
		--crucible-charcoal: #28201b;    /* Cell row background representing cooling cast iron and graphite */
		--brass-stamped: #543d2b;        /* Aged stamped-brass borders and mechanical bevels */
		--filament-amber: #ffb347;       /* Primary text and dates; the warm glow of a vacuum-tube dial */
		--hot-letter-orange: #ff5c26;    /* Links, active frequencies, and incandescent hot-metal highlights */
		--on-air-crimson: #9c2415;       /* Section headers modeled after studio 'ON AIR' backlit indicators */
		--incandescent-white: #fff4e6;   /* Pure white-hot molten metal text highlights */

		/* Semantic compatibility mapping */
		--mac-white: #1a1614;
		--mac-black: #543d2b;
		--mac-gray-bg: #28201b;
		--mac-gray-border: #543d2b;
		--mac-gray: #ffb347;
	}

	/* Universal Single Font Size: 12pt */
	*, *::before, *::after,
	html, body, div, span, p, a, button, input, select, textarea,
	h1, h2, h3, h4, h5, h6, table, th, td, label, code, pre, ol, ul, li {
		font-size: 12pt !important;
		line-height: 1.45;
		box-sizing: border-box;
		font-family: 'Courier New', 'SF Mono', 'Consolas', 'Liberation Mono', monospace;
	}

	/* Mobile Auto-Resize Prevention */
	html, body {
		-webkit-text-size-adjust: 100% !important;
		-moz-text-size-adjust: 100% !important;
		text-size-adjust: 100% !important;
		touch-action: pan-x pan-y;
		overflow-x: hidden;
		width: 100%;
		max-width: 100%;
	}

	body { 
		margin: 0; 
		padding: 12px; 
		min-height: 100vh;
		background-color: var(--furnace-core);
		color: var(--filament-amber);
		background-image: 
			linear-gradient(rgba(84, 61, 43, 0.15) 1px, transparent 1px),
			linear-gradient(90deg, rgba(84, 61, 43, 0.15) 1px, transparent 1px);
		background-size: 24px 24px;
	}

	a {
		color: var(--hot-letter-orange);
		text-decoration: underline;
		font-weight: bold;
		transition: color 0.15s, background-color 0.15s;
	}
	a:hover {
		color: var(--incandescent-white);
		background-color: rgba(255, 92, 38, 0.2);
	}

	#directoryPath {
		color: var(--filament-amber);
		padding: 10px 14px;
		margin: 0 0 14px 0;
		font-weight: bold;
		background-color: var(--chassis-iron);
		border: 2px solid var(--brass-stamped);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.7), inset 0 1px 0 rgba(255, 179, 71, 0.15);
		display: flex;
		justify-content: space-between;
		align-items: center;
		flex-wrap: wrap;
		gap: 10px;
		border-radius: 2px;
	}

	.desktop-toolbar {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin: 0 0 14px 0;
		flex-wrap: wrap;
		gap: 10px;
	}

	/* Thermionic Console Buttons */
	.mac-btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 6px;
		font-family: inherit;
		font-size: 12pt !important;
		font-weight: bold;
		color: var(--filament-amber);
		background-color: var(--crucible-charcoal);
		border: 2px solid var(--brass-stamped);
		box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 179, 71, 0.12);
		padding: 8px 14px;
		min-height: 40px;
		cursor: pointer;
		text-decoration: none;
		user-select: none;
		-webkit-user-select: none;
		transition: background-color 0.15s, border-color 0.15s, color 0.15s, box-shadow 0.15s;
		outline: none;
		border-radius: 2px;
	}
	.mac-btn:hover {
		background-color: var(--hot-letter-orange);
		border-color: var(--filament-amber);
		color: var(--furnace-core) !important;
		box-shadow: 0 0 10px rgba(255, 92, 38, 0.5);
	}
	.mac-btn:hover * {
		color: var(--furnace-core) !important;
	}
	.mac-btn:active {
		transform: translate(1px, 1px);
		box-shadow: 0 1px 2px rgba(0, 0, 0, 0.8);
	}
	.mac-btn-default {
		background-color: #381a10;
		border: 2px solid var(--hot-letter-orange);
		color: var(--incandescent-white);
	}
	.mac-btn-default:hover {
		background-color: var(--hot-letter-orange);
		color: var(--furnace-core) !important;
	}
	.mac-btn-danger {
		background-color: #2b1111;
		border: 2px solid var(--on-air-crimson);
		color: var(--incandescent-white);
	}
	.mac-btn-danger:hover {
		background-color: var(--on-air-crimson);
		color: var(--incandescent-white) !important;
	}

	/* Heavy Cast-Iron Panels and Windows */
	.upload-panel {
		background-color: var(--chassis-iron);
		border: 2px solid var(--brass-stamped);
		box-shadow: 0 4px 16px rgba(0, 0, 0, 0.65), inset 0 1px 0 rgba(255, 92, 38, 0.15);
		margin: 0 0 16px 0;
		padding: 0;
		border-radius: 2px;
	}
	.upload-header {
		background: var(--on-air-crimson);
		padding: 8px 12px;
		border-bottom: 2px solid var(--brass-stamped);
		display: flex;
		align-items: center;
		justify-content: space-between;
		box-shadow: inset 0 1px 0 rgba(255, 244, 230, 0.2), 0 2px 6px rgba(0, 0, 0, 0.5);
	}
	.upload-header-title {
		background: transparent;
		color: var(--incandescent-white);
		font-weight: bold;
		letter-spacing: 1.5px;
		text-transform: uppercase;
		text-shadow: 0 0 6px rgba(255, 244, 230, 0.4);
	}
	.upload-close-box {
		width: 22px;
		height: 22px;
		border: 1px solid var(--brass-stamped);
		background-color: var(--crucible-charcoal);
		cursor: pointer;
		padding: 0;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		color: var(--filament-amber);
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
		border-radius: 2px;
	}
	.upload-close-box:hover {
		background-color: var(--hot-letter-orange);
		color: var(--furnace-core);
	}
	.upload-body {
		padding: 16px;
		background-color: var(--chassis-iron);
		color: var(--filament-amber);
	}

	/* Alerts and Notices */
	.mac-alert {
		border: 2px solid var(--brass-stamped);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
		background: var(--crucible-charcoal);
		color: var(--filament-amber);
		padding: 12px 16px;
		margin-bottom: 14px;
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		border-radius: 2px;
	}
	.mac-alert-success {
		border-left: 8px solid var(--filament-amber);
	}
	.mac-alert-error {
		border-left: 8px solid var(--on-air-crimson);
		background: #251212;
		color: var(--incandescent-white);
	}
	.mac-alert-error * {
		color: var(--incandescent-white);
	}
	.mac-alert-close {
		border: 1px solid currentColor;
		background: transparent;
		color: inherit;
		padding: 2px 8px;
		font-family: inherit;
		font-weight: bold;
		cursor: pointer;
		border-radius: 2px;
	}
	.mac-alert-close:hover {
		background: var(--hot-letter-orange);
		color: var(--furnace-core);
	}

	/* Forms and Inputs */
	.mac-form-group {
		margin-bottom: 14px;
	}
	.mac-label {
		display: block;
		font-weight: bold;
		margin-bottom: 6px;
		color: var(--filament-amber);
	}
	.mac-input, .mac-textarea {
		width: 100%;
		max-width: 100%;
		border: 2px solid var(--brass-stamped);
		background-color: var(--furnace-core);
		color: var(--filament-amber);
		padding: 8px 12px;
		font-family: inherit;
		box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.6);
		outline: none;
		box-sizing: border-box;
		border-radius: 2px;
		caret-color: var(--hot-letter-orange);
	}
	.mac-textarea {
		min-height: 100px;
		resize: vertical;
	}
	.mac-input:focus, .mac-textarea:focus {
		border-color: var(--hot-letter-orange);
		background-color: #140f0c;
		box-shadow: 0 0 8px rgba(255, 92, 38, 0.35), inset 0 2px 4px rgba(0, 0, 0, 0.6);
	}
	.mac-checkbox-label {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-weight: bold;
		cursor: pointer;
		user-select: none;
		color: var(--filament-amber);
	}
	.mac-checkbox-label input[type="checkbox"] {
		appearance: none;
		-webkit-appearance: none;
		width: 20px;
		height: 20px;
		border: 2px solid var(--brass-stamped);
		background: var(--furnace-core);
		cursor: pointer;
		position: relative;
		margin: 0;
		outline: none;
		border-radius: 2px;
	}
	.mac-checkbox-label input[type="checkbox"]:checked {
		background: var(--hot-letter-orange);
		border-color: var(--filament-amber);
	}
	.mac-checkbox-label input[type="checkbox"]:checked::after {
		content: "✓";
		color: var(--furnace-core);
		font-weight: bold;
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
	}

	/* Destination Row Layout */
	.destination-row {
		display: flex;
		gap: 10px;
		align-items: center;
		margin-bottom: 8px;
	}

	/* Public Gateway Grid */
	.public-gateway-grid {
		display: grid;
		grid-template-columns: 1fr 1.35fr;
		gap: 18px;
		margin-top: 12px;
	}

	/* Status Pills and Badges */
	.status-badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 4px 10px;
		font-weight: bold;
		border: 1px solid var(--brass-stamped);
		background-color: var(--crucible-charcoal);
		color: var(--filament-amber);
		border-radius: 2px;
	}
	.status-badge-active {
		background-color: var(--on-air-crimson);
		border-color: var(--hot-letter-orange);
		color: var(--incandescent-white);
		box-shadow: 0 0 6px rgba(156, 36, 21, 0.4);
	}
	.code-box {
		background: var(--furnace-core);
		color: var(--filament-amber);
		border: 1px solid var(--brass-stamped);
		padding: 10px 14px;
		font-family: monospace;
		word-break: break-all;
		overflow-wrap: anywhere;
		user-select: all;
		border-radius: 2px;
	}

	/* Navigation Tabs */
	.mac-tabs {
		display: flex;
		gap: 6px;
		margin-bottom: 16px;
		border-bottom: 2px solid var(--brass-stamped);
		padding-bottom: 6px;
		flex-wrap: wrap;
	}
	.mac-tab-link {
		padding: 8px 14px;
		border: 2px solid var(--brass-stamped);
		background-color: var(--crucible-charcoal);
		color: var(--filament-amber);
		font-weight: bold;
		text-decoration: none;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
		transition: all 0.15s;
		border-radius: 2px;
	}
	.mac-tab-link:hover {
		border-color: var(--hot-letter-orange);
		color: var(--hot-letter-orange);
		background-color: var(--chassis-iron);
	}
	.mac-tab-link.active {
		background-color: var(--on-air-crimson);
		border-color: var(--hot-letter-orange);
		color: var(--incandescent-white);
		box-shadow: 0 0 10px rgba(156, 36, 21, 0.5);
	}

	/* Table Layouts */
	.table-responsive {
		width: 100%;
		overflow-x: auto;
		-webkit-overflow-scrolling: touch;
		margin-top: 8px;
		margin-bottom: 8px;
	}
	.mac-table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 6px;
		border: 2px solid var(--brass-stamped);
		background-color: var(--crucible-charcoal);
	}
	.mac-table th {
		background-color: var(--chassis-iron);
		color: var(--filament-amber);
		padding: 10px 12px;
		text-align: left;
		border-bottom: 2px solid var(--brass-stamped);
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}
	.mac-table th span {
		background: transparent;
		border: none;
		padding: 0;
		color: var(--filament-amber);
	}
	.mac-table td {
		border: 1px solid var(--brass-stamped);
		padding: 10px 12px;
		color: var(--filament-amber);
		background-color: var(--crucible-charcoal);
	}
	.mac-table tr:nth-child(even) td {
		background-color: #211915;
	}

	/* Modals */
	#blueprintModal, #editDefaultsModal {
		display: none;
		position: fixed;
		top: 0; left: 0; right: 0; bottom: 0;
		background: rgba(13, 11, 10, 0.85);
		z-index: 9999;
		overflow-y: auto;
		padding: 16px;
		-webkit-backdrop-filter: blur(4px);
		backdrop-filter: blur(4px);
	}
	.modal-dialog {
		background: var(--chassis-iron);
		border: 3px solid var(--brass-stamped);
		box-shadow: 0 8px 30px rgba(0, 0, 0, 0.85);
		max-width: 900px;
		margin: 20px auto;
		padding: 0;
		color: var(--filament-amber);
		border-radius: 2px;
	}

	/* Responsive Mobile Optimization */
	@media (max-width: 800px) {
		body {
			padding: 8px;
		}
		.public-gateway-grid {
			grid-template-columns: 1fr;
			gap: 14px;
		}
		#directoryPath {
			flex-direction: column;
			align-items: stretch;
			gap: 8px;
		}
		#directoryPath > div:last-child {
			justify-content: flex-start;
		}
		.destination-row {
			flex-direction: column;
			align-items: stretch;
		}
		.mac-tabs {
			flex-direction: column;
			gap: 4px;
		}
		.mac-tab-link {
			width: 100%;
			text-align: center;
		}
		.upload-panel {
			margin-bottom: 12px;
		}
		.upload-body {
			padding: 12px;
		}
	}
</style>
<script>
function $(e){ return document.getElementById(e); }

function copyToClipboard(text, btnElement) {
	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(text).then(function() {
			var orig = btnElement.innerText;
			btnElement.innerText = "✓ Copied!";
			setTimeout(function(){ btnElement.innerText = orig; }, 2000);
		});
	} else {
		var ta = document.createElement("textarea");
		ta.value = text;
		document.body.appendChild(ta);
		ta.select();
		document.execCommand("copy");
		document.body.removeChild(ta);
		var orig = btnElement.innerText;
		btnElement.innerText = "✓ Copied!";
		setTimeout(function(){ btnElement.innerText = orig; }, 2000);
	}
}

function toggleBlueprintModal(show) {
	var modal = $('blueprintModal');
	if (!modal) return;
	modal.style.display = show ? 'block' : 'none';
}

function toggleEditDefaultsModal(show) {
	var modal = $('editDefaultsModal');
	if (!modal) return;
	modal.style.display = show ? 'block' : 'none';
}

function escapeHtml(text){
	if (!text) return '';
	var div = document.createElement('div');
	div.innerText = text;
	return div.innerHTML;
}

// --------------------------------------------------------------
// Dynamic Destination URLs Management (Single User Per Domain)
// --------------------------------------------------------------
function formatAddressDisplay(url) {
	if (!url) return '';
	return url.replace(/^https?:\/\//i, '')
	          .replace(/\/index\.php$/i, '')
	          .replace(/\/+$/, '');
}

var defaultDestinations = <?php echo json_encode($default_destinations_array); ?>;
var defaultEndpointUrl = <?php echo json_encode(function_exists('format_address_display') ? format_address_display($primary_default_endpoint) : $primary_default_endpoint); ?>;

function addDestinationRow(url) {
	url = formatAddressDisplay(url || '');
	var container = $('destinationsContainer');
	if (!container) return;

	var row = document.createElement('div');
	row.className = 'destination-row';

	row.innerHTML = 
		'<div style="flex:1;">' +
			'<input type="text" name="destination_urls[]" class="mac-input dest-url-input" maxlength="255" placeholder="' + escapeHtml(defaultEndpointUrl) + '" value="' + escapeHtml(url) + '" required autocomplete="off">' +
		'</div>' +
		'<div>' +
			'<button type="button" class="mac-btn dest-remove-btn" onclick="removeDestinationRow(this)" title="Close / remove this destination row">[ ✕ Remove ]</button>' +
		'</div>';

	container.appendChild(row);
}

function removeDestinationRow(btn) {
	var row = btn.closest('.destination-row');
	var container = $('destinationsContainer');
	if (container && row) {
		var allRows = container.querySelectorAll('.destination-row');
		if (allRows.length > 1) {
			row.remove();
		} else {
			// Clear values if it's the only row
			var inputs = row.querySelectorAll('input');
			inputs.forEach(function(inp){ inp.value = ''; });
		}
	}
}

function loadDefaultDestinations() {
	var container = $('destinationsContainer');
	if (!container) return;
	container.innerHTML = '';
	if (Array.isArray(defaultDestinations) && defaultDestinations.length > 0) {
		defaultDestinations.forEach(function(item) {
			var u = (typeof item === 'string') ? item : (item.url || '');
			addDestinationRow(formatAddressDisplay(u));
		});
	} else {
		addDestinationRow('');
		alert('No default destinations saved yet. Click "[ ⚙ Edit \'To\' Defaults ]" to configure your distribution list.');
	}
}

// Dynamic Defaults Editor Rows
function addDefaultRow(url) {
	url = formatAddressDisplay(url || '');
	var container = $('defaultsEditorContainer');
	if (!container) return;

	var row = document.createElement('div');
	row.className = 'destination-row';
	row.innerHTML = 
		'<div style="flex:1;">' +
			'<input type="text" name="default_urls[]" class="mac-input" maxlength="255" placeholder="' + escapeHtml(defaultEndpointUrl) + '" value="' + escapeHtml(url) + '" required>' +
		'</div>' +
		'<div>' +
			'<button type="button" class="mac-btn" onclick="this.closest(\'.destination-row\').remove()">[ ✕ ]</button>' +
		'</div>';
	container.appendChild(row);
}

function urlB64ToUint8Array(base64String) {
	var padding = '='.repeat((4 - base64String.length % 4) % 4);
	var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
	var rawData = window.atob(base64);
	var outputArray = new Uint8Array(rawData.length);
	for (var i = 0; i < rawData.length; ++i) {
		outputArray[i] = rawData.charCodeAt(i);
	}
	return outputArray;
}

var deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
	e.preventDefault();
	deferredInstallPrompt = e;
	var b1 = $('pwaInstallBtn'); if (b1) b1.style.display = 'inline-flex';
	var b2 = $('pwaInstallBtnPublic'); if (b2) b2.style.display = 'inline-flex';
	var b3 = $('pwaInstallBtnSettings'); if (b3) b3.style.display = 'inline-flex';
});

function installPWA() {
	if (deferredInstallPrompt) {
		deferredInstallPrompt.prompt();
		deferredInstallPrompt.userChoice.then(function(choiceResult) {
			if (choiceResult.outcome === 'accepted') {
				var b1 = $('pwaInstallBtn'); if (b1) b1.style.display = 'none';
				var b2 = $('pwaInstallBtnPublic'); if (b2) b2.style.display = 'none';
				var b3 = $('pwaInstallBtnSettings'); if (b3) b3.style.display = 'none';
			}
			deferredInstallPrompt = null;
		});
	} else {
		alert("To install addr.bar Desktop app:\n\n• On Desktop (Chrome/Edge): Click the Install icon in the browser address bar.\n• On iOS (Safari): Tap the Share button, then tap 'Add to Home Screen'.\n• On Android: Tap Menu (⋮) and tap 'Install app' or 'Add to Home screen'.");
	}
}

var isClientLoggedIn = <?php echo is_logged_in() ? 'true' : 'false'; ?>;
var swRegistration = null;

function isInstalledApp() {
	return window.matchMedia('(display-mode: standalone)').matches ||
	       window.matchMedia('(display-mode: fullscreen)').matches ||
	       window.matchMedia('(display-mode: minimal-ui)').matches ||
	       window.navigator.standalone === true ||
	       document.referrer.includes('android-app://');
}

if ('serviceWorker' in navigator && 'PushManager' in window) {
	window.addEventListener('load', function() {
		navigator.serviceWorker.register('sw.js')
			.then(function(reg) {
				swRegistration = reg;
				checkPwaFirstLaunchAndPush();
			})
			.catch(function(err) {
				console.warn('Service Worker registration error:', err);
			});
	});
}

function checkPwaFirstLaunchAndPush() {
	if (!swRegistration) return;

	var isStandalone = isInstalledApp();
	var PWA_FIRST_KEY = 'pwa_app_opened_first_time';
	var isFirstPwaLaunch = isStandalone && !localStorage.getItem(PWA_FIRST_KEY);

	if (isFirstPwaLaunch) {
		// App opened for the first time in standalone mode:
		// Mark it in localStorage
		localStorage.setItem(PWA_FIRST_KEY, new Date().toISOString());

		// Deactivate any existing push subscription for this container
		swRegistration.pushManager.getSubscription().then(function(sub) {
			if (sub) {
				sub.unsubscribe().then(function() {
					fetch('index.php', {
						method: 'POST',
						headers: { 
							'Content-Type': 'application/json',
							'X-Installed-App': '1'
						},
						body: JSON.stringify({ 
							action: 'remove_push_subscription', 
							endpoint: sub.endpoint,
							is_standalone: 1
						})
					}).catch(function(e) { console.warn('Push removal error:', e); });
				}).finally(function() {
					// Display first-launch reactivation banner
					showPwaFirstLaunchBanner();
					updatePushUI();
				});
			} else {
				showPwaFirstLaunchBanner();
				updatePushUI();
			}
		}).catch(function() {
			showPwaFirstLaunchBanner();
			updatePushUI();
		});
	} else {
		updatePushUI();
	}
}

function showPwaFirstLaunchBanner() {
	var banner = $('pwaFirstLaunchPushBanner');
	if (!banner) return;
	banner.style.display = 'flex';

	var descEl = $('pwaFirstLaunchPushDesc');
	var actionBtn = $('pwaFirstLaunchPushActionBtn');

	if (isInstalledApp() && !isClientLoggedIn) {
		if (descEl) {
			descEl.innerHTML = 'Push notifications were deactivated for this newly installed standalone app container. <strong>When installed as an app, the visitor must be logged in before push notifications can be activated.</strong> Please sign in to your host account to activate push notifications.';
		}
		if (actionBtn) {
			actionBtn.innerText = '[ 🔐 Sign In to Activate Push ]';
			actionBtn.onclick = function() {
				var pwInput = $('loginPassword');
				if (pwInput) {
					pwInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
					pwInput.focus();
				}
			};
		}
	} else {
		if (descEl) {
			descEl.innerHTML = 'Push notifications were deactivated for this newly installed standalone app container. Please reactivate push notifications to ensure you receive desktop and system message alerts.';
		}
		if (actionBtn) {
			actionBtn.innerText = '[ 🔔 Reactivate Push Notifications ]';
			actionBtn.onclick = function() {
				enablePushDirectly();
			};
		}
	}
}

function dismissPwaFirstLaunchPrompt() {
	var banner = $('pwaFirstLaunchPushBanner');
	if (banner) {
		banner.style.display = 'none';
	}
}

function dismissPushSignInPrompt() {
	sessionStorage.setItem('push_prompt_dismissed', '1');
	var banner = $('pushSignInPromptBanner');
	if (banner) {
		banner.style.display = 'none';
	}
}

function updatePushUI() {
	if (!swRegistration) return;
	swRegistration.pushManager.getSubscription()
		.then(function(subscription) {
			var isSubscribed = !(subscription === null);
			var btn = $('pushNotifyBtn');
			var statusBadge = $('pushStatusBadge');
			var btnSettings = $('pushToggleBtnSettings');

			if (btn) {
				if (isInstalledApp() && !isClientLoggedIn) {
					btn.innerText = '[ 🔔 Push: Login Required ]';
					btn.title = 'When installed as an app, the visitor must be logged in before push notifications can be activated';
				} else {
					btn.innerText = isSubscribed ? '[ 🔔 Push: Active ]' : '[ 🔔 Enable Push ]';
					btn.title = isSubscribed ? 'Push notifications active' : 'Enable push notifications';
				}
			}
			if (statusBadge) {
				statusBadge.className = 'status-badge ' + (isSubscribed ? 'status-badge-active' : '');
				statusBadge.innerText = isSubscribed ? '● SUBSCRIBED IN THIS BROWSER' : '○ NOT SUBSCRIBED IN THIS BROWSER';
			}
			if (btnSettings) {
				if (isInstalledApp() && !isClientLoggedIn) {
					btnSettings.innerText = '[ 🔒 Sign In to Enable Push ]';
				} else {
					btnSettings.innerText = isSubscribed ? '[ 🔕 Unsubscribe This Device ]' : '[ 🔔 Subscribe This Device ]';
				}
			}

			// Prompt client on sign-in if push notifications are not enabled
			var signInBanner = $('pushSignInPromptBanner');
			var pwaBanner = $('pwaFirstLaunchPushBanner');
			var pwaBannerVisible = pwaBanner && pwaBanner.style.display !== 'none';

			if (signInBanner) {
				if (isClientLoggedIn && !isSubscribed && !pwaBannerVisible && !sessionStorage.getItem('push_prompt_dismissed')) {
					signInBanner.style.display = 'flex';
				} else {
					signInBanner.style.display = 'none';
				}
			}

			if (isSubscribed && pwaBanner) {
				pwaBanner.style.display = 'none';
			}
		});
}

function enablePushDirectly() {
	if (isInstalledApp() && !isClientLoggedIn) {
		alert('Sign-In Required: When installed as an app, the visitor must be logged in before push notifications can be activated.\n\nPlease enter your host password to log in first.');
		var pwInput = $('loginPassword');
		if (pwInput) {
			pwInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
			pwInput.focus();
		}
		return;
	}

	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		alert('Web Push is not supported in this browser or over insecure HTTP. Ensure you are accessing via HTTPS or localhost.');
		return;
	}
	if (Notification.permission === 'denied') {
		alert('Notifications are currently blocked in your browser permissions.\n\nPlease enable notifications for this site in your browser site settings and reload.');
		return;
	}

	if (!swRegistration) {
		navigator.serviceWorker.ready.then(function(reg) {
			swRegistration = reg;
			performPushSubscribe();
		}).catch(function(err) {
			alert('Could not initialize Service Worker: ' + err.message);
		});
	} else {
		performPushSubscribe();
	}
}

function performPushSubscribe() {
	if (isInstalledApp() && !isClientLoggedIn) {
		alert('Sign-In Required: When installed as an app, the visitor must be logged in before push notifications can be activated.\n\nPlease log in first.');
		var pwInput = $('loginPassword');
		if (pwInput) {
			pwInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
			pwInput.focus();
		}
		return;
	}

	Notification.requestPermission().then(function(permission) {
		if (permission !== 'granted') {
			alert('Notification permission was not granted. Please allow notification permissions when prompted.');
			return;
		}

		fetch('index.php?action=get_vapid_public_key')
			.then(function(res) { return res.json(); })
			.then(function(data) {
				if (!data.success || !data.publicKey) throw new Error(data.error || 'Failed to fetch public key');
				var appKey = urlB64ToUint8Array(data.publicKey);
				return swRegistration.pushManager.subscribe({
					userVisibleOnly: true,
					applicationServerKey: appKey
				});
			})
			.then(function(newSub) {
				var rawKey = newSub.getKey('p256dh');
				var rawAuth = newSub.getKey('auth');
				var p256dh = rawKey ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawKey))) : '';
				var auth = rawAuth ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuth))) : '';

				return fetch('index.php', {
					method: 'POST',
					headers: { 
						'Content-Type': 'application/json',
						'X-Installed-App': isInstalledApp() ? '1' : '0'
					},
					body: JSON.stringify({
						action: 'save_push_subscription',
						endpoint: newSub.endpoint,
						p256dh: p256dh,
						auth: auth,
						is_standalone: isInstalledApp() ? 1 : 0
					})
				});
			})
			.then(function(res) { return res.json(); })
			.then(function(data) {
				if (!data.success) {
					throw new Error(data.error || 'Failed to save push subscription');
				}
				var b1 = $('pushSignInPromptBanner'); if (b1) b1.style.display = 'none';
				var b2 = $('pwaFirstLaunchPushBanner'); if (b2) b2.style.display = 'none';
				updatePushUI();
				alert('✓ Success! Push notifications are now enabled on this device.');
			})
			.catch(function(err) {
				console.error('Push subscription failed:', err);
				alert('Could not enable push notifications: ' + err.message);
			});
	});
}

function togglePushSubscription() {
	if (!swRegistration) {
		alert('Web Push is not supported in this browser or over insecure HTTP. Ensure you are accessing via HTTPS or localhost.');
		return;
	}
	swRegistration.pushManager.getSubscription()
		.then(function(sub) {
			if (sub) {
				// Unsubscribe
				sub.unsubscribe().then(function() {
					fetch('index.php', {
						method: 'POST',
						headers: { 
							'Content-Type': 'application/json',
							'X-Installed-App': isInstalledApp() ? '1' : '0'
						},
						body: JSON.stringify({ 
							action: 'remove_push_subscription', 
							endpoint: sub.endpoint,
							is_standalone: isInstalledApp() ? 1 : 0
						})
					}).then(function() {
						updatePushUI();
						alert('Push notifications disabled for this device.');
					});
				});
			} else {
				// Subscribe
				enablePushDirectly();
			}
		});
}

function sendTestPushNotification() {
	fetch('index.php?action=test_push_notification')
		.then(function(res) { return res.json(); })
		.then(function(data) {
			if (data.success && data.sent) {
				alert('✓ Test notification dispatched! Check your desktop/phone notifications.');
			} else {
				alert('Notice: Push dispatched, but no subscribed devices responded. Make sure notifications are allowed and subscribed.');
			}
		})
		.catch(function(err) {
			alert('Error sending test push: ' + err.message);
		});
}

window.addEventListener('DOMContentLoaded', function(){
	// Initialize Mail Form with reply parameters or saved default destinations
	var container = $('destinationsContainer');
	if (container && container.children.length === 0) {
		var urlParams = new URLSearchParams(window.location.search);
		var replyUrl = urlParams.get('reply_url');
		var replySubject = urlParams.get('reply_subject');
		if (replyUrl) {
			addDestinationRow(replyUrl);
			if (replySubject && $('subjectInput')) {
				$('subjectInput').value = replySubject;
			}
			if ($('messageInput')) {
				$('messageInput').focus();
			}
		} else if (Array.isArray(defaultDestinations) && defaultDestinations.length > 0) {
			defaultDestinations.forEach(function(item) {
				var u = (typeof item === 'string') ? item : (item.url || '');
				addDestinationRow(formatAddressDisplay(u));
			});
		} else {
			addDestinationRow(formatAddressDisplay(defaultEndpointUrl));
		}
	}
});
</script>
</head>
<body>

<?php
// Display Feedback Alerts
if (!empty($_SESSION['auth_feedback'])) {
	$fb = $_SESSION['auth_feedback'];
	unset($_SESSION['auth_feedback']);
	$cls = !empty($fb['success']) ? 'mac-alert-success' : 'mac-alert-error';
	echo "<div class='mac-alert {$cls}'>";
	echo "<div>" . (!empty($fb['success']) ? '✓ ' : '⚠ ') . htmlspecialchars($fb['message'] ?? $fb['error'] ?? '') . "</div>";
	echo "<button type='button' class='mac-alert-close' onclick='this.parentElement.remove()'>[ OK ]</button>";
	echo "</div>";
}

if (!empty($_SESSION['comms_feedback'])) {
	$cfb = $_SESSION['comms_feedback'];
	unset($_SESSION['comms_feedback']);
	$cls = !empty($cfb['success']) ? 'mac-alert-success' : 'mac-alert-error';
	echo "<div class='mac-alert {$cls}'>";
	echo "<div>" . (!empty($cfb['success']) ? '✓ ' : '⚠ ') . htmlspecialchars($cfb['message'] ?? $cfb['error'] ?? '') . "</div>";
	echo "<button type='button' class='mac-alert-close' onclick='this.parentElement.remove()'>[ OK ]</button>";
	echo "</div>";
}

if (!empty($_SESSION['mail_feedback'])) {
	$mfb = $_SESSION['mail_feedback'];
	unset($_SESSION['mail_feedback']);
	$cls = !empty($mfb['success']) ? 'mac-alert-success' : 'mac-alert-error';
	echo "<div class='mac-alert {$cls}'>";
	echo "<div>" . (!empty($mfb['success']) ? '✓ ' : '⚠ ') . htmlspecialchars($mfb['message'] ?? $mfb['error'] ?? '') . "</div>";
	echo "<button type='button' class='mac-alert-close' onclick='this.parentElement.remove()'>[ OK ]</button>";
	echo "</div>";
}
?>

<!-- PWA Standalone First-Launch Push Deactivation & Reactivation Banner -->
<?php $is_auth_banner = is_logged_in(); ?>
<div id="pwaFirstLaunchPushBanner" style="display:none; border:2px solid var(--brass-stamped); background:var(--crucible-charcoal); color:var(--filament-amber); padding:12px 16px; margin-bottom:14px; box-shadow:3px 3px 0px #000; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
	<div style="display:flex; align-items:center; gap:12px;">
		<span style="line-height:1;">📱</span>
		<div>
			<div style="font-weight:bold;">Welcome to the Installed Application!</div>
			<div id="pwaFirstLaunchPushDesc" style="margin-top:2px;">
				<?php if (!$is_auth_banner): ?>
					Push notifications were deactivated for this newly installed standalone app container. <strong>When installed as an app, the visitor must be logged in before push notifications can be activated.</strong> Please sign in to your host account to activate push notifications.
				<?php else: ?>
					Push notifications were deactivated for this newly installed standalone app container. Please reactivate push notifications to ensure you receive desktop and system message alerts.
				<?php endif; ?>
			</div>
		</div>
	</div>
	<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
		<button type="button" id="pwaFirstLaunchPushActionBtn" class="mac-btn mac-btn-default" style="font-weight:bold;" onclick="<?php echo $is_auth_banner ? 'enablePushDirectly()' : 'var el=$(\'loginPassword\'); if(el){el.scrollIntoView({behavior:\'smooth\',block:\'center\'});el.focus();}'; ?>">
			<?php echo $is_auth_banner ? '[ 🔔 Reactivate Push Notifications ]' : '[ 🔐 Sign In to Activate Push ]'; ?>
		</button>
		<button type="button" class="mac-btn" onclick="dismissPwaFirstLaunchPrompt()">[ Dismiss ]</button>
	</div>
</div>

<?php if (!is_logged_in()): ?>
<!-- ============================================================== -->
<!-- PUBLIC VISITOR SCREEN (NOT LOGGED IN)                          -->
<!-- Login Form + Inter-Site Contact Info + Blueprint Link          -->
<!-- ============================================================== -->
<div id="directoryPath">
	<span>addr.bar System Desktop: Federated Contact &amp; Mail Gateway (<?php echo htmlspecialchars($current_domain); ?>)</span>
	<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
		<button type="button" id="pwaInstallBtnPublic" class="mac-btn" style="display:none; padding:3px 8px;" onclick="installPWA()">[ 📲 Install App ]</button>
		<span class="status-badge <?php echo $comms_enabled ? 'status-badge-active' : ''; ?>">
			<?php echo $comms_enabled ? '● COMMUNICATIONS ACTIVE' : '○ COMMUNICATIONS DISABLED'; ?>
		</span>
	</div>
</div>

<div class="public-gateway-grid">
	<!-- Left Window: Host Login -->
	<div class="upload-panel">
		<div class="upload-header">
			<span class="upload-header-title">SYSTEM LOGIN (<?php echo htmlspecialchars($current_domain); ?>)</span>
			<span style="font-weight:bold;">[ 1 User / Domain ]</span>
		</div>
		<div class="upload-body">
			<?php
				$domain_user = get_single_domain_user($current_domain);
				$visitor_ip = get_client_ip();
				$visitor_lock_info = get_login_lock_status($visitor_ip, $current_domain);
				$visitor_attempts = $visitor_lock_info['attempts'];
				$visitor_remaining = $visitor_lock_info['remaining'];
				$visitor_locked = $visitor_lock_info['locked'];
				$visitor_seconds_left = $visitor_lock_info['seconds_remaining'];
				$visitor_unlock_at = $visitor_lock_info['unlock_at'];
			?>
			<p style="margin-top:0;">
				Mail dispatch and communication controls are restricted to the single user for domain <strong><?php echo htmlspecialchars($current_domain); ?></strong><?php echo !empty($domain_user['username']) ? ' (<strong>' . htmlspecialchars($domain_user['username']) . '</strong>)' : ''; ?>. Enter your password to log in.
			</p>
			<?php if ($visitor_locked): ?>
				<div id="loginLockBanner" style="border:2px solid var(--on-air-crimson); background:var(--chassis-iron); color:var(--filament-amber); padding:10px; margin-bottom:12px; font-weight:bold;">
					<div style="color:var(--hot-letter-orange);">⚠ Maximum of <?php echo MAX_LOGIN_ATTEMPTS_PER_IP; ?> login attempts reached for this session.</div>
					<div style="margin-top:6px;">
						⏱ 6-Hour Lock Timer: <span id="lockCountdownTimer" data-seconds="<?php echo (int)$visitor_seconds_left; ?>" style="text-decoration:underline;"><?php echo htmlspecialchars(format_lock_timer($visitor_seconds_left)); ?></span> remaining
						<?php if ($visitor_unlock_at): ?>
							<span style="font-weight:normal;">(Unlocks at <?php echo htmlspecialchars($visitor_unlock_at); ?>)</span>
						<?php endif; ?>
					</div>
				</div>
				<script>
				(function(){
					var timerEl = document.getElementById('lockCountdownTimer');
					if (!timerEl) return;
					var sec = parseInt(timerEl.getAttribute('data-seconds'), 10) || 0;
					function fmt(s) {
						var h = Math.floor(s / 3600);
						var m = Math.floor((s % 3600) / 60);
						var r = s % 60;
						return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
					}
					var iv = setInterval(function(){
						sec--;
						if (sec <= 0) {
							clearInterval(iv);
							var banner = document.getElementById('loginLockBanner');
							if (banner) {
								banner.style.backgroundColor = 'var(--crucible-charcoal)';
								banner.style.color = 'var(--filament-amber)';
								banner.innerHTML = '✓ 6-hour login lock timer expired. You may now log in again.';
							}
							var pwd = document.getElementById('loginPassword');
							if (pwd) {
								pwd.disabled = false;
								pwd.placeholder = 'Enter password';
								pwd.focus();
							}
							var btn = document.getElementById('loginSubmitBtn');
							if (btn) {
								btn.disabled = false;
								btn.style.opacity = '1';
								btn.style.cursor = 'pointer';
							}
						} else {
							timerEl.textContent = fmt(sec);
						}
					}, 1000);
				})();
				</script>
			<?php elseif ($visitor_attempts > 0): ?>
				<div style="border:2px solid var(--brass-stamped); background:var(--crucible-charcoal); color:var(--filament-amber); padding:8px 10px; margin-bottom:12px; font-weight:bold;">
					Notice: <?php echo $visitor_remaining; ?> of <?php echo MAX_LOGIN_ATTEMPTS_PER_IP; ?> login attempt(s) remaining for this session (6-hour lock on 3 failed attempts).
				</div>
			<?php endif; ?>
			<form action="index.php" method="POST">
				<input type="hidden" name="action" value="login">
				<input type="hidden" name="username" value="<?php echo htmlspecialchars($domain_user['username'] ?? 'admin'); ?>">
				<div class="mac-form-group">
					<label for="loginPassword" class="mac-label">Host Password: *</label>
					<input type="password" id="loginPassword" name="password" class="mac-input" maxlength="255" placeholder="<?php echo $visitor_locked ? 'Locked for 6 hours (3 attempts exceeded)' : 'Enter password'; ?>" required autocomplete="current-password" <?php echo $visitor_locked ? 'disabled' : 'autofocus'; ?>>
				</div>
				<div style="margin-top:16px;">
					<button type="submit" id="loginSubmitBtn" class="mac-btn mac-btn-default" <?php echo $visitor_locked ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
						[ 🔑 Log In to Desktop ]
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Right Window: Contact This Website & Federation Info -->
	<div class="upload-panel">
		<div class="upload-header">
			<span class="upload-header-title">CONTACT THIS WEBSITE</span>
			<button type="button" class="upload-close-box" onclick="toggleBlueprintModal(true)" title="View Protocol Blueprint"></button>
		</div>
		<div class="upload-body">
			<div style="margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
				<span style="font-weight:bold;">Host Correspondence Channel:</span>
				<?php if ($comms_enabled): ?>
					<span class="status-badge status-badge-active">🟢 ACCEPTING INCOMING MESSAGES</span>
				<?php else: ?>
					<span class="status-badge" style="background:var(--on-air-crimson); color:var(--incandescent-white);">🔴 COMMUNICATIONS DISABLED BY HOST</span>
				<?php endif; ?>
			</div>

			<p style="line-height:1.5;">
				This website operates the sovereign <strong>addr.bar Federated Messaging Protocol</strong>. Visitors and external server hosts can send a direct, authenticated message to this website provided they also operate a website running this same form.
			</p>

			<div style="border:2px solid var(--brass-stamped); padding:10px; margin-bottom:12px; background:var(--crucible-charcoal);">
				<div style="font-weight:bold; margin-bottom:4px; color:var(--filament-amber);">How to Send a Message to this Website:</div>
				<ol style="margin:4px 0 8px 18px; padding:0; line-height:1.5;">
					<li>Visit your <strong>own website</strong> that operates this script.</li>
					<li>Log in to your website and open the <strong>Mail Form</strong>.</li>
					<li>Enter this website's canonical address into the <em>To</em> field:</li>
				</ol>
				<div class="code-box" id="contactEndpointBox"><?php echo htmlspecialchars(function_exists('format_address_display') ? format_address_display($canonical_url) : $canonical_url); ?></div>
				<div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" class="mac-btn" onclick="copyToClipboard('<?php echo addslashes(function_exists('format_address_display') ? format_address_display($canonical_url) : $canonical_url); ?>', this)">
						[ 📋 Copy 'To' Address ]
					</button>
					<button type="button" class="mac-btn" onclick="toggleBlueprintModal(true)">
						[ 📜 View Protocol Blueprint ]
					</button>
				</div>
			</div>

			<div style="border:2px solid var(--brass-stamped); padding:10px; margin-bottom:12px; background:var(--crucible-charcoal);">
				<div style="font-weight:bold; margin-bottom:4px; color:var(--filament-amber);">Install on Your Website via GitHub:</div>
				<p style="line-height:1.4; margin:4px 0 8px 0;">
					If you operate a website, you can install the federated correspondence system directly from GitHub using the command line:
				</p>
				<div class="code-box" id="gitInstallCommandBox">git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh</div>
				<div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" class="mac-btn mac-btn-default" onclick="copyToClipboard('git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh', this)">
						[ 📋 Copy Git Install Command ]
					</button>
					<a href="https://github.com/addressbar-net/contact" target="_blank" rel="noopener noreferrer" class="mac-btn">
						[ 🌐 View Repository on GitHub ]
					</a>
				</div>
			</div>

			<div style="line-height:1.4; border-top:1px solid var(--brass-stamped); padding-top:8px;">
				<strong>🔒 Direct Authenticated Transmission:</strong> When a message is dispatched, websites communicate directly and securely server-to-server via standard web protocols.
			</div>
		</div>
	</div>
</div>

<?php else: ?>
<!-- ============================================================== -->
<!-- AUTHENTICATED HOST SCREEN (LOGGED IN)                          -->
<!-- Mail Center + Inbox + Sent Outbox + Communication Controls     -->
<!-- ============================================================== -->
<?php
	$host_domain = get_current_domain();
	$domain_user = get_single_domain_user($host_domain);

	echo "<div id='directoryPath'>";
	echo "  <div>addr.bar Federated Correspondence Desktop: <strong>" . htmlspecialchars($host_domain) . "</strong></div>";
	echo "  <div style='display:flex; align-items:center; gap:8px; flex-wrap:wrap;'>";
	if ($unread_count > 0) {
		echo "    <a href='index.php?tab=inbox' class='status-badge status-badge-active' style='text-decoration:none;'>📥 {$unread_count} Unread</a>";
	}
	echo "    <button type='button' id='pwaInstallBtn' class='mac-btn' style='display:none; padding:3px 8px;' onclick='installPWA()'>[ 📲 Install App ]</button>";
	echo "    <button type='button' id='pushNotifyBtn' class='mac-btn' style='padding:3px 8px;' onclick='togglePushSubscription()'>[ 🔔 Push Notifications ]</button>";
	echo "    <span>[ 👤 Host: <strong>" . htmlspecialchars(get_logged_in_username()) . "</strong> ]</span>";
	echo "    <a href='index.php?action=logout' class='mac-btn' style='padding:3px 8px;'>[ 🚪 Log Out ]</a>";
	echo "  </div>";
	echo "</div>";
?>

<!-- Client Sign-In Push Notification Prompt Banner -->
<div id="pushSignInPromptBanner" style="display:none; border:2px solid var(--brass-stamped); background:var(--crucible-charcoal); color:var(--filament-amber); padding:12px 16px; margin-bottom:14px; box-shadow:3px 3px 0px #000; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
	<div style="display:flex; align-items:center; gap:12px;">
		<span style="line-height:1;">🔔</span>
		<div>
			<div style="font-weight:bold;">Push Notifications are not enabled for this device.</div>
			<div style="margin-top:2px;">Enable push notifications to receive instant OS-level alerts whenever new federated messages arrive on <?php echo htmlspecialchars($host_domain); ?>.</div>
		</div>
	</div>
	<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
		<button type="button" class="mac-btn mac-btn-default" style="font-weight:bold;" onclick="enablePushDirectly()">[ 🔔 Enable Push Notifications ]</button>
		<button type="button" class="mac-btn" onclick="dismissPushSignInPrompt()">[ Remind Me Later ]</button>
	</div>
</div>

<?php
	// Desktop Navigation Tabs (No Directory Listing)
	echo "<div class='mac-tabs'>";
	$tab_class_mail  = ($active_tab === 'mail') ? 'active' : '';
	$tab_class_inbox = ($active_tab === 'inbox') ? 'active' : '';
	$tab_class_comms = ($active_tab === 'comms') ? 'active' : '';
	$tab_class_sent  = ($active_tab === 'sent') ? 'active' : '';

	echo "<a href='index.php?tab=mail' class='mac-tab-link {$tab_class_mail}'>✉ Send Mail Form</a>";
	echo "<a href='index.php?tab=inbox' class='mac-tab-link {$tab_class_inbox}'>📥 Inbox " . ($unread_count > 0 ? "({$unread_count} Unread)" : "(0)") . "</a>";
	echo "<a href='index.php?tab=sent' class='mac-tab-link {$tab_class_sent}'>📤 Sent Outbox</a>";
	echo "<a href='index.php?tab=comms' class='mac-tab-link {$tab_class_comms}'>⚙ Communication Controls</a>";
	echo "<button type='button' class='mac-tab-link' onclick='toggleBlueprintModal(true)' style='cursor:pointer;'>📜 Protocol Blueprint</button>";
	echo "</div>";

	// ----------------------------------------------------------
	// TAB: SEND MAIL FORM
	// ----------------------------------------------------------
	if ($active_tab === 'mail') {
		echo "<div class='upload-panel'>";
		echo "  <div class='upload-header'>";
		echo "    <span class='upload-header-title'>SEND FEDERATED MAIL TO PEER WEBSITES</span>";
		echo "  </div>";
		echo "  <div class='upload-body'>";
		echo "    <p style='margin-top:0;'>";
		echo "      Transmit an authenticated message To peer websites (such as <code>" . htmlspecialchars(format_address_display($primary_default_endpoint)) . "</code>). Sent copies are preserved in your inbox.";
		echo "    </p>";
		echo "    <form action='index.php' method='POST'>";
		echo "      <input type='hidden' name='action' value='send_mail'>";

		// Destination URLs Section
		echo "      <div class='mac-form-group'>";
		echo "        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:8px;'>";
		echo "          <label class='mac-label' style='margin-bottom:0;'>To: *</label>";
		echo "          <div style='display:flex; gap:8px;'>";
		echo "            <button type='button' class='mac-btn' onclick='loadDefaultDestinations()' title='Set form destinations with one click'>";
		echo "              [ ⚡ Load Default 'To' Destinations ]";
		echo "            </button>";
		echo "            <button type='button' class='mac-btn' onclick='toggleEditDefaultsModal(true)' title='Edit frequently used distribution lists'>";
		echo "              [ ⚙ Edit 'To' Defaults ]";
		echo "            </button>";
		echo "          </div>";
		echo "        </div>";
		echo "        <div style='display:flex; gap:10px; font-weight:bold; margin-bottom:4px; opacity:0.8;'>";
		echo "          <div style='flex:1;'>To (e.g. " . htmlspecialchars(format_address_display($primary_default_endpoint)) . ")</div>";
		echo "          <div style='width:90px;'>Action</div>";
		echo "        </div>";
		echo "        <div id='destinationsContainer'>";
		// Render default 1 option or preserved options
		echo "        </div>";
		echo "        <div style='margin-top:8px;'>";
		echo "          <button type='button' class='mac-btn' onclick='addDestinationRow()'>";
		echo "            [ + Add Another 'To' Destination ]";
		echo "          </button>";
		echo "        </div>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <label for='senderNameInput' class='mac-label'>From (Your Name / Handle):</label>";
		echo "        <input type='text' id='senderNameInput' name='sender_name' class='mac-input' maxlength='100' value='" . htmlspecialchars(get_logged_in_username()) . "'>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <label for='subjectInput' class='mac-label'>Subject (optional, max 255 chars):</label>";
		echo "        <input type='text' id='subjectInput' name='subject' class='mac-input' maxlength='255' placeholder='(Optional subject)'>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:gap; gap:6px; margin-bottom:4px;'>";
		echo "          <label for='messageInput' class='mac-label' style='margin-bottom:0;'>Message Body (max 15,000 chars): *</label>";
		echo "          <span id='messageLengthCounter' style='opacity:0.8;'>0 / 15,000 chars (0 / 65,535 UTF-8 bytes)</span>";
		echo "        </div>";
		echo "        <textarea id='messageInput' name='message' class='mac-textarea' maxlength='15000' oninput='updateMessageLengthCounter()' placeholder='Write your message to the destination site host...' required></textarea>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <label class='mac-checkbox-label'>";
		echo "          <input type='checkbox' name='include_all_recipients' value='1' checked>";
		echo "          Include all 'To' destinations in message information (Visible Distribution List)";
		echo "        </label>";
		echo "      </div>";

		echo "      <div class='mac-form-group' style='background:var(--crucible-charcoal); border:1px solid var(--brass-stamped); padding:8px 12px;'>";
		echo "        <strong>From:</strong> Automatically declared as <code>" . htmlspecialchars(format_address_display($canonical_url)) . "</code>.";
		echo "      </div>";

		echo "      <div style='margin-top:14px; display:flex; gap:10px;'>";
		echo "        <button type='submit' class='mac-btn mac-btn-default'>[ ✉ Send Federated Mail ]</button>";
		echo "        <button type='reset' class='mac-btn' onclick='setTimeout(loadDefaultDestinations, 50)'>[ Clear ]</button>";
		echo "      </div>";
		echo "    </form>";
		echo "  </div>";
		echo "</div>";
	}

	// ----------------------------------------------------------
	// TAB: INBOX (RECEIVED & SENT COPY MESSAGES)
	// ----------------------------------------------------------
	elseif ($active_tab === 'inbox') {
		echo "<div class='upload-panel'>";
		echo "  <div class='upload-header'>";
		echo "    <span class='upload-header-title'>INCOMING MESSAGES INBOX (" . count($incoming_messages) . ")</span>";
		if ($unread_count > 0) {
			echo "    <form action='index.php' method='POST' style='display:inline; margin:0;'>";
			echo "      <input type='hidden' name='action' value='mark_all_read'>";
			echo "      <button type='submit' class='mac-btn' style='padding:2px 8px;'>[ ✓ Mark All Read ({$unread_count}) ]</button>";
			echo "    </form>";
		}
		echo "  </div>";
		echo "  <div class='upload-body'>";
		if (empty($incoming_messages)) {
			echo "<p style='text-align:center; padding:20px; font-style:italic;'>[ No messages in inbox yet ]</p>";
		} else {
			foreach ($incoming_messages as $msg) {
				$is_sent = !empty($msg['is_sent_copy']);
				$is_delayed = !empty($msg['is_delayed']);
				$is_unread = (empty($msg['is_read']) && !$is_sent && !$is_delayed);
				$s_host = trim($msg['sender_host'] ?? '');
				$s_url  = trim($msg['sender_url'] ?? '');
				$push_disabled_for_source = (!$is_sent && !$is_delayed && function_exists('is_push_disabled_for_source')) ? is_push_disabled_for_source($pdo, $host_domain, $s_host, $s_url) : false;

				$border_color = $is_delayed ? "var(--on-air-crimson)" : ($is_unread ? "var(--hot-letter-orange)" : "var(--brass-stamped)");
				echo "<div style='border:2px solid {$border_color}; " . ($is_unread ? "border-left:5px solid var(--hot-letter-orange); " : "") . "box-shadow:3px 3px 0px #000; margin-bottom:14px; background:var(--chassis-iron);'>";
				echo "  <div style='background:var(--crucible-charcoal); border-bottom:1px solid var(--brass-stamped); padding:8px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
				echo "    <div>";
				$display_subj = trim($msg['subject'] ?? '');
				echo "      <strong>" . htmlspecialchars($display_subj !== '' ? $display_subj : '(No Subject)') . "</strong>";
				if ($is_delayed) {
					echo "      <span style='margin-left:10px;'>To: <strong>" . htmlspecialchars(function_exists('format_address_display') ? format_address_display($msg['destination_url']) : $msg['destination_url']) . "</strong></span>";
				} elseif ($is_sent) {
					$dest_clean = function_exists('format_address_display') ? format_address_display($msg['destination_url']) : $msg['destination_url'];
					echo "      <span style='margin-left:10px;'>To: <strong>" . htmlspecialchars($dest_clean ?: 'Subscribers') . "</strong></span>";
					if (!empty($msg['sender_name'])) {
						echo "      <span style='margin-left:10px;'>From: <strong>" . htmlspecialchars($msg['sender_name']) . "</strong></span>";
					}
				} else {
					$clean_src = function_exists('format_address_display') ? format_address_display($msg['sender_url'] ?: $msg['sender_host']) : ($msg['sender_url'] ?: $msg['sender_host']);
					$from_text = (!empty($msg['sender_name']) && $msg['sender_name'] !== $clean_src) ? ($msg['sender_name'] . " (" . $clean_src . ")") : $clean_src;
					echo "      <span style='margin-left:10px;'>From: <strong>" . htmlspecialchars($from_text) . "</strong></span>";
				}
				echo "    </div>";
				echo "    <div style='display:flex; align-items:center; gap:8px; flex-wrap:wrap;'>";
				if ($is_unread) {
					echo "      <span class='status-badge status-badge-active' style='font-weight:bold;'>● UNREAD</span>";
				}
				if ($push_disabled_for_source) {
					echo "      <span class='status-badge' style='background:var(--brass-stamped); color:var(--incandescent-white);' title='Push notifications are disabled for this individual source. Messages still appear in inbox until marked read.'>🔕 Push Muted</span>";
				}
				if ($is_delayed) {
					echo "      <span class='status-badge' style='background:var(--on-air-crimson); color:var(--incandescent-white);'>⏳ DELAYED (RATE LIMITED)</span>";
				} elseif ($is_sent) {
					echo "      <span class='status-badge' style='background:var(--crucible-charcoal); border:1px solid var(--brass-stamped);'>📤 SENT COPY</span>";
				} else {
					echo "      <span class='status-badge status-badge-active'>✓ RECEIVED</span>";
				}
				if (!empty($msg['is_server_group_letter'])) {
					echo "      <span class='status-badge' style='background:var(--chassis-iron); border:1px solid var(--brass-stamped);'>👥 Server Group Letter</span>";
				} elseif (!empty($msg['is_broadcast_from_main'])) {
					$clean_target = function_exists('format_address_display') ? format_address_display($msg['target_domain']) : $msg['target_domain'];
					echo "      <span class='status-badge' style='background:var(--chassis-iron); border:1px solid var(--brass-stamped);'>📢 Transmitter Broadcast (" . htmlspecialchars($clean_target) . ")</span>";
				}
				echo "      <span>" . htmlspecialchars($msg['received_at']) . "</span>";
				echo "    </div>";
				echo "  </div>";

				// Delayed Message Info Bar
				if ($is_delayed) {
					echo "<div style='background:var(--crucible-charcoal); border-bottom:1px solid var(--on-air-crimson); padding:8px 12px; color:var(--hot-letter-orange);'>";
					echo "  <div><strong>⚠ Rate Limit Delay Notice:</strong> This message was not sent because the destination website daily message limit was reached.</div>";
					if (!empty($msg['retry_after'])) {
						echo "  <div style='margin-top:3px;'><strong>Eligible to send after:</strong> <code style='font-weight:bold; background:var(--chassis-iron); color:var(--filament-amber); padding:2px 6px; border:1px solid var(--brass-stamped);'>" . htmlspecialchars($msg['retry_after']) . "</code></div>";
					}
					echo "</div>";
				}

				// Metadata Bar for visible distribution list
				if (!empty($msg['all_recipients'])) {
					echo "<div style='background:var(--crucible-charcoal); border-bottom:1px solid var(--brass-stamped); padding:6px 12px;'>";
					echo "<div><strong>To:</strong> " . htmlspecialchars(function_exists('format_address_display') ? format_address_display($msg['all_recipients']) : $msg['all_recipients']) . "</div>";
					echo "</div>";
				}

				echo "  <div style='padding:12px; line-height:1.5; white-space:pre-wrap; border-bottom:1px solid var(--brass-stamped); color:var(--filament-amber);'>" . htmlspecialchars($msg['message_body']) . "</div>";
				echo "  <div style='background:var(--crucible-charcoal); padding:6px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
				echo "    <div>";
				if ($is_delayed) {
					echo "      <strong>Status:</strong> Saved in your inbox as delayed. If not deleted, you can send it after the delay.";
				} else {
					echo "      <strong>Status:</strong> Delivered to inbox";
				}
				echo "    </div>";
				echo "    <div style='display:flex; gap:8px; flex-wrap:wrap;'>";
				if ($is_delayed) {
					echo "      <form action='index.php' method='POST' style='display:inline;'>";
					echo "        <input type='hidden' name='action' value='send_delayed_message'>";
					echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
					echo "        <button type='submit' class='mac-btn mac-btn-default' style='padding:2px 8px;'>[ 🚀 Send Message Now ]</button>";
					echo "      </form>";
					echo "      <form action='index.php' method='POST' style='display:inline;' onsubmit=\"return confirm('Delete delayed message? It will not be sent.');\">";
					echo "        <input type='hidden' name='action' value='delete_message'>";
					echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
					echo "        <button type='submit' class='mac-btn' style='padding:2px 8px;'>[ ✕ Delete ]</button>";
					echo "      </form>";
				} else {
					if (!$is_sent) {
						$raw_subj = trim($msg['subject'] ?? '');
						$reply_subject = ($raw_subj !== '') ? ((stripos($raw_subj, 'Re:') === 0) ? $raw_subj : ('Re: ' . $raw_subj)) : '';
						$clean_reply_url = function_exists('format_address_display') ? format_address_display($msg['sender_url'] ?: $msg['sender_host']) : ($msg['sender_url'] ?: $msg['sender_host']);
						$reply_href = 'index.php?tab=mail&reply_url=' . urlencode($clean_reply_url) . ($reply_subject !== '' ? '&reply_subject=' . urlencode($reply_subject) : '');
						echo "      <a href='" . htmlspecialchars($reply_href, ENT_QUOTES) . "' class='mac-btn mac-btn-default' style='padding:2px 8px;'>[ ↩ Reply ]</a>";
						echo "      <form action='index.php' method='POST' style='display:inline;'>";
						echo "        <input type='hidden' name='action' value='toggle_read'>";
						echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
						echo "        <button type='submit' class='mac-btn' style='padding:2px 8px;'>" . (empty($msg['is_read']) ? '[ ✓ Mark Read ]' : '[ ○ Mark Unread ]') . "</button>";
						echo "      </form>";
						if ($push_disabled_for_source) {
							echo "      <form action='index.php' method='POST' style='display:inline;'>";
							echo "        <input type='hidden' name='action' value='enable_push_source'>";
							echo "        <input type='hidden' name='source' value='" . htmlspecialchars($s_host) . "'>";
							echo "        <input type='hidden' name='return_tab' value='inbox'>";
							echo "        <button type='submit' class='mac-btn' style='padding:2px 8px;' title='Re-enable push notifications for " . htmlspecialchars($s_host) . "'>[ 🔔 Enable Push ]</button>";
							echo "      </form>";
						} elseif ($s_host !== '') {
							echo "      <form action='index.php' method='POST' style='display:inline;'>";
							echo "        <input type='hidden' name='action' value='disable_push_source'>";
							echo "        <input type='hidden' name='source' value='" . htmlspecialchars($s_host) . "'>";
							echo "        <input type='hidden' name='return_tab' value='inbox'>";
							echo "        <button type='submit' class='mac-btn' style='padding:2px 8px;' title='Disable push notifications for " . htmlspecialchars($s_host) . " (messages will still show in inbox)'>[ 🔕 Disable Push ]</button>";
							echo "      </form>";
						}
						echo "      <form action='index.php' method='POST' style='display:inline;' onsubmit=\"return confirm('Block all contact from " . htmlspecialchars($msg['sender_host']) . "?');\">";
						echo "        <input type='hidden' name='action' value='block_site'>";
						echo "        <input type='hidden' name='target' value='" . htmlspecialchars($msg['sender_host']) . "'>";
						echo "        <button type='submit' class='mac-btn mac-btn-danger' style='padding:2px 8px;'>[ 🚫 Block Site ]</button>";
						echo "      </form>";
					}
					echo "      <form action='index.php' method='POST' style='display:inline;' onsubmit=\"return confirm('Delete message?');\">";
					echo "        <input type='hidden' name='action' value='delete_message'>";
					echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
					echo "        <button type='submit' class='mac-btn' style='padding:2px 8px;'>[ ✕ Delete ]</button>";
					echo "      </form>";
				}
				echo "    </div>";
				echo "  </div>";
				echo "</div>";
			}
		}
		echo "  </div>";
		echo "</div>";
	}

	// ----------------------------------------------------------
	// TAB: SENT OUTBOX
	// ----------------------------------------------------------
	elseif ($active_tab === 'sent') {
		echo "<div class='upload-panel'>";
		echo "  <div class='upload-header'>";
		echo "    <span class='upload-header-title'>SENT FEDERATED MESSAGES (" . count($sent_messages) . ")</span>";
		echo "  </div>";
		echo "  <div class='upload-body'>";
		if (empty($sent_messages)) {
			echo "<p style='text-align:center; padding:20px; font-style:italic;'>[ No messages sent yet ]</p>";
		} else {
			echo "<div class='table-responsive'>";
			echo "<table class='mac-table'>";
			echo "  <thead><tr><th><span>To</span></th><th><span>Subject</span></th><th><span>Status</span></th><th><span>Sent At</span></th></tr></thead>";
			echo "  <tbody>";
			foreach ($sent_messages as $s) {
				echo "<tr>";
				echo "  <td>";
				echo "    <div style='font-weight:bold; word-break:break-all;'>" . htmlspecialchars(function_exists('format_address_display') ? format_address_display($s['destination_url']) : $s['destination_url']) . "</div>";
				if (!empty($s['all_recipients'])) {
					echo "    <div style='color:var(--filament-amber); opacity:0.8; margin-top:2px;'>To: " . htmlspecialchars(function_exists('format_address_display') ? format_address_display($s['all_recipients']) : $s['all_recipients']) . "</div>";
				}
				echo "  </td>";
				$display_sent_subj = trim($s['subject'] ?? '');
				echo "  <td><strong>" . htmlspecialchars($display_sent_subj !== '' ? $display_sent_subj : '(No Subject)') . "</strong></td>";
				echo "  <td>";
				if ($s['delivery_status'] === 'DELIVERED') {
					echo "<span class='status-badge status-badge-active'>✓ DELIVERED</span>";
				} elseif ($s['delivery_status'] === 'DELAYED') {
					echo "<span class='status-badge' style='background:var(--on-air-crimson); color:var(--incandescent-white);'>⏳ DELAYED</span>";
				} elseif ($s['delivery_status'] === 'PARTIALLY_DELIVERED') {
					echo "<span class='status-badge' style='background:var(--brass-stamped); color:var(--incandescent-white);'>⚠ PARTIAL</span>";
				} else {
					echo "<span class='status-badge' style='background:var(--chassis-iron); border:1px solid var(--brass-stamped);'>✕ " . htmlspecialchars($s['delivery_status']) . "</span>";
				}
				echo "  <div style='margin-top:3px; opacity:0.8; white-space:pre-wrap;'>" . htmlspecialchars($s['response_message']) . "</div>";
				echo "  </td>";
				echo "  <td style='white-space:nowrap;'>" . htmlspecialchars($s['sent_at']) . "</td>";
				echo "</tr>";
			}
			echo "  </tbody>";
			echo "</table>";
			echo "</div>";
		}
		echo "  </div>";
		echo "</div>";
	}

	// ----------------------------------------------------------
	// TAB: COMMUNICATION CONTROLS & SITE BLOCKLIST
	// ----------------------------------------------------------
	elseif ($active_tab === 'comms') {
		echo "<div class='upload-panel'>";
		echo "  <div class='upload-header'>";
		echo "    <span class='upload-header-title'>COMMUNICATION CONTROLS &amp; SITE BLOCKLIST</span>";
		echo "  </div>";
		echo "  <div class='upload-body'>";

		// Browser & Progressive Web App (PWA) Push Notifications Section
		$push_notifications_enabled = (get_setting('push_notifications_enabled', '1', $host_domain) === '1');
		$push_outside_enabled = (get_setting('push_notifications_outside_enabled', '1', $host_domain) === '1');
		$push_inside_enabled = (get_setting('push_notifications_inside_enabled', '1', $host_domain) === '1');
		$push_main_broadcast_enabled = (get_setting('push_notifications_main_broadcast_enabled', '1', $host_domain) === '1');

		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; margin-bottom:18px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
		echo "        <h3 style='margin:0;'>Browser &amp; PWA Push Notifications</h3>";
		echo "        <span id='pushStatusBadge' class='status-badge " . ($push_notifications_enabled ? 'status-badge-active' : '') . "'>";
		echo "          " . ($push_notifications_enabled ? '● PUSH NOTIFICATIONS ENABLED' : '○ PUSH NOTIFICATIONS DISABLED');
		echo "        </span>";
		echo "      </div>";
		echo "      <p style='line-height:1.4;'>";
		echo "        Receive instant OS-level push notifications whenever peer websites send a message to <strong>" . htmlspecialchars($host_domain) . "</strong>. Notifications arrive even when this tab is closed, in Chrome, Safari (macOS &amp; iOS 16.4+), Firefox, and Edge. Zero email, zero SMTP, and zero third-party accounts.";
		echo "      </p>";
		echo "      <div style='display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px; margin-bottom:18px; padding-bottom:14px; border-bottom:1px dashed var(--brass-stamped);'>";
		echo "        <button type='button' id='pushToggleBtnSettings' class='mac-btn mac-btn-default' onclick='togglePushSubscription()'>";
		echo "          [ 🔔 Subscribe This Device ]";
		echo "        </button>";
		echo "        <button type='button' class='mac-btn' onclick='sendTestPushNotification()'>";
		echo "          [ 🚀 Send Test Notification ]";
		echo "        </button>";
		echo "        <button type='button' id='pwaInstallBtnSettings' class='mac-btn' style='display:none;' onclick='installPWA()'>";
		echo "          [ 📲 Install addr.bar App ]";
		echo "        </button>";
		echo "        <form action='index.php' method='POST' style='margin:0;'>";
		echo "          <input type='hidden' name='action' value='toggle_push_notifications'>";
		if ($push_notifications_enabled) {
			echo "          <button type='submit' class='mac-btn mac-btn-danger' style='padding:6px 14px;'>";
			echo "            [ ⚪ Click to TOGGLE OFF Push Alerts ]";
			echo "          </button>";
		} else {
			echo "          <button type='submit' class='mac-btn mac-btn-default' style='padding:6px 14px;'>";
			echo "            [ 🟢 Click to ENABLE Push Alerts ]";
			echo "          </button>";
		}
		echo "        </form>";
		echo "      </div>";

		// Granular Push Notification Options Form
		echo "      <form action='index.php' method='POST' style='margin:0;'>";
		echo "        <input type='hidden' name='action' value='save_push_options'>";
		echo "        <h4 style='margin:0 0 8px 0; text-transform:uppercase; letter-spacing:0.5px;'>";
		echo "          ⚙️ Options for Push Notifications";
		echo "        </h4>";
		echo "        <p style='color:var(--filament-amber); opacity:0.85; margin-bottom:14px; line-height:1.4;'>";
		echo "          Push notifications can be disabled from outside sources, and from the main contact point. When disabled, incoming messages still arrive safely in your inbox, but push alerts will not be sounded or sent to your devices.";
		echo "        </p>";

		echo "        <div style='display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px; margin-bottom:16px;'>";

		// Master Option
		echo "          <div style='border:1px solid var(--brass-stamped); padding:12px; background:var(--crucible-charcoal); box-shadow:2px 2px 0px #000;'>";
		echo "            <label class='mac-checkbox-label' style='font-weight:bold; cursor:pointer;'>";
		echo "              <input type='checkbox' name='push_notifications_enabled' value='1' " . ($push_notifications_enabled ? 'checked' : '') . ">";
		echo "              Enable Push Notifications";
		echo "            </label>";
		echo "            <div style='color:var(--filament-amber); opacity:0.8; margin-top:6px; line-height:1.3;'>";
		echo "              Master switch for all Web Push alerts on this domain.";
		echo "            </div>";
		echo "            <div style='margin-top:8px;'>";
		echo "              <span class='status-badge " . ($push_notifications_enabled ? 'status-badge-active' : '') . "'>";
		echo "                " . ($push_notifications_enabled ? 'MASTER: ON' : 'MASTER: OFF');
		echo "              </span>";
		echo "            </div>";
		echo "          </div>";

		// Outside Sources Option
		echo "          <div style='border:1px solid var(--brass-stamped); padding:12px; background:var(--crucible-charcoal); box-shadow:2px 2px 0px #000;'>";
		echo "            <label class='mac-checkbox-label' style='font-weight:bold; cursor:pointer;'>";
		echo "              <input type='checkbox' name='push_notifications_outside_enabled' value='1' " . ($push_outside_enabled ? 'checked' : '') . ">";
		echo "              Allow from Outside Sources";
		echo "            </label>";
		echo "            <div style='color:var(--filament-amber); opacity:0.8; margin-top:6px; line-height:1.3;'>";
		echo "              Receive push alerts for messages from external / remote websites over the internet. Uncheck to disable outside push notifications.";
		echo "            </div>";
		echo "            <div style='margin-top:8px;'>";
		echo "              <span class='status-badge " . ($push_outside_enabled ? 'status-badge-active' : '') . "'>";
		echo "                " . ($push_outside_enabled ? 'OUTSIDE: ENABLED' : 'OUTSIDE: DISABLED');
		echo "              </span>";
		echo "            </div>";
		echo "          </div>";

		// Inside Sources Option
		echo "          <div style='border:1px solid var(--brass-stamped); padding:12px; background:var(--crucible-charcoal); box-shadow:2px 2px 0px #000;'>";
		echo "            <label class='mac-checkbox-label' style='font-weight:bold; cursor:pointer;'>";
		echo "              <input type='checkbox' name='push_notifications_inside_enabled' value='1' " . ($push_inside_enabled ? 'checked' : '') . ">";
		echo "              Allow from Inside Sources";
		echo "            </label>";
		echo "            <div style='color:var(--filament-amber); opacity:0.8; margin-top:6px; line-height:1.3;'>";
		echo "              Receive push alerts for messages from domains hosted on this local server or recognized local sites.";
		echo "            </div>";
		echo "            <div style='margin-top:8px;'>";
		echo "              <span class='status-badge " . ($push_inside_enabled ? 'status-badge-active' : '') . "'>";
		echo "                " . ($push_inside_enabled ? 'INSIDE: ENABLED' : 'INSIDE: DISABLED');
		echo "              </span>";
		echo "            </div>";
		echo "          </div>";

		// Main Contact Point Option
		echo "          <div style='border:1px solid var(--brass-stamped); padding:12px; background:var(--crucible-charcoal); box-shadow:2px 2px 0px #000;'>";
		echo "            <label class='mac-checkbox-label' style='font-weight:bold; cursor:pointer;'>";
		echo "              <input type='checkbox' name='push_notifications_main_broadcast_enabled' value='1' " . ($push_main_broadcast_enabled ? 'checked' : '') . ">";
		echo "              Allow from Main Contact Point";
		echo "            </label>";
		echo "            <div style='color:var(--filament-amber); opacity:0.8; margin-top:6px; line-height:1.3;'>";
		echo "              Receive push alerts when the main contact point broadcasts messages or sends alerts to subscribed clients. Uncheck to disable.";
		echo "            </div>";
		echo "            <div style='margin-top:8px;'>";
		echo "              <span class='status-badge " . ($push_main_broadcast_enabled ? 'status-badge-active' : '') . "'>";
		echo "                " . ($push_main_broadcast_enabled ? 'MAIN POINT: ENABLED' : 'MAIN POINT: DISABLED');
		echo "              </span>";
		echo "            </div>";
		echo "          </div>";

		echo "        </div>";

		echo "        <div style='display:flex; align-items:center; gap:12px; flex-wrap:wrap;'>";
		echo "          <button type='submit' class='mac-btn mac-btn-default' style='padding:6px 16px;'>";
		echo "            [ 💾 Save Push Notification Options ]";
		echo "          </button>";
		echo "        </div>";
		echo "      </form>";

		// Individual Sources Push Notification Controls
		$disabled_sources = function_exists('get_push_disabled_sources_for_domain') ? get_push_disabled_sources_for_domain($pdo, $host_domain) : [];

		echo "      <div style='margin-top:20px; padding-top:16px; border-top:1px dashed var(--brass-stamped);'>";
		echo "        <h4 style='margin:0 0 6px 0; text-transform:uppercase;'>";
		echo "          🔕 Individual Sources with Push Notifications Disabled";
		echo "        </h4>";
		echo "        <p style='color:var(--filament-amber); opacity:0.85; margin-bottom:12px; line-height:1.4;'>";
		echo "          Push notifications can be disabled from individual sources. When disabled, incoming messages from these sources will not trigger device push alerts, but until marked as read the inbox count will be present for messages.";
		echo "        </p>";

		// Add source form
		echo "        <form action='index.php' method='POST' style='display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px;'>";
		echo "          <input type='hidden' name='action' value='disable_push_source'>";
		echo "          <input type='hidden' name='return_tab' value='comms'>";
		echo "          <input type='text' name='source' placeholder='domain-or-website.com' class='mac-input' style='width:260px;' required>";
		echo "          <button type='submit' class='mac-btn'>[ 🔕 Disable Push for Source ]</button>";
		echo "        </form>";

		// Table of disabled sources
		if (!empty($disabled_sources)) {
			echo "      <div class='table-responsive'>";
			echo "        <table class='mac-table' style='width:100%; margin-top:8px;'>";
			echo "          <thead>";
			echo "            <tr>";
			echo "              <th>Disabled Source (Domain / Host)</th>";
			echo "              <th>Muted Since</th>";
			echo "              <th style='text-align:right;'>Action</th>";
			echo "            </tr>";
			echo "          </thead>";
			echo "          <tbody>";
			foreach ($disabled_sources as $ds) {
				echo "            <tr>";
				echo "              <td><code>" . htmlspecialchars($ds['source_identifier']) . "</code></td>";
				echo "              <td>" . htmlspecialchars($ds['created_at']) . "</td>";
				echo "              <td style='text-align:right;'>";
				echo "                <form action='index.php' method='POST' style='display:inline; margin:0;'>";
				echo "                  <input type='hidden' name='action' value='enable_push_source'>";
				echo "                  <input type='hidden' name='source' value='" . htmlspecialchars($ds['source_identifier']) . "'>";
				echo "                  <input type='hidden' name='return_tab' value='comms'>";
				echo "                  <button type='submit' class='mac-btn mac-btn-default' style='padding:2px 8px;'>[ 🔔 Re-enable Push ]</button>";
				echo "                </form>";
				echo "              </td>";
				echo "            </tr>";
			}
			echo "          </tbody>";
			echo "        </table>";
			echo "      </div>";
		} else {
			echo "        <p style='color:var(--filament-amber); opacity:0.75; font-style:italic; margin:4px 0 0 0;'>[ No individual sources currently muted. All allowed sources trigger push alerts. ]</p>";
		}
		echo "      </div>";
		echo "    </div>";

		// Global Toggle Section
		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; margin-bottom:18px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <h3 style='margin-top:0;'>Global Communication Toggle</h3>";
		echo "      <p style='line-height:1.4;'>";
		echo "        If you do not wish to receive communications from any peer websites, you can toggle this option off. When turned off, all incoming federated message attempts will be rejected.";
		echo "      </p>";
		echo "      <div style='display:flex; align-items:center; gap:16px; margin-top:12px; flex-wrap:wrap;'>";
		echo "        <form action='index.php' method='POST'>";
		echo "          <input type='hidden' name='action' value='toggle_communications'>";
		if ($comms_enabled) {
			echo "          <button type='submit' class='mac-btn mac-btn-danger' style='padding:8px 16px;'>";
			echo "            [ 🔴 Click to TOGGLE OFF All Incoming Communications ]";
			echo "          </button>";
		} else {
			echo "          <button type='submit' class='mac-btn mac-btn-default' style='padding:8px 16px;'>";
			echo "            [ 🟢 Click to ENABLE Incoming Communications ]";
			echo "          </button>";
		}
		echo "        </form>";
		echo "        <span class='status-badge " . ($comms_enabled ? 'status-badge-active' : '') . "'>";
		echo "          Status: " . ($comms_enabled ? 'ACCEPTING COMMUNICATIONS' : 'COMMUNICATIONS DISABLED');
		echo "        </span>";
		echo "      </div>";
		echo "    </div>";

		// Website Message Rate Limiting Section (Outside vs Inside)
		$rate_limit_enabled = (get_setting('rate_limiting_enabled', '1') === '1');
		$limit_outside = (int)get_setting('rate_limit_outside_per_day', get_setting('rate_limit_per_website_per_day', '10'));
		$limit_inside = (int)get_setting('rate_limit_inside_per_day', '100');
		if ($limit_outside < 1) $limit_outside = 10;
		if ($limit_inside < 1) $limit_inside = 100;

		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; margin-bottom:18px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <h3 style='margin-top:0;'>Website Message Rate Limiting &amp; Delay Controls</h3>";
		echo "      <p style='line-height:1.4;'>";
		echo "        Configure independent daily message quotas for <strong>Outside (Remote Websites)</strong> and <strong>Inside (Local Server / Subdomains)</strong>. Senders recognized as local domains or configured in the server hosts file receive the inside rate limit. When a limit is reached, future messages are delayed. If a limit causes a message to not be sent, it is stored in the sender's inbox as delayed and can be sent after the delay.";
		echo "      </p>";
		echo "      <form action='index.php' method='POST' style='display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-top:12px;'>";
		echo "        <input type='hidden' name='action' value='update_rate_limit'>";
		echo "        <div>";
		echo "          <label class='mac-label'>Outside Limit (Remote Websites / Day):</label>";
		echo "          <input type='number' name='rate_limit_outside_per_day' class='mac-input' style='width:120px;' value='{$limit_outside}' min='1' max='10000' required>";
		echo "        </div>";
		echo "        <div>";
		echo "          <label class='mac-label'>Inside Limit (Local Server / Day):</label>";
		echo "          <input type='number' name='rate_limit_inside_per_day' class='mac-input' style='width:120px;' value='{$limit_inside}' min='1' max='10000' required>";
		echo "        </div>";
		echo "        <div style='padding-bottom:8px;'>";
		echo "          <label class='mac-checkbox-label'>";
		echo "            <input type='checkbox' name='rate_limiting_enabled' value='1' " . ($rate_limit_enabled ? 'checked' : '') . ">";
		echo "            Enforce daily rate limits";
		echo "          </label>";
		echo "        </div>";
		echo "        <button type='submit' class='mac-btn mac-btn-default' style='margin-bottom:4px;'>[ Save Limit Settings ]</button>";
		echo "        <span class='status-badge " . ($rate_limit_enabled ? 'status-badge-active' : '') . "' style='margin-bottom:6px;'>";
		echo "          " . ($rate_limit_enabled ? "LIMITS ACTIVE: OUTSIDE {$limit_outside} / INSIDE {$limit_inside} MSG/DAY" : "LIMITS DISABLED");
		echo "        </span>";
		echo "      </form>";
		echo "    </div>";

		// Operating Role & Main Website Subscriptions Section
		$server_role = get_setting('server_role', 'main');
		$sub_main_dom = get_setting('subscribed_to_main_domain', '');
		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; margin-bottom:18px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
		echo "        <h3 style='margin:0;'>Operating Role &amp; Main Website Subscriptions</h3>";
		if ($server_role === 'main') {
			echo "        <span class='status-badge status-badge-active'>🏛️ MAIN RECIPIENT POINT</span>";
		} else {
			echo "        <span class='status-badge' style='background:var(--crucible-charcoal); border:1px solid var(--brass-stamped);'>👤 INDIVIDUAL CLIENT</span>";
		}
		echo "      </div>";

		if ($server_role === 'main') {
			echo "      <p style='line-height:1.4;'>";
			echo "        This website operates as the central <strong>Main Recipient Point</strong>. Messages sent to this domain are automatically distributed to all subscribed client websites. For multiple clients installed on this server, a single shared database is used so <strong>only one copy of each message is stored on disk</strong>.";
			echo "      </p>";

			// List subscribed clients
			$sub_clients = [];
			try {
				$sc_stmt = $pdo->prepare("SELECT client_domain, is_local, subscribed_at FROM main_subscriptions WHERE main_domain = ? ORDER BY subscribed_at DESC");
				$sc_stmt->execute([$host_domain]);
				$sub_clients = $sc_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
			} catch (Exception $e) {}

			if (!empty($sub_clients)) {
				echo "      <div style='font-weight:bold; margin-top:10px;'>Subscribed Client Websites (" . count($sub_clients) . "):</div>";
				echo "      <ul style='margin:6px 0 10px 20px; line-height:1.6;'>";
				foreach ($sub_clients as $sc) {
					$loc_badge = !empty($sc['is_local']) ? "<span class='status-badge'>Local Server (Shared DB)</span>" : "<span class='status-badge'>Remote Dispatch</span>";
					echo "        <li><code><strong>" . htmlspecialchars($sc['client_domain']) . "</strong></code> &mdash; {$loc_badge} (Subscribed: " . htmlspecialchars($sc['subscribed_at']) . ")</li>";
				}
				echo "      </ul>";
			} else {
				echo "      <p style='color:var(--filament-amber); opacity:0.75; font-style:italic; margin:8px 0;'>No client websites currently subscribed to this main website.</p>";
			}
		} else {
			echo "      <p style='line-height:1.4;'>";
			echo "        This website operates as an <strong>Individual Client</strong>. You can subscribe to messages sent to the main website. When a message is sent to the main website, your inbox automatically receives a copy. For clients on the same server sharing a single database, <strong>only one copy of the message is stored on disk</strong>.";
			echo "      </p>";

			if (!empty($sub_main_dom)) {
				echo "      <div style='border:2px solid var(--brass-stamped); background:var(--crucible-charcoal); padding:10px 12px; margin:10px 0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
				echo "        <div>";
				echo "          <span style='font-weight:bold;'>Active Subscription:</span> <code><strong>" . htmlspecialchars($sub_main_dom) . "</strong></code>";
				echo "          <div style='color:var(--filament-amber); opacity:0.8; margin-top:2px;'>All incoming messages sent to " . htmlspecialchars($sub_main_dom) . " appear in your inbox tagged with [📢 Subscribed].</div>";
				echo "        </div>";
				echo "        <form action='index.php' method='POST' style='margin:0;'>";
				echo "          <input type='hidden' name='action' value='unsubscribe_main'>";
				echo "          <input type='hidden' name='main_domain' value='" . htmlspecialchars($sub_main_dom) . "'>";
				echo "          <button type='submit' class='mac-btn mac-btn-danger' style='padding:3px 10px;'>[ Unsubscribe ]</button>";
				echo "        </form>";
				echo "      </div>";
			} else {
				echo "      <div style='border:2px solid var(--brass-stamped); background:var(--crucible-charcoal); padding:10px 12px; margin:10px 0;'>";
				echo "        <span style='font-style:italic;'>Not currently subscribed to any main website.</span>";
				echo "      </div>";
			}

			echo "      <form action='index.php' method='POST' style='display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:12px;'>";
			echo "        <input type='hidden' name='action' value='subscribe_main'>";
			echo "        <div style='flex:1; min-width:240px;'>";
			echo "          <label class='mac-label'>Main Website Domain or URL to Subscribe To: *</label>";
			echo "          <input type='text' name='main_domain' class='mac-input' maxlength='255' placeholder='main.example.com or https://main.example.com/contact' value='" . htmlspecialchars($sub_main_dom) . "' required>";
			echo "        </div>";
			echo "        <button type='submit' class='mac-btn mac-btn-default'>[ 🔔 Subscribe to Main Website ]</button>";
			echo "      </form>";
		}
		echo "    </div>";

		// Specific Site Blocklist Section
		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <h3 style='margin-top:0;'>Toggle Off Contact from Specific Sites (Blocklist)</h3>";
		echo "      <p>";
		echo "        Block or disable incoming communications from specific websites or domain names:";
		echo "      </p>";
		echo "      <form action='index.php' method='POST' style='display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;'>";
		echo "        <input type='hidden' name='action' value='block_site'>";
		echo "        <div style='flex:1; min-width:220px;'>";
		echo "          <label class='mac-label'>Domain or Website to Block: *</label>";
		echo "          <input type='text' name='target' class='mac-input' maxlength='255' placeholder='spam-site.com' required>";
		echo "        </div>";
		echo "        <div style='flex:1; min-width:200px;'>";
		echo "          <label class='mac-label'>Reason (optional, max 255 chars):</label>";
		echo "          <input type='text' name='reason' class='mac-input' maxlength='255' placeholder='Spam, unsolicited contact'>";
		echo "        </div>";
		echo "        <button type='submit' class='mac-btn mac-btn-default'>[ + Block Site ]</button>";
		echo "      </form>";

		if (empty($blocked_sites_list)) {
			echo "      <p style='font-style:italic; padding:8px 0;'>No specific sites currently blocked.</p>";
		} else {
			echo "      <div class='table-responsive'>";
			echo "      <table class='mac-table'>";
			echo "        <thead><tr><th><span>Blocked Target</span></th><th><span>Reason</span></th><th><span>Blocked Since</span></th><th><span>Actions</span></th></tr></thead>";
			echo "        <tbody>";
			foreach ($blocked_sites_list as $b) {
				echo "<tr>";
				echo "  <td><strong>" . htmlspecialchars($b['domain_or_url']) . "</strong></td>";
				echo "  <td>" . htmlspecialchars($b['reason'] ?: 'None specified') . "</td>";
				echo "  <td>" . htmlspecialchars($b['created_at']) . "</td>";
				echo "  <td>";
				echo "    <form action='index.php' method='POST' style='display:inline;'>";
				echo "      <input type='hidden' name='action' value='unblock_site'>";
				echo "      <input type='hidden' name='block_id' value='" . (int)$b['id'] . "'>";
				echo "      <button type='submit' class='mac-btn' style='padding:2px 8px;'>[ Unblock / Allow ]</button>";
				echo "    </form>";
				echo "  </td>";
				echo "</tr>";
			}
			echo "        </tbody>";
			echo "      </table>";
			echo "      </div>";
		}
		echo "    </div>";

		// Login Attempt Protection Section (Max 3 Attempts, 6-Hour Lockout)
		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; margin-top:18px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
		echo "        <h3 style='margin:0;'>Session Login Attempt Protection (Max " . MAX_LOGIN_ATTEMPTS_PER_IP . " Failed Attempts &mdash; 6-Hour Lockout)</h3>";
		if (!empty($login_attempts_list)) {
			echo "        <form action='index.php' method='POST' style='margin:0;'>";
			echo "          <input type='hidden' name='action' value='clear_login_attempts'>";
			echo "          <button type='submit' class='mac-btn' style='padding:3px 10px;'>[ Clear All Lockouts ]</button>";
			echo "        </form>";
		}
		echo "      </div>";
		echo "      <p>";
		echo "        Only <strong>" . MAX_LOGIN_ATTEMPTS_PER_IP . " failed login attempts</strong> are permitted per session. Once a session reaches " . MAX_LOGIN_ATTEMPTS_PER_IP . " failed attempts, login is locked for <strong>6 hours</strong>, after which the client can try to log in again.";
		echo "      </p>";
		if (empty($login_attempts_list)) {
			echo "      <p style='font-style:italic; padding:4px 0;'>No failed login attempts currently recorded within the last 6 hours.</p>";
		} else {
			echo "      <div class='table-responsive'>";
			echo "      <table class='mac-table'>";
			echo "        <thead><tr><th><span>Client Session</span></th><th><span>Failed Attempts</span></th><th><span>Status / 6-Hour Timer</span></th><th><span>Unlocks At</span></th><th><span>Actions</span></th></tr></thead>";
			echo "        <tbody>";
			foreach ($login_attempts_list as $la) {
				$cnt = (int)$la['attempt_count'];
				$sec_left = (int)($la['seconds_remaining'] ?? 0);
				$is_locked_ip = ($cnt >= MAX_LOGIN_ATTEMPTS_PER_IP && $sec_left > 0);
				$session_code = 'Session #' . strtoupper(substr(hash('sha256', $la['ip_address']), 0, 8));
				echo "<tr>";
				echo "  <td><code><strong>" . htmlspecialchars($session_code) . "</strong></code></td>";
				echo "  <td>" . $cnt . " / " . MAX_LOGIN_ATTEMPTS_PER_IP . "</td>";
				echo "  <td>" . ($is_locked_ip ? "<span class='status-badge' style='background:var(--on-air-crimson); color:var(--incandescent-white);'>🔒 LOCKED (" . htmlspecialchars(format_lock_timer($sec_left)) . " left)</span>" : "<span class='status-badge'>" . (MAX_LOGIN_ATTEMPTS_PER_IP - $cnt) . " Attempt(s) Left</span>") . "</td>";
				echo "  <td>" . htmlspecialchars($la['unlock_at'] ?? $la['last_attempt']) . "</td>";
				echo "  <td>";
				echo "    <form action='index.php' method='POST' style='display:inline;'>";
				echo "      <input type='hidden' name='action' value='clear_login_attempts'>";
				echo "      <input type='hidden' name='ip_address' value='" . htmlspecialchars($la['ip_address']) . "'>";
				echo "      <button type='submit' class='mac-btn' style='padding:2px 8px;'>[ Reset / Unlock Session ]</button>";
				echo "    </form>";
				echo "  </td>";
				echo "</tr>";
			}
			echo "        </tbody>";
			echo "      </table>";
			echo "      </div>";
		}
		echo "    </div>";

		// Command-Line Server Installation via GitHub Section
		echo "    <div style='border:2px solid var(--brass-stamped); padding:16px; margin-top:18px; background:var(--chassis-iron); box-shadow:3px 3px 0px #000;'>";
		echo "      <h3 style='margin-top:0;'>Command-Line Server Installation via GitHub</h3>";
		echo "      <p style='line-height:1.4;'>";
		echo "        Deploy this federated correspondence suite to another server or website directly from GitHub using the command line:";
		echo "      </p>";
		echo "      <div class='code-box' style='margin:10px 0;'>git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh</div>";
		echo "      <div style='display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;'>";
		echo "        <button type='button' class='mac-btn mac-btn-default' style='padding:6px 14px;' onclick=\"copyToClipboard('git clone https://github.com/addressbar-net/contact.git && cd contact && sudo ./install.sh', this)\">";
		echo "          [ 📋 Copy Git Install Command ]";
		echo "        </button>";
		echo "        <a href='https://github.com/addressbar-net/contact' target='_blank' rel='noopener noreferrer' class='mac-btn' style='padding:6px 14px;'>";
		echo "          [ 🌐 View GitHub Repository ]";
		echo "        </a>";
		echo "      </div>";
		echo "      <div style='margin-top:10px; padding:8px; background:var(--crucible-charcoal); border:1px solid var(--brass-stamped);'>";
		echo "        <strong>Automatic Provisioning:</strong> <code>install.sh</code> handles server dependencies (Nginx, PHP-FPM, SQLite), zero-configuration direct database initialization, administrator setup, and CLI password management utilities.";
		echo "      </div>";
		echo "    </div>";

		echo "  </div>";
		echo "</div>";
	}
?>
<?php endif; ?>

<!-- ============================================================== -->
<!-- CORRESPONDENCE PROTOCOL BLUEPRINT MODAL DIALOG                 -->
<!-- ============================================================== -->
<div id="blueprintModal" onclick="if(event.target===this) toggleBlueprintModal(false);">
	<div class="modal-dialog">
		<div class="upload-header">
			<span class="upload-header-title">BLUEPRINT: FEDERATED SITE-TO-SITE CORRESPONDENCE PROTOCOL</span>
			<button type="button" class="upload-close-box" onclick="toggleBlueprintModal(false)" title="Close Window"></button>
		</div>
		<div class="upload-body" style="max-height:80vh; overflow-y:auto; line-height:1.5;">
			<div style="background:var(--crucible-charcoal); border:2px solid var(--brass-stamped); padding:12px; margin-bottom:16px;">
				<div style="font-weight:bold; margin-bottom:4px;">Ready to Deploy? Install via GitHub from the Command Line:</div>
				<p style="line-height:1.4; margin:4px 0 8px 0;">
					If you operate a website, clone and install the federated contact suite directly on your server:
				</p>
				<div class="code-box">git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh</div>
				<div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" class="mac-btn mac-btn-default" onclick="copyToClipboard('git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh', this)">
						[ 📋 Copy Git Install Command ]
					</button>
					<a href="https://github.com/addressbar-net/contact" target="_blank" rel="noopener noreferrer" class="mac-btn">
						[ 🌐 View Repository on GitHub ]
					</a>
				</div>
			</div>

			<h3 style="margin-top:0;">1. Architectural Blueprint Overview</h3>
			<p>
				The addr.bar Federated Mail Protocol enables sovereign, decentralized communication between independent websites running this PHP directory script. No centralized middleman or third-party email server is required.
			</p>

			<h3 style="margin-top:16px;">2. Key Features</h3>
			<ul style="margin-left:20px; line-height:1.6;">
				<li><strong>Single User Per Domain:</strong> Each domain has a single sovereign host account that manages the directory and receives all incoming correspondence.</li>
				<li><strong>Multiple 'To' Destinations:</strong> Senders can specify one or more 'To' destination addresses in the mail form.</li>
				<li><strong>Single Outbox Storage:</strong> When a message is sent to multiple destination websites, one consolidated message record is saved in the outbox.</li>
				<li><strong>Inbox Storage for Sender:</strong> A copy of all sent dispatches is automatically stored in the sender's own inbox for complete record-keeping and conversation review.</li>
				<li><strong>Website Message Rate Limiting & Delay:</strong> Remote websites can be rate-limited (default 10 messages/day per website). A warning reply is issued when the next message will reach the limit. When the limit is reached, future messages are delayed. If a message cannot be sent due to the limit, it is saved in the sender's inbox as delayed and can be sent after the delay.</li>
				<li><strong>Default 'To' Presets:</strong> Frequently used 'To' destinations can be saved and loaded into the form with a single button click.</li>
				<li><strong>Visible Distribution List:</strong> Senders can optionally include the full 'To' distribution list in the message header.</li>
			</ul>

			<h3 style="margin-top:16px;">3. Communication Workflow</h3>
			<ol style="margin-left:20px; line-height:1.6;">
				<li><strong>Form Composition:</strong> A host visits their own website, logs in, and fills the Mail Form with 'To' destination addresses (e.g., <code><?php echo htmlspecialchars(function_exists('format_address_display') ? format_address_display($primary_default_endpoint) : $primary_default_endpoint); ?></code>), optional subject, and message.</li>
				<li><strong>Direct HTTP Stream Transmission:</strong> The sender website's PHP script initiates a server-to-server direct authenticated HTTP POST request to the destination website's endpoint.</li>
				<li><strong>Host Toggle Check:</strong> The receiving server verifies that incoming communications are currently enabled by the host.</li>
				<li><strong>Site Blocklist Evaluation:</strong> The receiving server checks its blocklist to ensure the sender domain is not blocked.</li>
				<li><strong>Inbox Delivery:</strong> Accepted messages are recorded in SQLite (<code>incoming_messages</code> table) for the domain's host.</li>
			</ol>

			<h3 style="margin-top:16px;">4. Protocol Payload Schema</h3>
			<div class="code-box" style="white-space:pre;">POST /contact/index.php HTTP/1.1
Content-Type: application/x-www-form-urlencoded
User-Agent: addr.bar-Federated-Mail/2.0

action=inter_site_mail
&sender_url=https://tricurse.com/contact/index.php
&sender_name=SenderHost
&all_recipients=https://tricurse.com/contact; https://peer-b.com/contact
&subject=Federated+Handshake
&message=Hello+from+our+server+to+yours!
&timestamp=1729141200</div>

			<h3 style="margin-top:16px;">5. Verification Response Format</h3>
			<div class="code-box" style="white-space:pre;">{
  "success": true,
  "message_id": 42,
  "verification": "STREAM_CONTEXT_POST",
  "note": "Message received via HTTP POST and accepted into host inbox."
}</div>

			<div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
				<div style="display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" class="mac-btn mac-btn-default" onclick="copyToClipboard('git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh', this)">
						[ 📋 Copy Git Command ]
					</button>
					<a href="https://github.com/addressbar-net/contact" target="_blank" rel="noopener noreferrer" class="mac-btn">
						[ 🌐 View on GitHub ]
					</a>
				</div>
				<button type="button" class="mac-btn" onclick="toggleBlueprintModal(false)">
					[ Close Blueprint Window ]
				</button>
			</div>
		</div>
	</div>
</div>

<!-- ============================================================== -->
<!-- EDIT DEFAULT DESTINATIONS MODAL DIALOG                         -->
<!-- ============================================================== -->
<div id="editDefaultsModal" onclick="if(event.target===this) toggleEditDefaultsModal(false);">
	<div class="modal-dialog">
		<div class="upload-header">
			<span class="upload-header-title">EDIT DEFAULT 'TO' DESTINATIONS</span>
			<button type="button" class="upload-close-box" onclick="toggleEditDefaultsModal(false)" title="Close Window"></button>
		</div>
		<div class="upload-body" style="line-height:1.5;">
			<p style="margin-top:0;">
				Configure default 'To' destinations. These can be populated into your mail form with a single click of <strong>[ ⚡ Load Default 'To' Destinations ]</strong>.
			</p>
			<form action="index.php" method="POST">
				<input type="hidden" name="action" value="save_default_destinations">
				<div style="display:flex; gap:10px; font-weight:bold; margin-bottom:4px; opacity:0.8;">
					<div style="flex:1;">Default To</div>
					<div style="width:40px;">Del</div>
				</div>
				<div id="defaultsEditorContainer">
					<?php if (empty($default_destinations_array)): ?>
						<div class="destination-row">
							<div style="flex:1;">
								<input type="text" name="default_urls[]" class="mac-input" maxlength="255" placeholder="<?php echo htmlspecialchars(format_address_display($primary_default_endpoint)); ?>">
							</div>
							<div>
								<button type="button" class="mac-btn" onclick="this.closest('.destination-row').remove()">[ ✕ ]</button>
							</div>
						</div>
					<?php else: ?>
						<?php foreach ($default_destinations_array as $row): ?>
							<?php $row_url = is_string($row) ? $row : ($row['url'] ?? ''); ?>
							<div class="destination-row">
								<div style="flex:1;">
									<input type="text" name="default_urls[]" class="mac-input" maxlength="255" value="<?php echo htmlspecialchars(format_address_display($row_url)); ?>" required>
								</div>
								<div>
									<button type="button" class="mac-btn" onclick="this.closest('.destination-row').remove()">[ ✕ ]</button>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div style="margin-top:8px;">
					<button type="button" class="mac-btn" onclick="addDefaultRow('')">
						[ + Add Another Default 'To' ]
					</button>
				</div>
				<div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center;">
					<button type="submit" class="mac-btn mac-btn-default">
						[ 💾 Save Default 'To' Destinations ]
					</button>
					<button type="button" class="mac-btn" onclick="toggleEditDefaultsModal(false)">
						[ Cancel ]
					</button>
				</div>
			</form>
		</div>
	</div>
</div>

</body>
</html>
