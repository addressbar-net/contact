<?php
// ==============================================================
// addr.bar Configuration & Database Options
// ==============================================================
$date_format    = 'Y-m-d H:i T';

// SQLite Database Configuration (Direct file access, zero server daemon)
$db_file = getenv('SQLITE_PATH') ?: (getenv('DB_PATH') ?: (getenv('MESSAGES_DIR') ? rtrim(getenv('MESSAGES_DIR'), '/\\') . '/contact.sqlite' : (getenv('DATA_DIR') ? rtrim(getenv('DATA_DIR'), '/\\') . '/contact.sqlite' : __DIR__ . '/contact.sqlite')));

if (file_exists(__DIR__ . '/db_config.php')) {
	include __DIR__ . '/db_config.php';
	if (!empty($sqlite_path)) $db_file = $sqlite_path;
	elseif (!empty($db_path)) $db_file = $db_path;
	elseif (!empty($messages_dir)) $db_file = rtrim($messages_dir, '/\\') . '/contact.sqlite';
	elseif (!empty($data_dir)) $db_file = rtrim($data_dir, '/\\') . '/contact.sqlite';
}

if (session_status() === PHP_SESSION_NONE) {
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
				subject TEXT NOT NULL,
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
				subject TEXT NOT NULL,
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
			('email_notifications_enabled', '1'),
			('rate_limiting_enabled', '1'),
			('rate_limit_per_website_per_day', '10'),
			('site_title', 'addr.bar Remote Server Directory'),
			('default_mail_destinations', '[{\"url\":\"https://addressbar.net/contact\",\"user\":\"\"}]')
		");
		$stmt->execute();

		// Seed default admin account if users table is empty
		$cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
		if ($cnt === 0) {
			$hash = password_hash('admin123', PASSWORD_BCRYPT);
			$stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
			$stmt->execute(['admin', $hash, 'admin@localhost']);
		}

	} catch (PDOException $e) {
		error_log("Database initialization error: " . $e->getMessage());
		return null;
	}
	return $pdo;
}

// Settings Helpers
function get_setting($key, $default = '') {
	$pdo = get_db();
	if (!$pdo) return $default;
	try {
		$stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
		$stmt->execute([$key]);
		$val = $stmt->fetchColumn();
		return ($val !== false) ? $val : $default;
	} catch (Exception $e) {
		return $default;
	}
}

function set_setting($key, $value) {
	$pdo = get_db();
	if (!$pdo) return false;
	try {
		$stmt = $pdo->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
		return $stmt->execute([$key, $value]);
	} catch (Exception $e) {
		return false;
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
			$stmt = $pdo->prepare("SELECT * FROM users WHERE domain IS NULL OR domain = '' ORDER BY (username = 'admin') DESC, id ASC LIMIT 1");
			$stmt->execute();
			$fallback = $stmt->fetch();
			if ($fallback) {
				$upd = $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?");
				$upd->execute([$dom, $fallback['id']]);
				$fallback['domain'] = $dom;
				return $fallback;
			}

			// 2b. If exactly one user exists in the users table, bind it to this domain!
			$stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 2");
			$all_users = $stmt ? $stmt->fetchAll() : [];
			if (count($all_users) === 1) {
				$single = $all_users[0];
				$upd = $pdo->prepare("UPDATE users SET domain = ? WHERE id = ?");
				$upd->execute([$dom, $single['id']]);
				$single['domain'] = $dom;
				return $single;
			}

			// 3. Auto-initialize single user for this domain
			$default_user = 'admin';
			$default_hash = password_hash('admin123', PASSWORD_BCRYPT);
			$ins = $pdo->prepare("INSERT INTO users (domain, username, password, email) VALUES (?, ?, ?, NULL)");
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
	if (!empty($_SESSION['auth_user'])) {
		$sess_domain = $_SESSION['auth_user']['domain'] ?? $current_domain;
		if ($sess_domain === $current_domain) {
			return true;
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
					'username' => $domain_user['username'],
					'email'    => $domain_user['email']
				];
				return true;
			}
		}
	}
	return false;
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

	// 1. Single user per domain: verify password against the current domain's user account
	$user = get_single_domain_user(get_current_domain());
	if ($user && verify_and_rehash_password($password_input, $user, $pdo)) {
		return $user;
	}

	// 2. Lookup candidate user by username or email
	$login_input = trim($login_input);
	if ($login_input !== '') {
		try {
			$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? ORDER BY id ASC LIMIT 1");
			$stmt->execute([$login_input, $login_input]);
			$candidate = $stmt->fetch();
			if ($candidate && verify_and_rehash_password($password_input, $candidate, $pdo)) {
				try {
					$cur_dom = get_current_domain();
					$pdo->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$cur_dom, $candidate['id']]);
					$candidate['domain'] = $cur_dom;
				} catch (Exception $e) {}
				return $candidate;
			}
		} catch (Exception $e) {}
	}

	// 3. Fallback: check the primary single user in the database
	try {
		$stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
		$first_user = $stmt ? $stmt->fetch() : null;
		if ($first_user && verify_and_rehash_password($password_input, $first_user, $pdo)) {
			try {
				$cur_dom = get_current_domain();
				$pdo->prepare("UPDATE users SET domain = ? WHERE id = ?")->execute([$cur_dom, $first_user['id']]);
				$first_user['domain'] = $cur_dom;
			} catch (Exception $e) {}
			return $first_user;
		}
	} catch (Exception $e) {}

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
		echo json_encode([
			'success' => true,
			'title'   => $base_domain,
			'body'    => "New message from {$sender}: " . $latest['subject'] . ($preview ? " — {$preview}" : ""),
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
	$sender_email    = trim($_POST['sender_email'] ?? '');
	$recipient_users = trim($_POST['recipient_users'] ?? '');
	$all_recipients  = trim($_POST['all_recipients'] ?? '');

	if ($sender_url === '' || $subject === '' || $message === '') {
		http_response_code(400);
		echo json_encode([
			'success' => false,
			'error'   => 'Missing required fields: sender_url, subject, and message are required.'
		]);
		exit;
	}

	// Enforce utf8mb4 multi-byte / emoji column length limits
	$sender_url   = mb_substr($sender_url, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
	$subject      = mb_substr($subject, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
	$sender_name  = mb_substr($sender_name, 0, MAX_LEN_VARCHAR_100, 'UTF-8');
	$sender_email = mb_substr($sender_email, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
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
	$daily_limit = (int)get_setting('rate_limit_per_website_per_day', '10');
	if ($daily_limit < 1) $daily_limit = 10;

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
				'current_count'         => $current_count,
				'retry_after'           => $reset_timestamp,
				'retry_after_formatted' => $delay_time_str,
				'delay_seconds'         => $delay_seconds,
				'message'               => "Daily limit of {$daily_limit} messages reached for website '{$sender_host}'. A delay is in effect on future messages. Message was not accepted. It can be sent after the delay ({$delay_time_str})."
			]);
			exit;
		}
	}

	// 4. Save to Inbox (incoming_messages)
	$verification_status  = 'STREAM_CONTEXT_POST';
	$verification_details = "Received via stream_context_create() HTTP POST request from {$sender_url} ({$client_ip}).";

	$stmt = $pdo->prepare("
		INSERT INTO incoming_messages 
		(sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status, dns_verification_details, sender_name, sender_email, subject, message_body, recipient_user, all_recipients, is_sent_copy) 
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
	");
	$stmt->execute([
		$sender_url,
		$sender_host,
		$client_ip,
		null,
		$verification_status,
		$verification_details,
		$sender_name ?: null,
		$sender_email ?: null,
		$subject,
		$message,
		null,
		$all_recipients ?: null
	]);
	$msg_id = $pdo->lastInsertId();

	// 4b. Send browser push notification to host if push notifications are enabled
	$push_notifications_enabled = (get_setting('push_notifications_enabled', '1') === '1');
	$current_domain = get_current_domain();

	if ($push_notifications_enabled && function_exists('send_browser_push_notification')) {
		send_browser_push_notification($pdo, $current_domain, [
			'sender_name'  => $sender_name,
			'sender_email' => $sender_email,
			'sender_url'   => $sender_url,
			'sender_host'  => $sender_host,
			'subject'      => $subject,
			'message_body' => $message,
			'received_at'  => date('Y-m-d H:i:s T')
		]);
	}

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
		$resp['current_count'] = $new_count;

		// "A message will be accepted, but a response will be given that there will be a delay on future messages. That's what will be used when a user wants to limit messages from a website."
		if ($new_count >= $daily_limit) {
			$delay_notice = "Daily limit of {$daily_limit} messages per day reached for your website. There will be a delay on future messages until {$delay_time_str}.";
			$resp['warning'] = $delay_notice;
			$resp['limit_reached'] = true;
			$resp['future_delay'] = true;
			$resp['retry_after'] = $reset_timestamp;
			$resp['retry_after_formatted'] = $delay_time_str;
			$resp['note'] .= " {$delay_notice}";
		}
		// "A warning message must be sent in reply to a message being sent if the next message will reach the limit."
		elseif ($new_count === $daily_limit - 1) {
			$warning_msg = "Warning: The next message from your website will reach the limit of {$daily_limit} messages per day. Subsequent messages will experience a delay.";
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
			'error'   => "Maximum of " . MAX_LOGIN_ATTEMPTS_PER_IP . " login attempts reached for IP address {$client_ip}. Login is locked for 6 hours (Time remaining: {$timer_str}, unlocks at {$lock_status['unlock_at']})."
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
			'username' => $user['username'],
			'email'    => $user['email']
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

		setcookie('session_token', $session_token, [
			'expires'  => time() + (86400 * 30),
			'path'     => '/',
			'httponly' => false,
			'samesite' => 'Lax'
		]);
		setcookie('dw_user', $user['username'], [
			'expires'  => time() + (86400 * 30),
			'path'     => '/',
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
			$err_msg = "Invalid password. Maximum of " . MAX_LOGIN_ATTEMPTS_PER_IP . " login attempts reached for IP address {$client_ip}. Login is now locked for 6 hours (Time remaining: {$timer_str}, unlocks at {$updated_lock['unlock_at']}).";
		} else {
			$err_msg = "Invalid password. {$updated_lock['remaining']} of " . MAX_LOGIN_ATTEMPTS_PER_IP . " login attempt(s) remaining for IP address {$client_ip}.";
		}
		$_SESSION['auth_feedback'] = ['success' => false, 'error' => $err_msg];
		header('Location: ' . $_SERVER['SCRIPT_NAME']);
		exit;
	}
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
	unset($_SESSION['auth_user']);
	setcookie('session_token', '', ['expires' => time() - 3600, 'path' => '/']);
	setcookie('dw_user', '', ['expires' => time() - 3600, 'path' => '/']);
	$_SESSION['auth_feedback'] = ['success' => true, 'message' => "You have been logged out."];
	header('Location: ' . $_SERVER['SCRIPT_NAME']);
	exit;
}

// --------------------------------------------------------------
// Handle Email Configuration
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	$post_action = $_POST['action'];

	// 1. Save / Update User Recovery Email Address (Logged-In or Initial Setup with Password)
	if ($post_action === 'save_user_email') {
		$email_input = mb_substr(trim($_POST['email'] ?? ''), 0, MAX_LEN_VARCHAR_255, 'UTF-8');
		$current_domain = get_current_domain();
		$domain_user = get_single_domain_user($current_domain);
		$redirect_tab = trim($_POST['redirect_tab'] ?? '');

		if ($email_input === '' || !filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
			$_SESSION['auth_feedback'] = ['success' => false, 'error' => 'Please enter a valid email address.'];
			header('Location: ' . $_SERVER['SCRIPT_NAME'] . ($redirect_tab ? '?tab=' . urlencode($redirect_tab) : ''));
			exit;
		}

		$authorized = is_logged_in();
		if (!$authorized && $domain_user) {
			$pwd_check = trim($_POST['current_password'] ?? '');
			$pdo = get_db();
			if ($pwd_check !== '' && $pdo && verify_and_rehash_password($pwd_check, $domain_user, $pdo)) {
				$authorized = true;
			}
		}

		if (!$authorized || !$domain_user) {
			$_SESSION['auth_feedback'] = ['success' => false, 'error' => 'Please log in or provide your current host password to configure the domain recovery email address.'];
			header('Location: ' . $_SERVER['SCRIPT_NAME']);
			exit;
		}

		$pdo = get_db();
		if ($pdo) {
			$upd = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
			$upd->execute([$email_input, $domain_user['id']]);
			if (!empty($_SESSION['auth_user'])) {
				$_SESSION['auth_user']['email'] = $email_input;
			}
			$_SESSION['auth_feedback'] = [
				'success' => true,
				'message' => "Recovery email address '{$email_input}' saved for domain '{$current_domain}'."
			];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . ($redirect_tab ? '?tab=' . urlencode($redirect_tab) : ''));
		exit;
	}
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
	if ($action === 'toggle_push_notifications' || $action === 'toggle_email_notifications') {
		$current = get_setting('push_notifications_enabled', '1');
		$next = ($current === '1') ? '0' : '1';
		set_setting('push_notifications_enabled', $next);
		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => ($next === '1') ? 'Browser push notifications for incoming messages are now ENABLED.' : 'Browser push notifications for incoming messages have been TOGGLED OFF.'
		];
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=comms');
		exit;
	}

	// Update Website Message Rate Limit Controls
	if ($action === 'update_rate_limit') {
		$enabled = isset($_POST['rate_limiting_enabled']) ? '1' : '0';
		$limit = (int)($_POST['rate_limit_per_website_per_day'] ?? 10);
		if ($limit < 1) $limit = 10;
		set_setting('rate_limiting_enabled', $enabled);
		set_setting('rate_limit_per_website_per_day', (string)$limit);
		$_SESSION['comms_feedback'] = [
			'success' => true,
			'message' => "Website message limit updated: " . ($enabled === '1' ? "Active ({$limit} messages/day per website)." : "Disabled.")
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
							'sender_email'   => $msg['sender_email'] ?: '',
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

	// Delete Message (Single User Per Domain)
	if ($action === 'delete_message') {
		$msg_id = (int)($_POST['message_id'] ?? 0);
		if ($msg_id > 0) {
			$pdo = get_db();
			if ($pdo) {
				$del_stmt = $pdo->prepare("DELETE FROM incoming_messages WHERE id = ?");
				$del_stmt->execute([$msg_id]);
				$_SESSION['comms_feedback'] = [
					'success' => true,
					'message' => "Message #{$msg_id} deleted."
				];
			}
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
		exit;
	}

	// Toggle Read / Unread Status on a Single Message
	if ($action === 'toggle_read') {
		$msg_id = (int)($_POST['message_id'] ?? 0);
		if ($msg_id > 0) {
			$pdo = get_db();
			if ($pdo) {
				$stmt = $pdo->prepare("UPDATE incoming_messages SET is_read = CASE WHEN is_read = 1 THEN 0 ELSE 1 END WHERE id = ?");
				$stmt->execute([$msg_id]);
			}
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
		exit;
	}

	// Mark All Messages in Inbox as Read
	if ($action === 'mark_all_read') {
		$pdo = get_db();
		if ($pdo) {
			$pdo->exec("UPDATE incoming_messages SET is_read = 1 WHERE is_read = 0");
			$_SESSION['comms_feedback'] = ['success' => true, 'message' => 'All inbox messages marked as read.'];
		}
		header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=inbox');
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
		$sender_email     = mb_substr(trim($_POST['sender_email'] ?? ''), 0, MAX_LEN_VARCHAR_255, 'UTF-8');
		$canonical_url    = get_canonical_site_url();

		if (mb_strlen($message, 'UTF-8') > MAX_LEN_TEXT_UTF8MB4 || strlen($message) > MAX_LEN_TEXT_BYTES) {
			$_SESSION['mail_feedback'] = [
				'success' => false,
				'error'   => 'Message body exceeds the maximum limit of ' . number_format(MAX_LEN_TEXT_UTF8MB4) . ' characters (based on 4-byte utf8mb4 emojis / ' . number_format(MAX_LEN_TEXT_BYTES) . ' bytes).'
			];
			header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=mail');
			exit;
		}

		// Parse and deduplicate destination URLs
		$target_urls = [];
		for ($i = 0; $i < count($destination_urls); $i++) {
			$u = trim($destination_urls[$i] ?? '');
			if ($u === '') continue;
			if (!preg_match('#^https?://#i', $u)) {
				$u = 'https://' . $u;
			}
			$u = mb_substr($u, 0, MAX_LEN_VARCHAR_255, 'UTF-8');
			if (!in_array($u, $target_urls, true)) {
				$target_urls[] = $u;
			}
		}

		if (empty($target_urls) || $subject === '' || $message === '') {
			$_SESSION['mail_feedback'] = ['success' => false, 'error' => 'At least one Destination Website URL, Subject, and Message are all required.'];
			header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?tab=mail');
			exit;
		}

		$all_recipients_str = implode('; ', $target_urls);

		$dispatch_results = [];
		$success_count = 0;
		$delayed_count = 0;
		$total_servers = count($target_urls);
		$remote_warnings = [];
		$delayed_servers_info = [];

		foreach ($target_urls as $target_url) {
			$payload = [
				'action'         => 'inter_site_mail',
				'sender_url'     => $canonical_url,
				'sender_name'    => $sender_name,
				'sender_email'   => $sender_email,
				'subject'        => $subject,
				'message'        => $message,
				'all_recipients' => $include_all ? $all_recipients_str : '',
				'timestamp'      => time(),
			];

			$res = dispatch_inter_site_mail($target_url, $payload);
			$is_ok = ($res['status_code'] >= 200 && $res['status_code'] < 300 && !empty($res['json']['success']));
			$is_delayed = ($res['status_code'] === 429 || (!empty($res['json']['status']) && $res['json']['status'] === 'RATE_LIMITED_DELAY'));

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

		$combined_dest_url = implode('; ', $target_urls);

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
			if ($dr['success']) {
				$resp_summaries[] = "{$dr['url']}: ✓ Delivered ({$dr['verif']})";
			} elseif (!empty($dr['delayed'])) {
				$resp_summaries[] = "{$dr['url']}: ⏳ Delayed (Rate limit reached on remote site)";
			} else {
				$resp_summaries[] = "{$dr['url']}: ✕ Failed ({$dr['error']})";
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

			// 2. Store in Sender's Inbox
			$is_msg_delayed = ($delivery_status === 'DELAYED' || ($delayed_count > 0 && $success_count === 0));
			$retry_ts = null;
			$retry_formatted = null;
			if ($is_msg_delayed && !empty($delayed_servers_info)) {
				$retry_ts = $delayed_servers_info[0]['retry_after'] ? date('Y-m-d H:i:s', $delayed_servers_info[0]['retry_after']) : date('Y-m-d H:i:s', time() + 86400);
				$retry_formatted = $delayed_servers_info[0]['retry_after_formatted'] ?? $retry_ts;
			}

			$stmt_inbox = $pdo->prepare("
				INSERT INTO incoming_messages 
				(sender_url, sender_host, sender_ip, sender_rdns, dns_verification_status, dns_verification_details, sender_name, sender_email, subject, message_body, recipient_user, is_sent_copy, all_recipients, is_read, is_delayed, retry_after, destination_url) 
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?, 1, ?, ?, ?)
			");
			$stmt_inbox->execute([
				$canonical_url,
				parse_url($canonical_url, PHP_URL_HOST) ?: 'localhost',
				$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
				'self-outbox',
				$is_msg_delayed ? 'DELAYED' : 'SENT_COPY_STORED',
				$is_msg_delayed ? ("Delayed by remote website rate limit. Can be sent after: {$retry_formatted}.") : ("Dispatched to {$total_servers} destination website(s). Delivery: {$delivery_status}."),
				$sender_name,
				$sender_email,
				$subject,
				$message,
				$include_all ? $all_recipients_str : null,
				$is_msg_delayed ? 1 : 0,
				$retry_ts,
				$combined_dest_url
			]);
		}

		if ($delivery_status === 'DELIVERED') {
			$msg_text = "✓ Mail delivered successfully to {$total_servers} destination website(s)! Copy stored in your inbox.";
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
				'message' => "⚠ Destination website daily message limit reached. The message was not sent, but has been saved in your inbox as DELAYED. You can send it after the delay."
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

	// Clear Failed Login Attempts for an IP Address (or all IPs on this domain)
	if ($action === 'clear_login_attempts') {
		$target_ip = trim($_POST['ip_address'] ?? '');
		$cur_dom = get_current_domain();
		$pdo = get_db();
		if ($pdo) {
			if ($target_ip !== '') {
				$stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND (domain = ? OR domain IS NULL)");
				$stmt->execute([$target_ip, $cur_dom]);
				$_SESSION['comms_feedback'] = ['success' => true, 'message' => "Cleared failed login attempts for IP '{$target_ip}' on domain '{$cur_dom}'."];
			} else {
				$stmt = $pdo->prepare("DELETE FROM login_attempts WHERE domain = ? OR domain IS NULL");
				$stmt->execute([$cur_dom]);
				$_SESSION['comms_feedback'] = ['success' => true, 'message' => "Cleared all failed IP login attempt records for domain '{$cur_dom}'."];
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
$default_destinations_json = get_setting('default_mail_destinations', '[{"url":"https://addressbar.net/contact"}]');
$default_destinations_array = json_decode($default_destinations_json, true);
if (empty($default_destinations_array)) {
	$default_destinations_array = [['url' => 'https://addressbar.net/contact']];
}

$incoming_messages = [];
$sent_messages = [];
$blocked_sites_list = [];
$login_attempts_list = [];
$unread_count = 0;

if (is_logged_in() && $pdo) {
	try {
		$raw_messages = $pdo->query("SELECT * FROM incoming_messages ORDER BY id DESC LIMIT 50")->fetchAll();
		$incoming_messages = [];
		$unread_count = 0;

		foreach ($raw_messages as $m) {
			$is_sent = !empty($m['is_sent_copy']);
			$incoming_messages[] = $m;
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, nosnippet">
<title>addr.bar Desktop — Federated Contact &amp; Mail</title>
<link rel="manifest" href="index.php?action=manifest">
<meta name="theme-color" content="#000000">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(preg_replace('/:[0-9]+$/', '', get_current_domain())); ?>">
<link rel="apple-touch-icon" href="icon-192.png">
<style>
	:root {
		--font-size--menu: 17pt;
		--min-height--row: 46pt;
		--padding-cell: 6pt;
		--border-cell: 2pt;
		
		/* Classic 1-Bit Macintosh Monochrome System Palette */
		--mac-white: #ffffff;
		--mac-black: #000000;
		--mac-gray-bg: #ececec;
		--mac-gray-border: #808080;
	}
	#invisible_directory { display: none; }
	html, body {
		touch-action: pan-x pan-y;
	}
	body { 
		margin: 0; 
		padding: 16px; 
		min-height: 100vh;
		background-color: var(--mac-white);
		/* Classic Mac OS Desktop Checkerboard Pattern */
		background-image: radial-gradient(var(--mac-black) 1px, transparent 0);
		background-size: 4px 4px;
	}
	* { 
		color: var(--mac-black); 
		font-family: 'Geneva', 'Monaco', 'Chicago', 'Courier New', monospace; 
		font-size: var(--font-size--menu);
		box-sizing: border-box;
	}
	a {
		color: var(--mac-black);
		text-decoration: none;
		font-weight: bold;
	}
	a:hover {
		background-color: var(--mac-black);
		color: var(--mac-white);
	}
	#directoryPath {
		color: var(--mac-black);
		padding: 8px 12px;
		margin: 0 0 12px 0;
		font-weight: bold;
		background-color: var(--mac-white);
		border: 2px solid var(--mac-black);
		box-shadow: 3px 3px 0px var(--mac-black);
		display: flex;
		justify-content: space-between;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
	}
	.desktop-toolbar {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin: 0 0 14px 0;
		flex-wrap: wrap;
		gap: 10px;
	}
	.tbl { 
		display: flex; 
		flex-direction: column; 
		background-color: var(--mac-white);
		border: 2px solid var(--mac-black);
		box-shadow: 4px 4px 0px var(--mac-black);
		padding: 4px;
	}
	.row { 
		display: flex; 
		min-height: var(--min-height--row); 
	}
	.cell { 
		background-color: var(--mac-white); 
		border: 1px solid var(--mac-black); 
		display: flex; 
		flex: 1; 
		padding: var(--padding-cell); 
		align-items: center;
	}
	.cell span { 
		white-space: pre-wrap; 
		color: var(--mac-black);
	}
	.header .cell { 
		background: repeating-linear-gradient(
			0deg,
			var(--mac-black),
			var(--mac-black) 1px,
			var(--mac-white) 1px,
			var(--mac-white) 3px
		);
		padding: 4px;
		border: 1px solid var(--mac-black);
	}
	.header-title {
		background-color: var(--mac-white);
		color: var(--mac-black);
		padding: 2px 12px;
		font-weight: bold;
		border: 1px solid var(--mac-black);
		margin: 0 auto;
		letter-spacing: 1px;
	}

	/* Macintosh UI Buttons */
	.mac-btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 6px;
		font-family: inherit;
		font-size: 12pt;
		font-weight: bold;
		color: var(--mac-black);
		background-color: var(--mac-white);
		border: 2px solid var(--mac-black);
		box-shadow: 2px 2px 0px var(--mac-black);
		padding: 6px 12px;
		cursor: pointer;
		text-decoration: none;
		user-select: none;
		-webkit-user-select: none;
		transition: background-color 0.1s, color 0.1s;
		outline: none;
	}
	.mac-btn:hover {
		background-color: var(--mac-black);
		color: var(--mac-white);
	}
	.mac-btn:hover * {
		color: var(--mac-white);
	}
	.mac-btn:active {
		transform: translate(1px, 1px);
		box-shadow: 1px 1px 0px var(--mac-black);
	}
	.mac-btn-default {
		border: 3px double var(--mac-black);
		outline: 1px solid var(--mac-black);
		outline-offset: 1px;
	}
	.mac-btn-danger {
		border: 2px solid var(--mac-black);
	}
	.mac-btn-danger:hover {
		background-color: var(--mac-black);
		color: var(--mac-white);
	}

	/* Panels and Windows */
	.upload-panel {
		background-color: var(--mac-white);
		border: 2px solid var(--mac-black);
		box-shadow: 4px 4px 0px var(--mac-black);
		margin: 0 0 16px 0;
		padding: 0;
	}
	.upload-header {
		background: repeating-linear-gradient(
			0deg,
			var(--mac-black),
			var(--mac-black) 1px,
			var(--mac-white) 1px,
			var(--mac-white) 3px
		);
		padding: 4px 8px;
		border-bottom: 2px solid var(--mac-black);
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	.upload-header-title {
		background-color: var(--mac-white);
		color: var(--mac-black);
		padding: 2px 14px;
		font-weight: bold;
		border: 1px solid var(--mac-black);
		letter-spacing: 1px;
		font-size: 13pt;
	}
	.upload-close-box {
		width: 16px;
		height: 16px;
		border: 1px solid var(--mac-black);
		background-color: var(--mac-white);
		cursor: pointer;
		padding: 0;
		box-shadow: 1px 1px 0px var(--mac-black);
		display: inline-block;
	}
	.upload-close-box:hover {
		background-color: var(--mac-black);
	}
	.upload-body {
		padding: 16px;
	}

	/* Drop Zone & Upload Styles */
	.drop-zone {
		border: 2px dashed var(--mac-black);
		padding: 20px 14px;
		text-align: center;
		background-color: var(--mac-white);
		cursor: pointer;
		margin-bottom: 12px;
		user-select: none;
	}
	.drop-zone:hover {
		border-style: solid;
		background-color: #f6f6f6;
	}
	.drop-zone.dragover {
		background-color: var(--mac-black) !important;
		color: var(--mac-white) !important;
		border-style: solid;
	}
	.drop-zone.dragover * {
		color: var(--mac-white) !important;
	}
	.drop-zone-icon {
		font-size: 24pt;
		margin-bottom: 4px;
		display: block;
	}
	.drop-zone-title {
		font-size: 13pt;
		font-weight: bold;
	}
	.drop-zone-subtitle {
		font-size: 11pt;
		margin-top: 4px;
		opacity: 0.85;
	}
	.file-queue {
		margin: 12px 0;
		border: 2px solid var(--mac-black);
		background: var(--mac-white);
		box-shadow: 2px 2px 0px var(--mac-black);
		display: none;
		max-height: 160px;
		overflow-y: auto;
	}
	.file-queue-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 6px 10px;
		font-size: 12pt;
		border-bottom: 1px solid #ccc;
	}
	.file-queue-item:last-child {
		border-bottom: none;
	}
	.file-queue-name {
		font-weight: bold;
		word-break: break-all;
	}
	.file-queue-size {
		font-size: 11pt;
		color: #333;
		margin-left: 8px;
	}
	.file-queue-remove {
		cursor: pointer;
		font-weight: bold;
		border: 1px solid var(--mac-black);
		padding: 1px 6px;
		font-size: 10pt;
		margin-left: 10px;
		background: var(--mac-white);
	}
	.file-queue-remove:hover {
		background: var(--mac-black);
		color: var(--mac-white);
	}
	.upload-controls {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: center;
		margin-top: 12px;
	}
	.mac-checkbox-label {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 12pt;
		font-weight: bold;
		cursor: pointer;
		user-select: none;
	}
	.mac-checkbox-label input[type="checkbox"] {
		appearance: none;
		-webkit-appearance: none;
		width: 18px;
		height: 18px;
		border: 2px solid var(--mac-black);
		background: var(--mac-white);
		cursor: pointer;
		position: relative;
		margin: 0;
		outline: none;
	}
	.mac-checkbox-label input[type="checkbox"]:checked {
		background: var(--mac-black);
	}
	.mac-checkbox-label input[type="checkbox"]:checked::after {
		content: "✕";
		color: var(--mac-white);
		font-size: 12px;
		font-weight: bold;
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
	}
	.progress-bar-container {
		margin-top: 14px;
		border: 2px solid var(--mac-black);
		height: 26px;
		background: var(--mac-white);
		position: relative;
		overflow: hidden;
		display: none;
		box-shadow: 2px 2px 0px var(--mac-black);
	}
	.progress-bar-fill {
		height: 100%;
		background: repeating-linear-gradient(
			-45deg,
			var(--mac-black),
			var(--mac-black) 8px,
			var(--mac-white) 8px,
			var(--mac-white) 16px
		);
		background-size: 22.6px 22.6px;
		animation: mac-barber-pole 0.6s linear infinite;
		width: 0%;
	}
	@keyframes mac-barber-pole {
		0% { background-position: 0 0; }
		100% { background-position: 22.6px 0; }
	}
	.progress-bar-text {
		position: absolute;
		top: 0; left: 0; right: 0; bottom: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 11pt;
		font-weight: bold;
		background: rgba(255, 255, 255, 0.75);
		padding: 0 8px;
		width: fit-content;
		margin: auto;
		border: 1px solid var(--mac-black);
	}

	/* Alerts and Notices */
	.mac-alert {
		border: 2px solid var(--mac-black);
		box-shadow: 3px 3px 0px var(--mac-black);
		background: var(--mac-white);
		padding: 10px 14px;
		margin-bottom: 14px;
		font-size: 12pt;
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
	}
	.mac-alert-success {
		border-left: 8px solid var(--mac-black);
	}
	.mac-alert-error {
		background: var(--mac-black);
		color: var(--mac-white);
	}
	.mac-alert-error * {
		color: var(--mac-white);
	}
	.mac-alert-close {
		border: 1px solid currentColor;
		background: transparent;
		color: inherit;
		padding: 2px 8px;
		font-family: inherit;
		font-size: 11pt;
		font-weight: bold;
		cursor: pointer;
	}
	.mac-alert-close:hover {
		background: currentColor;
		color: var(--mac-white);
	}
	.mac-alert-error .mac-alert-close:hover {
		color: var(--mac-black);
		background: var(--mac-white);
	}

	/* Forms and Inputs */
	.mac-form-group {
		margin-bottom: 12px;
	}
	.mac-label {
		display: block;
		font-size: 12pt;
		font-weight: bold;
		margin-bottom: 4px;
		color: var(--mac-black);
	}
	.mac-input, .mac-textarea {
		width: 100%;
		border: 2px solid var(--mac-black);
		background-color: var(--mac-white);
		color: var(--mac-black);
		padding: 8px 10px;
		font-family: 'Geneva', 'Monaco', 'Chicago', 'Courier New', monospace;
		font-size: 12pt;
		box-shadow: 2px 2px 0px var(--mac-black);
		outline: none;
		box-sizing: border-box;
	}
	.mac-textarea {
		min-height: 90px;
		resize: vertical;
	}
	.mac-input:focus, .mac-textarea:focus {
		background-color: #f7f7f7;
		box-shadow: 3px 3px 0px var(--mac-black);
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
		gap: 20px;
		margin-top: 10px;
	}
	@media (max-width: 900px) {
		.public-gateway-grid {
			grid-template-columns: 1fr;
		}
	}

	/* Status Pills and Badges */
	.status-badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 3px 10px;
		font-size: 11pt;
		font-weight: bold;
		border: 2px solid var(--mac-black);
		box-shadow: 2px 2px 0px var(--mac-black);
		background-color: var(--mac-white);
	}
	.status-badge-active {
		background-color: var(--mac-black);
		color: var(--mac-white);
	}
	.status-badge-active * {
		color: var(--mac-white);
	}
	.code-box {
		background: #f4f4f4;
		border: 1px solid var(--mac-black);
		padding: 8px 12px;
		font-family: monospace;
		font-size: 11pt;
		word-break: break-all;
		user-select: all;
	}

	/* Tab Navigation */
	.mac-tabs {
		display: flex;
		gap: 6px;
		margin-bottom: 14px;
		border-bottom: 2px solid var(--mac-black);
		padding-bottom: 4px;
		flex-wrap: wrap;
	}
	.mac-tab-link {
		padding: 6px 14px;
		border: 2px solid var(--mac-black);
		background: var(--mac-white);
		font-size: 12pt;
		font-weight: bold;
		text-decoration: none;
		box-shadow: 2px 2px 0px var(--mac-black);
	}
	.mac-tab-link.active {
		background: var(--mac-black);
		color: var(--mac-white);
	}

	/* Table Layouts */
	.mac-table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 10px;
		border: 2px solid var(--mac-black);
	}
	.mac-table th {
		background: repeating-linear-gradient(0deg, var(--mac-black), var(--mac-black) 1px, var(--mac-white) 1px, var(--mac-white) 3px);
		padding: 6px;
		text-align: left;
		border-bottom: 2px solid var(--mac-black);
	}
	.mac-table th span {
		background: var(--mac-white);
		padding: 2px 8px;
		font-size: 11pt;
		border: 1px solid var(--mac-black);
	}
	.mac-table td {
		border: 1px solid var(--mac-black);
		padding: 8px;
		font-size: 11pt;
		background: var(--mac-white);
	}

	/* Modals */
	#blueprintModal, #editDefaultsModal {
		display: none;
		position: fixed;
		top: 0; left: 0; right: 0; bottom: 0;
		background: rgba(0, 0, 0, 0.6);
		z-index: 9999;
		overflow-y: auto;
		padding: 24px;
	}
	.modal-dialog {
		background: var(--mac-white);
		border: 3px solid var(--mac-black);
		box-shadow: 6px 6px 0px var(--mac-black);
		max-width: 900px;
		margin: 30px auto;
		padding: 0;
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
var defaultDestinations = <?php echo json_encode($default_destinations_array); ?>;

function addDestinationRow(url) {
	url = url || '';
	var container = $('destinationsContainer');
	if (!container) return;

	var row = document.createElement('div');
	row.className = 'destination-row';

	row.innerHTML = 
		'<div style="flex:1;">' +
			'<input type="text" name="destination_urls[]" class="mac-input dest-url-input" maxlength="255" placeholder="https://addressbar.net/contact" value="' + escapeHtml(url) + '" required autocomplete="off">' +
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
			addDestinationRow(u);
		});
	} else {
		addDestinationRow('');
		alert('No default destinations saved yet. Click "[ ⚙ Edit Defaults ]" to configure your distribution list.');
	}
}

// Dynamic Defaults Editor Rows
function addDefaultRow(url) {
	url = url || '';
	var container = $('defaultsEditorContainer');
	if (!container) return;

	var row = document.createElement('div');
	row.className = 'destination-row';
	row.innerHTML = 
		'<div style="flex:1;">' +
			'<input type="text" name="default_urls[]" class="mac-input" maxlength="255" placeholder="https://addressbar.net/contact" value="' + escapeHtml(url) + '" required>' +
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

var swRegistration = null;
if ('serviceWorker' in navigator && 'PushManager' in window) {
	window.addEventListener('load', function() {
		navigator.serviceWorker.register('sw.js')
			.then(function(reg) {
				swRegistration = reg;
				updatePushUI();
			})
			.catch(function(err) {
				console.warn('Service Worker registration error:', err);
			});
	});
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
				btn.innerText = isSubscribed ? '[ 🔔 Push: Active ]' : '[ 🔔 Enable Push ]';
			}
			if (statusBadge) {
				statusBadge.className = 'status-badge ' + (isSubscribed ? 'status-badge-active' : '');
				statusBadge.innerText = isSubscribed ? '● SUBSCRIBED IN THIS BROWSER' : '○ NOT SUBSCRIBED IN THIS BROWSER';
			}
			if (btnSettings) {
				btnSettings.innerText = isSubscribed ? '[ 🔕 Unsubscribe This Device ]' : '[ 🔔 Subscribe This Device ]';
			}
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
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'remove_push_subscription', endpoint: sub.endpoint })
					}).then(function() {
						updatePushUI();
						alert('Push notifications disabled for this device.');
					});
				});
			} else {
				// Subscribe
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
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({
								action: 'save_push_subscription',
								endpoint: newSub.endpoint,
								p256dh: p256dh,
								auth: auth
							})
						});
					})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						updatePushUI();
						alert('✓ Success! This device is now registered to receive instant push notifications.');
					})
					.catch(function(err) {
						console.error('Push subscription failed:', err);
						alert('Could not enable push notifications: ' + err.message);
					});
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
				addDestinationRow(u);
			});
		} else {
			addDestinationRow('https://addressbar.net/contact');
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

<?php if (!is_logged_in()): ?>
<!-- ============================================================== -->
<!-- PUBLIC VISITOR SCREEN (NOT LOGGED IN)                          -->
<!-- Login Form + Inter-Site Contact Info + Blueprint Link          -->
<!-- ============================================================== -->
<div id="directoryPath">
	<span>addr.bar System Desktop: Federated Contact &amp; Mail Gateway (<?php echo htmlspecialchars($current_domain); ?>)</span>
	<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
		<button type="button" id="pwaInstallBtnPublic" class="mac-btn" style="display:none; font-size:10pt; padding:3px 8px;" onclick="installPWA()">[ 📲 Install App ]</button>
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
			<span style="font-size:11pt; font-weight:bold;">[ 1 User / Domain ]</span>
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
			<p style="font-size:11pt; margin-top:0;">
				Mail dispatch and communication controls are restricted to the single user for domain <strong><?php echo htmlspecialchars($current_domain); ?></strong><?php echo !empty($domain_user['username']) ? ' (<strong>' . htmlspecialchars($domain_user['username']) . '</strong>)' : ''; ?>. Enter your password to log in.
			</p>
			<?php if ($visitor_locked): ?>
				<div id="loginLockBanner" style="border:2px solid var(--mac-black); background:#000; color:#fff; padding:10px; margin-bottom:12px; font-size:11pt; font-weight:bold;">
					<div style="color:#fff;">⚠ Maximum of <?php echo MAX_LOGIN_ATTEMPTS_PER_IP; ?> login attempts reached for IP address <?php echo htmlspecialchars($visitor_ip); ?>.</div>
					<div style="color:#fff; margin-top:6px;">
						⏱ 6-Hour Lock Timer: <span id="lockCountdownTimer" data-seconds="<?php echo (int)$visitor_seconds_left; ?>" style="color:#fff; text-decoration:underline;"><?php echo htmlspecialchars(format_lock_timer($visitor_seconds_left)); ?></span> remaining
						<?php if ($visitor_unlock_at): ?>
							<span style="color:#fff; font-size:10pt; font-weight:normal;">(Unlocks at <?php echo htmlspecialchars($visitor_unlock_at); ?>)</span>
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
								banner.style.backgroundColor = '#ffffff';
								banner.style.color = '#000000';
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
				<div style="border:2px solid var(--mac-black); background:#f4f4f4; padding:8px 10px; margin-bottom:12px; font-size:10pt; font-weight:bold;">
					Notice: <?php echo $visitor_remaining; ?> of <?php echo MAX_LOGIN_ATTEMPTS_PER_IP; ?> login attempt(s) remaining for IP address <?php echo htmlspecialchars($visitor_ip); ?> (6-hour lock on 3 failed attempts).
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
				<span style="font-size:12pt; font-weight:bold;">Host Correspondence Channel:</span>
				<?php if ($comms_enabled): ?>
					<span class="status-badge status-badge-active">🟢 ACCEPTING INCOMING MESSAGES</span>
				<?php else: ?>
					<span class="status-badge" style="background:#000; color:#fff;">🔴 COMMUNICATIONS DISABLED BY HOST</span>
				<?php endif; ?>
			</div>

			<p style="font-size:11pt; line-height:1.5;">
				This website operates the sovereign <strong>addr.bar Federated Messaging Protocol</strong>. Visitors and external server hosts can send a direct, authenticated message to this website provided they also operate a website running this same form.
			</p>

			<div style="border:2px solid var(--mac-black); padding:10px; margin-bottom:12px; background:#fafafa;">
				<div style="font-size:11pt; font-weight:bold; margin-bottom:4px;">How to Send a Message to this Website:</div>
				<ol style="margin:4px 0 8px 18px; padding:0; font-size:10pt; line-height:1.5;">
					<li>Visit your <strong>own website</strong> that operates this script.</li>
					<li>Log in to your website and open the <strong>Mail Form</strong>.</li>
					<li>Enter this website's canonical endpoint into the <em>Destination Website URLs</em> field:</li>
				</ol>
				<div class="code-box" id="contactEndpointBox"><?php echo htmlspecialchars($canonical_url); ?></div>
				<div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" class="mac-btn" onclick="copyToClipboard('<?php echo addslashes($canonical_url); ?>', this)">
						[ 📋 Copy Destination URL ]
					</button>
					<button type="button" class="mac-btn" onclick="toggleBlueprintModal(true)">
						[ 📜 View Protocol Blueprint ]
					</button>
				</div>
			</div>

			<div style="border:2px solid var(--mac-black); padding:10px; margin-bottom:12px; background:#f4f4f4;">
				<div style="font-size:11pt; font-weight:bold; margin-bottom:4px;">Install on Your Website via GitHub:</div>
				<p style="font-size:10pt; line-height:1.4; margin:4px 0 8px 0;">
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

			<div style="font-size:10pt; line-height:1.4; border-top:1px solid #ccc; padding-top:8px;">
				<strong>🔒 Direct HTTP Stream Transmission:</strong> When a message is dispatched, the two PHP files communicate server-to-server using PHP's <code>stream_context_create()</code> and <code>file_get_contents()</code> HTTP POST requests—no Reverse DNS (PTR) configuration is required.
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
	echo "    <button type='button' id='pwaInstallBtn' class='mac-btn' style='display:none; font-size:10pt; padding:3px 8px;' onclick='installPWA()'>[ 📲 Install App ]</button>";
	echo "    <button type='button' id='pushNotifyBtn' class='mac-btn' style='font-size:10pt; padding:3px 8px;' onclick='togglePushSubscription()'>[ 🔔 Push Notifications ]</button>";
	echo "    <span style='font-size:11pt;'>[ 👤 Host: <strong>" . htmlspecialchars(get_logged_in_username()) . "</strong> ]</span>";
	echo "    <a href='index.php?action=logout' class='mac-btn' style='font-size:10pt; padding:3px 8px;'>[ 🚪 Log Out ]</a>";
	echo "  </div>";
	echo "</div>";

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
		echo "    <p style='font-size:11pt; margin-top:0;'>";
		echo "      Transmit an authenticated message to one or more peer websites (such as <code>https://addressbar.net/contact</code>) via <code>stream_context_create()</code> HTTP POST requests. No Reverse DNS is required. Sent copies are preserved in your inbox.";
		echo "    </p>";
		echo "    <form action='index.php' method='POST'>";
		echo "      <input type='hidden' name='action' value='send_mail'>";

		// Destination URLs Section
		echo "      <div class='mac-form-group'>";
		echo "        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:8px;'>";
		echo "          <label class='mac-label' style='margin-bottom:0;'>Destination Website URLs: *</label>";
		echo "          <div style='display:flex; gap:8px;'>";
		echo "            <button type='button' class='mac-btn' onclick='loadDefaultDestinations()' title='Set form destinations with one click'>";
		echo "              [ ⚡ Load Default Destinations ]";
		echo "            </button>";
		echo "            <button type='button' class='mac-btn' onclick='toggleEditDefaultsModal(true)' title='Edit frequently used distribution lists'>";
		echo "              [ ⚙ Edit Defaults ]";
		echo "            </button>";
		echo "          </div>";
		echo "        </div>";
		echo "        <div style='display:flex; gap:10px; font-size:10pt; font-weight:bold; margin-bottom:4px; opacity:0.8;'>";
		echo "          <div style='flex:1;'>Website Endpoint URL (e.g. https://addressbar.net/contact)</div>";
		echo "          <div style='width:90px;'>Action</div>";
		echo "        </div>";
		echo "        <div id='destinationsContainer'>";
		// Render default 1 option or preserved options
		echo "        </div>";
		echo "        <div style='margin-top:8px;'>";
		echo "          <button type='button' class='mac-btn' onclick='addDestinationRow()'>";
		echo "            [ + Add Another Destination URL ]";
		echo "          </button>";
		echo "        </div>";
		echo "      </div>";

		echo "      <div style='display:grid; grid-template-columns:1fr 1fr; gap:12px;'>";
		echo "        <div class='mac-form-group'>";
		echo "          <label for='senderNameInput' class='mac-label'>Your Name / Handle (max 100 chars):</label>";
		echo "          <input type='text' id='senderNameInput' name='sender_name' class='mac-input' maxlength='100' value='" . htmlspecialchars(get_logged_in_username()) . "'>";
		echo "        </div>";
		echo "        <div class='mac-form-group'>";
		echo "          <label for='senderEmailInput' class='mac-label'>Your Contact Email (optional, max 255 chars):</label>";
		echo "          <input type='email' id='senderEmailInput' name='sender_email' class='mac-input' maxlength='255' value='" . htmlspecialchars($configured_email) . "' placeholder='host@yourdomain.com'>";
		echo "        </div>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <label for='subjectInput' class='mac-label'>Subject (max 255 chars): *</label>";
		echo "        <input type='text' id='subjectInput' name='subject' class='mac-input' maxlength='255' placeholder='Inter-site collaboration inquiry' required>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; margin-bottom:4px;'>";
		echo "          <label for='messageInput' class='mac-label' style='margin-bottom:0;'>Message Body (max 15,000 chars): *</label>";
		echo "          <span id='messageLengthCounter' style='font-size:10pt; opacity:0.8;'>0 / 15,000 chars (0 / 65,535 UTF-8 bytes)</span>";
		echo "        </div>";
		echo "        <textarea id='messageInput' name='message' class='mac-textarea' maxlength='15000' oninput='updateMessageLengthCounter()' placeholder='Write your message to the destination site host...' required></textarea>";
		echo "      </div>";

		echo "      <div class='mac-form-group'>";
		echo "        <label class='mac-checkbox-label'>";
		echo "          <input type='checkbox' name='include_all_recipients' value='1' checked>";
		echo "          Include all destination websites in message information (Visible Distribution List)";
		echo "        </label>";
		echo "      </div>";

		echo "      <div class='mac-form-group' style='background:#f6f6f6; border:1px solid #000; padding:8px 12px; font-size:10pt;'>";
		echo "        <strong>Sender Origin:</strong> Automatically declared as <code>" . htmlspecialchars($canonical_url) . "</code>.";
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
			echo "      <button type='submit' class='mac-btn' style='font-size:9pt; padding:2px 8px;'>[ ✓ Mark All Read ({$unread_count}) ]</button>";
			echo "    </form>";
		}
		echo "  </div>";
		echo "  <div class='upload-body'>";
		if (empty($incoming_messages)) {
			echo "<p style='font-size:12pt; text-align:center; padding:20px; font-style:italic;'>[ No messages in inbox yet ]</p>";
		} else {
			foreach ($incoming_messages as $msg) {
				$is_sent = !empty($msg['is_sent_copy']);
				$is_delayed = !empty($msg['is_delayed']);
				echo "<div style='border:2px solid " . ($is_delayed ? "#b00" : "var(--mac-black)") . "; box-shadow:3px 3px 0px " . ($is_delayed ? "#b00" : "var(--mac-black)") . "; margin-bottom:14px; background:var(--mac-white);'>";
				echo "  <div style='background:" . ($is_delayed ? "#fff2f2" : "#f2f2f2") . "; border-bottom:1px solid #000; padding:8px 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
				echo "    <div>";
				echo "      <strong style='font-size:13pt;'>" . htmlspecialchars($msg['subject']) . "</strong>";
				if ($is_delayed) {
					echo "      <span style='font-size:10pt; margin-left:10px;'>Target Server: <strong>" . htmlspecialchars($msg['destination_url']) . "</strong></span>";
				} elseif ($is_sent) {
					echo "      <span style='font-size:10pt; margin-left:10px;'>Sent by: <strong>" . htmlspecialchars($msg['sender_name']) . "</strong></span>";
				} else {
					echo "      <span style='font-size:10pt; margin-left:10px;'>From: <strong>" . htmlspecialchars($msg['sender_name'] ?: $msg['sender_host']) . "</strong> (" . htmlspecialchars($msg['sender_url']) . ")</span>";
				}
				echo "    </div>";
				echo "    <div style='display:flex; align-items:center; gap:8px;'>";
				if ($is_delayed) {
					echo "      <span class='status-badge' style='background:#b00; color:#fff; font-size:9pt;'>⏳ DELAYED (RATE LIMITED)</span>";
				} elseif ($is_sent) {
					echo "      <span class='status-badge' style='background:#000; color:#fff; font-size:9pt;'>📤 SENT COPY</span>";
				} else {
					echo "      <span class='status-badge status-badge-active' style='font-size:9pt;'>✓ " . htmlspecialchars($msg['dns_verification_status']) . "</span>";
				}
				echo "      <span style='font-size:10pt;'>" . htmlspecialchars($msg['received_at']) . "</span>";
				echo "    </div>";
				echo "  </div>";

				// Delayed Message Info Bar
				if ($is_delayed) {
					echo "<div style='background:#ffebeb; border-bottom:1px solid #e0b0b0; padding:8px 12px; font-size:10pt; color:#900;'>";
					echo "  <div><strong>⚠ Rate Limit Delay Notice:</strong> This message was not sent because the destination website daily message limit was reached.</div>";
					if (!empty($msg['retry_after'])) {
						echo "  <div style='margin-top:3px;'><strong>Eligible to send after:</strong> <code style='font-weight:bold; background:#fff; padding:2px 6px; border:1px solid #ccc;'>" . htmlspecialchars($msg['retry_after']) . "</code></div>";
					}
					echo "</div>";
				}

				// Metadata Bar for visible distribution list
				if (!empty($msg['all_recipients'])) {
					echo "<div style='background:#fafafa; border-bottom:1px solid #ddd; padding:6px 12px; font-size:10pt;'>";
					echo "<div><strong>Visible Distribution Sources:</strong> " . htmlspecialchars($msg['all_recipients']) . "</div>";
					echo "</div>";
				}

				echo "  <div style='padding:12px; font-size:11pt; line-height:1.5; white-space:pre-wrap; border-bottom:1px solid #eee;'>" . htmlspecialchars($msg['message_body']) . "</div>";
				echo "  <div style='background:#fafafa; padding:6px 12px; display:flex; justify-content:space-between; align-items:center; font-size:10pt; flex-wrap:wrap; gap:8px;'>";
				echo "    <div>";
				if ($is_delayed) {
					echo "      <strong>Status:</strong> Saved in your inbox as delayed. If not deleted, you can send it after the delay.";
				} else {
					echo "      <strong>Client IP:</strong> <code>" . htmlspecialchars($msg['sender_ip']) . "</code> | <strong>Delivery:</strong> <code>" . htmlspecialchars($msg['dns_verification_status'] ?: 'STREAM_CONTEXT_POST') . "</code>";
				}
				echo "    </div>";
				echo "    <div style='display:flex; gap:8px;'>";
				if ($is_delayed) {
					echo "      <form action='index.php' method='POST' style='display:inline;'>";
					echo "        <input type='hidden' name='action' value='send_delayed_message'>";
					echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
					echo "        <button type='submit' class='mac-btn mac-btn-default' style='font-size:10pt; padding:2px 8px;'>[ 🚀 Send Message Now ]</button>";
					echo "      </form>";
					echo "      <form action='index.php' method='POST' style='display:inline;' onsubmit=\"return confirm('Delete delayed message? It will not be sent.');\">";
					echo "        <input type='hidden' name='action' value='delete_message'>";
					echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
					echo "        <button type='submit' class='mac-btn' style='font-size:10pt; padding:2px 8px;'>[ ✕ Delete ]</button>";
					echo "      </form>";
				} else {
					if (!$is_sent) {
						$reply_subject = (stripos($msg['subject'], 'Re:') === 0) ? $msg['subject'] : ('Re: ' . $msg['subject']);
						$reply_href = 'index.php?tab=mail&reply_url=' . urlencode($msg['sender_url']) . '&reply_subject=' . urlencode($reply_subject);
						echo "      <a href='" . htmlspecialchars($reply_href, ENT_QUOTES) . "' class='mac-btn mac-btn-default' style='font-size:10pt; padding:2px 8px;'>[ ↩ Reply ]</a>";
						echo "      <form action='index.php' method='POST' style='display:inline;'>";
						echo "        <input type='hidden' name='action' value='toggle_read'>";
						echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
						echo "        <button type='submit' class='mac-btn' style='font-size:10pt; padding:2px 8px;'>" . (empty($msg['is_read']) ? '[ ✓ Mark Read ]' : '[ ○ Mark Unread ]') . "</button>";
						echo "      </form>";
						echo "      <form action='index.php' method='POST' style='display:inline;' onsubmit=\"return confirm('Block all contact from " . htmlspecialchars($msg['sender_host']) . "?');\">";
						echo "        <input type='hidden' name='action' value='block_site'>";
						echo "        <input type='hidden' name='target' value='" . htmlspecialchars($msg['sender_host']) . "'>";
						echo "        <button type='submit' class='mac-btn mac-btn-danger' style='font-size:10pt; padding:2px 8px;'>[ 🚫 Block Site ]</button>";
						echo "      </form>";
					}
					echo "      <form action='index.php' method='POST' style='display:inline;' onsubmit=\"return confirm('Delete message?');\">";
					echo "        <input type='hidden' name='action' value='delete_message'>";
					echo "        <input type='hidden' name='message_id' value='" . (int)$msg['id'] . "'>";
					echo "        <button type='submit' class='mac-btn' style='font-size:10pt; padding:2px 8px;'>[ ✕ Delete ]</button>";
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
			echo "<p style='font-size:12pt; text-align:center; padding:20px; font-style:italic;'>[ No messages sent yet ]</p>";
		} else {
			echo "<table class='mac-table'>";
			echo "  <thead><tr><th><span>Destination Website URLs</span></th><th><span>Subject</span></th><th><span>Status</span></th><th><span>Sent At</span></th></tr></thead>";
			echo "  <tbody>";
			foreach ($sent_messages as $s) {
				echo "<tr>";
				echo "  <td>";
				echo "    <div style='font-weight:bold; word-break:break-all;'>" . htmlspecialchars($s['destination_url']) . "</div>";
				if (!empty($s['all_recipients'])) {
					echo "    <div style='font-size:9pt; color:#444; margin-top:2px;'>Distribution: " . htmlspecialchars($s['all_recipients']) . "</div>";
				}
				echo "  </td>";
				echo "  <td><strong>" . htmlspecialchars($s['subject']) . "</strong></td>";
				echo "  <td>";
				if ($s['delivery_status'] === 'DELIVERED') {
					echo "<span class='status-badge status-badge-active' style='font-size:9pt;'>✓ DELIVERED</span>";
				} elseif ($s['delivery_status'] === 'DELAYED') {
					echo "<span class='status-badge' style='background:#b00; color:#fff; font-size:9pt;'>⏳ DELAYED</span>";
				} elseif ($s['delivery_status'] === 'PARTIALLY_DELIVERED') {
					echo "<span class='status-badge' style='background:#444; color:#fff; font-size:9pt;'>⚠ PARTIAL</span>";
				} else {
					echo "<span class='status-badge' style='background:#000; color:#fff; font-size:9pt;'>✕ " . htmlspecialchars($s['delivery_status']) . "</span>";
				}
				echo "  <div style='font-size:9pt; margin-top:3px; opacity:0.8; white-space:pre-wrap;'>" . htmlspecialchars($s['response_message']) . "</div>";
				echo "  </td>";
				echo "  <td style='white-space:nowrap; font-size:10pt;'>" . htmlspecialchars($s['sent_at']) . "</td>";
				echo "</tr>";
			}
			echo "  </tbody>";
			echo "</table>";
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
		$push_notifications_enabled = (get_setting('push_notifications_enabled', '1') === '1');
		echo "    <div style='border:2px solid var(--mac-black); padding:16px; margin-bottom:18px; background:var(--mac-white); box-shadow:3px 3px 0px var(--mac-black);'>";
		echo "      <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
		echo "        <h3 style='margin:0; font-size:14pt;'>Browser &amp; PWA Push Notifications</h3>";
		echo "        <span id='pushStatusBadge' class='status-badge " . ($push_notifications_enabled ? 'status-badge-active' : '') . "'>";
		echo "          " . ($push_notifications_enabled ? '● PUSH NOTIFICATIONS ENABLED' : '○ PUSH NOTIFICATIONS DISABLED');
		echo "        </span>";
		echo "      </div>";
		echo "      <p style='font-size:11pt; line-height:1.4;'>";
		echo "        Receive instant OS-level push notifications whenever peer websites send a message to <strong>" . htmlspecialchars($host_domain) . "</strong>. Notifications arrive even when this tab is closed, in Chrome, Safari (macOS &amp; iOS 16.4+), Firefox, and Edge. Zero email, zero SMTP, and zero third-party accounts.";
		echo "      </p>";
		echo "      <div style='display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px;'>";
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
			echo "          <button type='submit' class='mac-btn mac-btn-danger' style='font-size:11pt; padding:6px 14px;'>";
			echo "            [ ⚪ Click to TOGGLE OFF Push Alerts ]";
			echo "          </button>";
		} else {
			echo "          <button type='submit' class='mac-btn mac-btn-default' style='font-size:11pt; padding:6px 14px;'>";
			echo "            [ 🟢 Click to ENABLE Push Alerts ]";
			echo "          </button>";
		}
		echo "        </form>";
		echo "      </div>";
		echo "    </div>";

		// Global Toggle Section
		echo "    <div style='border:2px solid var(--mac-black); padding:16px; margin-bottom:18px; background:var(--mac-white); box-shadow:3px 3px 0px var(--mac-black);'>";
		echo "      <h3 style='margin-top:0; font-size:14pt;'>Global Communication Toggle</h3>";
		echo "      <p style='font-size:11pt; line-height:1.4;'>";
		echo "        If you do not wish to receive communications from any peer websites, you can toggle this option off. When turned off, all incoming federated message attempts will be rejected.";
		echo "      </p>";
		echo "      <div style='display:flex; align-items:center; gap:16px; margin-top:12px;'>";
		echo "        <form action='index.php' method='POST'>";
		echo "          <input type='hidden' name='action' value='toggle_communications'>";
		if ($comms_enabled) {
			echo "          <button type='submit' class='mac-btn mac-btn-danger' style='font-size:12pt; padding:8px 16px;'>";
			echo "            [ 🔴 Click to TOGGLE OFF All Incoming Communications ]";
			echo "          </button>";
		} else {
			echo "          <button type='submit' class='mac-btn mac-btn-default' style='font-size:12pt; padding:8px 16px;'>";
			echo "            [ 🟢 Click to ENABLE Incoming Communications ]";
			echo "          </button>";
		}
		echo "        </form>";
		echo "        <span class='status-badge " . ($comms_enabled ? 'status-badge-active' : '') . "'>";
		echo "          Status: " . ($comms_enabled ? 'ACCEPTING COMMUNICATIONS' : 'COMMUNICATIONS DISABLED');
		echo "        </span>";
		echo "      </div>";
		echo "    </div>";

		// Website Message Rate Limiting Section
		$rate_limit_enabled = (get_setting('rate_limiting_enabled', '1') === '1');
		$daily_limit = (int)get_setting('rate_limit_per_website_per_day', '10');
		if ($daily_limit < 1) $daily_limit = 10;

		echo "    <div style='border:2px solid var(--mac-black); padding:16px; margin-bottom:18px; background:var(--mac-white); box-shadow:3px 3px 0px var(--mac-black);'>";
		echo "      <h3 style='margin-top:0; font-size:14pt;'>Website Message Rate Limiting & Delay Controls</h3>";
		echo "      <p style='font-size:11pt; line-height:1.4;'>";
		echo "        Limit incoming messages from remote websites (default is 10 messages per day per website). When a user limits messages from a website, the limit applies to all users across this website. When the next message will reach the limit, a warning is returned. When the limit is reached, future messages are delayed. If a limit causes a message to not be sent, it is stored in the sender's inbox as delayed and can be sent after the delay.";
		echo "      </p>";
		echo "      <form action='index.php' method='POST' style='display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-top:12px;'>";
		echo "        <input type='hidden' name='action' value='update_rate_limit'>";
		echo "        <div>";
		echo "          <label class='mac-label'>Daily Message Limit Per Website:</label>";
		echo "          <input type='number' name='rate_limit_per_website_per_day' class='mac-input' style='width:120px;' value='{$daily_limit}' min='1' max='1000' required>";
		echo "        </div>";
		echo "        <div style='padding-bottom:8px;'>";
		echo "          <label class='mac-checkbox-label'>";
		echo "            <input type='checkbox' name='rate_limiting_enabled' value='1' " . ($rate_limit_enabled ? 'checked' : '') . ">";
		echo "            Enforce daily rate limit on remote websites";
		echo "          </label>";
		echo "        </div>";
		echo "        <button type='submit' class='mac-btn mac-btn-default' style='margin-bottom:4px;'>[ Save Limit Settings ]</button>";
		echo "        <span class='status-badge " . ($rate_limit_enabled ? 'status-badge-active' : '') . "' style='margin-bottom:6px;'>";
		echo "          " . ($rate_limit_enabled ? "LIMIT ACTIVE: {$daily_limit} MSG/DAY" : "LIMIT DISABLED");
		echo "        </span>";
		echo "      </form>";
		echo "    </div>";

		// Specific Site Blocklist Section
		echo "    <div style='border:2px solid var(--mac-black); padding:16px; background:var(--mac-white); box-shadow:3px 3px 0px var(--mac-black);'>";
		echo "      <h3 style='margin-top:0; font-size:14pt;'>Toggle Off Contact from Specific Sites (Blocklist)</h3>";
		echo "      <p style='font-size:11pt;'>";
		echo "        Block or disable incoming communications from specific websites, domain names, or IP addresses:";
		echo "      </p>";
		echo "      <form action='index.php' method='POST' style='display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;'>";
		echo "        <input type='hidden' name='action' value='block_site'>";
		echo "        <div style='flex:1; min-width:220px;'>";
		echo "          <label class='mac-label'>Domain or IP Address to Block (max 255 chars): *</label>";
		echo "          <input type='text' name='target' class='mac-input' maxlength='255' placeholder='spam-site.com or 192.168.1.5' required>";
		echo "        </div>";
		echo "        <div style='flex:1; min-width:200px;'>";
		echo "          <label class='mac-label'>Reason (optional, max 255 chars):</label>";
		echo "          <input type='text' name='reason' class='mac-input' maxlength='255' placeholder='Spam, unsolicited contact'>";
		echo "        </div>";
		echo "        <button type='submit' class='mac-btn mac-btn-default'>[ + Block Site ]</button>";
		echo "      </form>";

		if (empty($blocked_sites_list)) {
			echo "      <p style='font-size:11pt; font-style:italic; padding:8px 0;'>No specific sites currently blocked.</p>";
		} else {
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
				echo "      <button type='submit' class='mac-btn' style='font-size:10pt; padding:2px 8px;'>[ Unblock / Allow ]</button>";
				echo "    </form>";
				echo "  </td>";
				echo "</tr>";
			}
			echo "        </tbody>";
			echo "      </table>";
		}
		echo "    </div>";

		// Login Attempt Protection Section (Max 3 Attempts Per IP, 6-Hour Lock Timer)
		echo "    <div style='border:2px solid var(--mac-black); padding:16px; margin-top:18px; background:var(--mac-white); box-shadow:3px 3px 0px var(--mac-black);'>";
		echo "      <div style='display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;'>";
		echo "        <h3 style='margin:0; font-size:14pt;'>IP Login Attempt Protection (Max " . MAX_LOGIN_ATTEMPTS_PER_IP . " Attempts Per IP &mdash; 6-Hour Lock Timer)</h3>";
		if (!empty($login_attempts_list)) {
			echo "        <form action='index.php' method='POST' style='margin:0;'>";
			echo "          <input type='hidden' name='action' value='clear_login_attempts'>";
			echo "          <button type='submit' class='mac-btn' style='font-size:10pt; padding:3px 10px;'>[ Clear All IP Lockouts ]</button>";
			echo "        </form>";
		}
		echo "      </div>";
		echo "      <p style='font-size:11pt;'>";
		echo "        Only <strong>" . MAX_LOGIN_ATTEMPTS_PER_IP . " failed login attempts</strong> are permitted per IP address. Once an IP reaches " . MAX_LOGIN_ATTEMPTS_PER_IP . " failed attempts, login from that IP is locked for <strong>6 hours</strong>, after which the client can try to log in again.";
		echo "      </p>";
		if (empty($login_attempts_list)) {
			echo "      <p style='font-size:11pt; font-style:italic; padding:4px 0;'>No failed login attempts currently recorded within the last 6 hours.</p>";
		} else {
			echo "      <table class='mac-table'>";
			echo "        <thead><tr><th><span>IP Address</span></th><th><span>Failed Attempts</span></th><th><span>Status / 6-Hour Timer</span></th><th><span>Unlocks At</span></th><th><span>Actions</span></th></tr></thead>";
			echo "        <tbody>";
			foreach ($login_attempts_list as $la) {
				$cnt = (int)$la['attempt_count'];
				$sec_left = (int)($la['seconds_remaining'] ?? 0);
				$is_locked_ip = ($cnt >= MAX_LOGIN_ATTEMPTS_PER_IP && $sec_left > 0);
				echo "<tr>";
				echo "  <td><code><strong>" . htmlspecialchars($la['ip_address']) . "</strong></code></td>";
				echo "  <td>" . $cnt . " / " . MAX_LOGIN_ATTEMPTS_PER_IP . "</td>";
				echo "  <td>" . ($is_locked_ip ? "<span class='status-badge' style='background:#000; color:#fff; font-size:9pt;'>🔒 LOCKED (" . htmlspecialchars(format_lock_timer($sec_left)) . " left)</span>" : "<span class='status-badge' style='font-size:9pt;'>" . (MAX_LOGIN_ATTEMPTS_PER_IP - $cnt) . " Attempt(s) Left</span>") . "</td>";
				echo "  <td>" . htmlspecialchars($la['unlock_at'] ?? $la['last_attempt']) . "</td>";
				echo "  <td>";
				echo "    <form action='index.php' method='POST' style='display:inline;'>";
				echo "      <input type='hidden' name='action' value='clear_login_attempts'>";
				echo "      <input type='hidden' name='ip_address' value='" . htmlspecialchars($la['ip_address']) . "'>";
				echo "      <button type='submit' class='mac-btn' style='font-size:10pt; padding:2px 8px;'>[ Reset / Unlock IP ]</button>";
				echo "    </form>";
				echo "  </td>";
				echo "</tr>";
			}
			echo "        </tbody>";
			echo "      </table>";
		}
		echo "    </div>";

		// Command-Line Server Installation via GitHub Section
		echo "    <div style='border:2px solid var(--mac-black); padding:16px; margin-top:18px; background:var(--mac-white); box-shadow:3px 3px 0px var(--mac-black);'>";
		echo "      <h3 style='margin-top:0; font-size:14pt;'>Command-Line Server Installation via GitHub</h3>";
		echo "      <p style='font-size:11pt; line-height:1.4;'>";
		echo "        Deploy this federated correspondence suite to another server or website directly from GitHub using the command line:";
		echo "      </p>";
		echo "      <div class='code-box' style='margin:10px 0;'>git clone https://github.com/addressbar-net/contact.git &amp;&amp; cd contact &amp;&amp; sudo ./install.sh</div>";
		echo "      <div style='display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;'>";
		echo "        <button type='button' class='mac-btn mac-btn-default' style='font-size:11pt; padding:6px 14px;' onclick=\"copyToClipboard('git clone https://github.com/addressbar-net/contact.git && cd contact && sudo ./install.sh', this)\">";
		echo "          [ 📋 Copy Git Install Command ]";
		echo "        </button>";
		echo "        <a href='https://github.com/addressbar-net/contact' target='_blank' rel='noopener noreferrer' class='mac-btn' style='font-size:11pt; padding:6px 14px;'>";
		echo "          [ 🌐 View GitHub Repository ]";
		echo "        </a>";
		echo "      </div>";
		echo "      <div style='font-size:10pt; margin-top:10px; padding:8px; background:#f4f4f4; border:1px solid #ccc;'>";
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
		<div class="upload-body" style="max-height:80vh; overflow-y:auto; font-size:11pt; line-height:1.5;">
			<div style="background:#f4f4f4; border:2px solid var(--mac-black); padding:12px; margin-bottom:16px;">
				<div style="font-size:11pt; font-weight:bold; margin-bottom:4px;">Ready to Deploy? Install via GitHub from the Command Line:</div>
				<p style="font-size:10pt; line-height:1.4; margin:4px 0 8px 0;">
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

			<h3 style="margin-top:0; font-size:14pt;">1. Architectural Blueprint Overview</h3>
			<p>
				The addr.bar Federated Mail Protocol enables sovereign, decentralized communication between independent websites running this PHP directory script. No centralized middleman or third-party email server is required.
			</p>

			<h3 style="font-size:13pt; margin-top:16px;">2. Key Features</h3>
			<ul style="margin-left:20px; line-height:1.6;">
				<li><strong>Single User Per Domain:</strong> Each domain has a single sovereign host account that manages the directory and receives all incoming correspondence.</li>
				<li><strong>Multiple Destination URLs:</strong> Senders can specify one or more destination website URLs in the mail form.</li>
				<li><strong>Single Outbox Storage:</strong> When a message is sent to multiple destination websites, one consolidated message record is saved in the outbox.</li>
				<li><strong>Inbox Storage for Sender:</strong> A copy of all sent dispatches is automatically stored in the sender's own inbox for complete record-keeping and conversation review.</li>
				<li><strong>Website Message Rate Limiting & Delay:</strong> Remote websites can be rate-limited (default 10 messages/day per website). A warning reply is issued when the next message will reach the limit. When the limit is reached, future messages are delayed. If a message cannot be sent due to the limit, it is saved in the sender's inbox as delayed and can be sent after the delay.</li>
				<li><strong>Default Messaging Form:</strong> Frequently used destination websites can be saved and loaded into the form with a single button click.</li>
				<li><strong>Visible Distribution List:</strong> Senders can optionally include the full destination website list in the message header.</li>
			</ul>

			<h3 style="font-size:13pt; margin-top:16px;">3. Communication Workflow</h3>
			<ol style="margin-left:20px; line-height:1.6;">
				<li><strong>Form Composition:</strong> A host visits their own website, logs in, and fills the Mail Form with Destination Website URLs (e.g., <code>https://addressbar.net/contact</code>), subject, and message.</li>
				<li><strong>Direct HTTP Stream Transmission:</strong> The sender website's PHP script initiates a server-to-server HTTP POST request using <code>stream_context_create()</code> and <code>file_get_contents()</code> to the destination website's endpoint—without requiring Reverse DNS (PTR) records.</li>
				<li><strong>Host Toggle Check:</strong> The receiving server verifies that incoming communications are currently enabled by the host.</li>
				<li><strong>Site Blocklist Evaluation:</strong> The receiving server checks its SQLite <code>blocked_sites</code> table to ensure the sender domain and IP are not blocked.</li>
				<li><strong>Inbox Delivery:</strong> Accepted messages are recorded in SQLite (<code>incoming_messages</code> table) for the domain's host.</li>
			</ol>

			<h3 style="font-size:13pt; margin-top:16px;">4. Protocol Payload Schema</h3>
			<div class="code-box" style="white-space:pre;">POST /contact/index.php HTTP/1.1
Content-Type: application/x-www-form-urlencoded
User-Agent: addr.bar-Federated-Mail/2.0

action=inter_site_mail
&sender_url=https://addressbar.net/contact/index.php
&sender_name=SenderHost
&sender_email=host@addressbar.net
&all_recipients=https://addressbar.net/contact; https://peer-b.com/contact
&subject=Federated+Handshake
&message=Hello+from+our+server+to+yours!
&timestamp=1729141200</div>

			<h3 style="font-size:13pt; margin-top:16px;">5. Verification Response Format</h3>
			<div class="code-box" style="white-space:pre;">{
  "success": true,
  "message_id": 42,
  "verification": "STREAM_CONTEXT_POST",
  "note": "Message received via stream_context_create() HTTP POST and accepted into host inbox."
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
			<span class="upload-header-title">EDIT DEFAULT MESSAGING FORM DESTINATIONS</span>
			<button type="button" class="upload-close-box" onclick="toggleEditDefaultsModal(false)" title="Close Window"></button>
		</div>
		<div class="upload-body" style="font-size:11pt; line-height:1.5;">
			<p style="margin-top:0;">
				Configure default destination websites. These can be populated into your mail form with a single click of <strong>[ ⚡ Load Default Destinations ]</strong>.
			</p>
			<form action="index.php" method="POST">
				<input type="hidden" name="action" value="save_default_destinations">
				<div style="display:flex; gap:10px; font-size:10pt; font-weight:bold; margin-bottom:4px; opacity:0.8;">
					<div style="flex:1;">Default Website Endpoint URL</div>
					<div style="width:40px;">Del</div>
				</div>
				<div id="defaultsEditorContainer">
					<?php if (empty($default_destinations_array)): ?>
						<div class="destination-row">
							<div style="flex:1;">
								<input type="text" name="default_urls[]" class="mac-input" maxlength="255" placeholder="https://addressbar.net/contact">
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
									<input type="text" name="default_urls[]" class="mac-input" maxlength="255" value="<?php echo htmlspecialchars($row_url); ?>" required>
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
						[ + Add Another Default Destination ]
					</button>
				</div>
				<div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center;">
					<button type="submit" class="mac-btn mac-btn-default">
						[ 💾 Save Default Destinations ]
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
