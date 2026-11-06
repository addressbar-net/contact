-- ==============================================================================
-- addr.bar Remote Server Directory & Federated Inter-Site Mail System
-- Database Schema & Initial Data Setup (SQLite)
-- ==============================================================================
-- Engine: SQLite 3 via PHP pdo_sqlite
-- Storage: Direct disk file (contact.sqlite) - Zero server daemon required
-- Concurrency: WAL (Write-Ahead Logging) enabled
-- ==============================================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ------------------------------------------------------------------------------
-- 1. Table: users
-- Holds authenticated users (one user per domain name).
-- Supports multiple domains hosted on the same server sharing the same SQLite database.
-- Includes session token and password reset token fields.
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 1b. Table: login_attempts
-- Tracks failed login attempts per IP address and domain (maximum 3 attempts per IP, 6-hour lock).
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain TEXT DEFAULT NULL,
    ip_address TEXT NOT NULL,
    attempted_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ip_domain_attempted ON login_attempts(ip_address, domain, attempted_at);

-- ------------------------------------------------------------------------------
-- 2. Table: site_settings
-- System-wide key-value configuration toggles (comms, rate limits, presets).
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT DEFAULT (datetime('now'))
);

-- ------------------------------------------------------------------------------
-- 3. Table: blocked_sites
-- Blocklist containing domains or IP addresses denied from contacting this site.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blocked_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_or_url TEXT NOT NULL UNIQUE,
    ip_address TEXT DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    is_blocked INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now'))
);

-- ------------------------------------------------------------------------------
-- 4. Table: incoming_messages
-- Stores incoming messages received from peer websites via stream_context_create() HTTP POST,
-- copies of sent messages archived in the sender's own inbox, and delayed messages
-- awaiting retry after remote website rate limits expire.
-- Supports multi-recipient deletion tracking via deleted_by_users.
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 5. Table: sent_messages
-- Outbox records storing outbound dispatches. When dispatches are targeted
-- to multiple recipients, exactly one consolidated outbox record is stored.
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 6. Table: push_subscriptions
-- Stores W3C Web Push API subscriptions for browser and PWA push notifications.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain TEXT DEFAULT '',
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT DEFAULT '',
    auth TEXT DEFAULT '',
    user_agent TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_push_endpoint ON push_subscriptions(endpoint);
CREATE INDEX IF NOT EXISTS idx_push_domain ON push_subscriptions(domain);

-- ------------------------------------------------------------------------------
-- Initial Seed Configuration
-- ------------------------------------------------------------------------------
INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('allow_incoming_communications', '1'),
    ('push_notifications_enabled', '1'),
    ('rate_limiting_enabled', '1'),
    ('rate_limit_per_website_per_day', '10'),
    ('site_title', 'addr.bar Remote Server Directory'),
    ('default_mail_destinations', '[{"url":"https://tricurse.com/contact","user":""}]');

INSERT OR IGNORE INTO users (username, password) VALUES
    ('admin', '$2y$12$ViOB0iOIugIEP3hmEegeyO8JR3lmd/MihKTfqG6FBbOh3I2XDBuuK');
