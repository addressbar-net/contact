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
    destination_url TEXT DEFAULT NULL,
    target_domain TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_sender_host ON incoming_messages(sender_host);
CREATE INDEX IF NOT EXISTS idx_received_at ON incoming_messages(received_at);
CREATE INDEX IF NOT EXISTS idx_is_delayed ON incoming_messages(is_delayed);
CREATE INDEX IF NOT EXISTS idx_recipient_user ON incoming_messages(recipient_user);
CREATE INDEX IF NOT EXISTS idx_target_domain ON incoming_messages(target_domain);

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
-- 7. Table: main_subscriptions
-- Tracks client domain subscriptions to main recipient point websites.
-- When a message arrives at the main website, all subscribed clients get a copy
-- while sharing a single database (only one copy stored on disk).
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS main_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_domain TEXT NOT NULL,
    main_domain TEXT NOT NULL,
    client_url TEXT DEFAULT '',
    is_local INTEGER DEFAULT 1,
    subscribed_at TEXT DEFAULT (datetime('now')),
    UNIQUE(client_domain, main_domain)
);
CREATE INDEX IF NOT EXISTS idx_main_sub_client ON main_subscriptions(client_domain);
CREATE INDEX IF NOT EXISTS idx_main_sub_main ON main_subscriptions(main_domain);

-- ------------------------------------------------------------------------------
-- 8. Table: message_reads
-- Tracks read status per client domain for broadcast and direct messages.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS message_reads (
    message_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    read_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (message_id, domain)
);

-- ------------------------------------------------------------------------------
-- 9. Table: message_deletions
-- Tracks per-client message deletions so deleting a broadcast message does not
-- remove it from other clients or the main recipient point.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS message_deletions (
    message_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    deleted_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (message_id, domain)
);

-- ------------------------------------------------------------------------------
-- 10. Table: push_disabled_sources
-- Stores individual sources (sender host, domain, or URL) for which device push
-- notifications are disabled/muted per domain. Messages still enter the inbox
-- and show up in the unread inbox count until marked as read.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_disabled_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain TEXT NOT NULL DEFAULT '',
    source_identifier TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(domain, source_identifier)
);
CREATE INDEX IF NOT EXISTS idx_push_disabled_domain_source ON push_disabled_sources(domain, source_identifier);

-- ------------------------------------------------------------------------------
-- Initial Seed Configuration
-- ------------------------------------------------------------------------------
INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('allow_incoming_communications', '1'),
    ('push_notifications_enabled', '1'),
    ('push_notifications_outside_enabled', '1'),
    ('push_notifications_inside_enabled', '1'),
    ('push_notifications_main_broadcast_enabled', '1'),
    ('rate_limiting_enabled', '1'),
    ('rate_limit_outside_per_day', '10'),
    ('rate_limit_inside_per_day', '100'),
    ('rate_limit_per_website_per_day', '10'),
    ('server_role', 'main'),
    ('subscribed_to_main_domain', ''),
    ('subscribed_to_main_url', ''),
    ('site_title', 'addr.bar Remote Server Directory'),
    ('default_mail_destinations', '');

INSERT OR IGNORE INTO users (username, password) VALUES
    ('admin', '$2y$12$ViOB0iOIugIEP3hmEegeyO8JR3lmd/MihKTfqG6FBbOh3I2XDBuuK');
