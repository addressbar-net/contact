# addr.bar

Federated Inter-Site Correspondence Suite & Remote Server Directory (SQLite Edition).

`addr.bar` enables autonomous websites to communicate peer-to-peer over standard HTTP POST requests (`stream_context_create()` / `file_get_contents()`) without requiring Reverse DNS (PTR) or complex mail server infrastructure.

Powered by direct **SQLite file storage** (`contact.sqlite`)—zero database server daemon, zero database passwords, and zero network configuration required.

---

## 🚀 Quick Installation with Git

Install the entire server suite and web directory in two commands:

```bash
# 1. Clone the repository
git clone https://github.com/addressbar-net/contact.git
cd contact

# 2. Run the installer (interactive - prompts for target directory, message directory, and password)
sudo ./install.sh
```

### Non-Interactive One-Liner (Existing Website Directory Deployment)

Specify the target installation directory (`-d` or `-w`), persistent message storage directory on disk (`-m`), and administrator password (`-p`):

```bash
sudo ./install.sh -D client1.example.com -d /var/www/client1 -m /var/lib/tricurse/contact/client1.example.com -p 'YourSecurePassword123' -H -y
```

### Serving from `/tmp` with Persistent Disk Storage

If you run your website from `/tmp` (e.g. tmpfs/ramdisk), your messages remain safe on disk by designating a persistent storage directory:

```bash
sudo ./install.sh -D client1.example.com -d /tmp/contact -m /var/lib/tricurse/contact/client1.example.com -p 'YourSecurePassword123' -y
```

The installer writes files directly into the target directory provided (e.g. `contact/`), protecting your existing website's root `index.php` from ever being overwritten. The administrator password is provided during installation and hashed directly with bcrypt into the SQLite database. There are no password reset links or forms on the web interface; passwords are set during install and updated via command line.

### Installation Options & Flags

```
Usage: sudo ./install.sh [OPTIONS]

Options:
  -d, -w, --dir, --directory PATH
                               Target directory for installation (e.g. /var/www/html/contact or contact/)
  -m, --messages-dir PATH      Persistent message and database storage directory on disk (default: /var/lib/tricurse/contact/<domain>)
  -p, --password PASS          Administrator password (REQUIRED for initial install)
  -D, --domain DOMAIN          Domain name or hostname of your existing website (default: detected hostname)
  -u, --user USER              Initial administrator username (default: admin)
  -H, --add-hosts              Add domain or subdomain to /etc/hosts (127.0.0.1 <domain>) for local server loopback
      --db-path PATH           Explicit SQLite database file path (default: <messages-dir>/contact.sqlite)
      --skip-system            Skip system package verification
      --skip-db                Skip database initialization
  -U, --update                 Update an existing installation from git (git pull + sync)
  -y, --yes                    Non-interactive mode (requires -d/-w, -p)
  -h, --help                   Display help message and exit
```

---

## 🔒 Security: CLI Utilities in `/usr/local/bin/` & Isolated Per-Client Storage

- **Pre-Message Destination Website Availability Check**: Clients can verify whether another website (local client or external domain) is online, reachable, and available to receive messages *before* writing or sending mail. Clicking `[ 🔍 Check ]` on any destination row or `[ 🔍 Check Availability ]` in the `To:` toolbar performs an immediate availability probe, returning real-time status indicators (🟢 Available with remaining daily quota, ⏳ Rate Limited, 🚫 Communications Disabled, 🚫 Blocked, or 🔴 Unreachable). Both root domain installations (e.g. `novy.addr.bar`) and subdirectory installations (e.g. `tricurse.com/contact`) are automatically resolved and supported without requiring manual path guessing. Selecting a preset name from the Local Server Client dropdown automatically triggers an instant availability check.
- **Client Removal & Relocation Cleanup (`remove_client`)**: Easily remove obsolete, old, or relocated clients from the server. When moving an installation to a new location, orphaned records (where `index.php` is no longer present) are automatically detected during installation and multi-client updates. Administrators can remove clients interactively (`sudo remove_client`), clean all orphaned installations at once (`sudo remove_client --clean-orphans` or `sudo ./install.sh --clean-orphans`), remove a specific domain (`sudo remove_client -D <domain> -y`), or remove them directly from the web GUI under **Communication Controls** (`tab=comms`).
- **Mandatory Unique Domain or Subdomain Per Client**: Every client account must host its `index.php` at a unique domain or subdomain (e.g. `client1.example.com`, `client2.example.com`). Co-tenancy under the same domain via subpaths is prohibited because SSL/TLS SNI certificates, DNS sender authentication, and per-website rate limiting (`sender_host`) operate at the hostname level. Uniqueness is enforced in `install.sh` and `is_client_domain_unique()` in `db.php`, while stale registrations from moved directories are automatically migrated.
- **CLI Management Utilities in `/usr/local/bin/`**: Administrative tools (`update_client_name`, `update_password`, `create_user`, `set_user_password`, `set_password`, `contact_list`, `contact_update_all`, `merge_databases`, `remove_client`) are installed directly to `/usr/local/bin/` as executable system utilities and are never copied to the public webroot.
- **Main Recipient Point vs. Individual Client Roles**: During installation, administrators are prompted to select whether the site functions as the Main Recipient Point (central receiving website / transmitter) or an Individual Client.
- **Main Website Subscriptions & Transmitter Broadcast**: Individual clients can subscribe to the main website. Whenever a broadcast is sent to or from the transmitter/main contact point, all subscribed clients automatically receive a copy in their inbox without notification alerts (notifications are sent only if the main contact point explicitly includes itself in the recipient list).
- **Intra-Server Group Letters**: All letters exchanged between clients within the server group are automatically received in the inbox of all group clients, ensuring full group visibility.
- **Inner Message Protection & Block Site Suppression**: The `[ 🚫 Block Site ]` button is completely removed for all "inner messages" (messages originating within the local server group, intra-server group letters, transmitter broadcasts from the main contact point, or local loopback/server domains). Only messages from remote/outside websites display the block site option, and communication control safeguards reject blocking inner server group sites.
- **Preset Destination Names & External Destination Distribution Filtering**: When sending mail, local server clients are selected from a dropdown of preset names. All sources and destinations are displayed cleanly as `To` and `From` without protocol schemes (`https://`) or script filenames (`index.php`). When sending messages to an external contact point, the option *"Include all 'To' destinations in messages arriving to external sources"* optionally includes destinations in the message information arriving to external contact points while strictly excluding internal recipients. Any messages delivered internally always include the full recipient list.
- **Archive Message & Archive All by Month and Year**: Message deletion is replaced with dedicated archiving (`[ 📦 Archive Message ]`). Users can archive individual messages or archive all messages at once (`[ 📦 Archive All Messages ]`). Archived messages are moved to a separate archive list accessible by month and year (`tab=archive`), with quick-access period buttons, year and month dropdown selectors, and one-click unarchiving (`[ 📥 Move Back to Inbox ]` or `[ 📥 Move All Back to Inbox ]`) back to the inbox.
- **Mandatory Administrator-Configured Client Names**: Every client account (and the main contact point) has a name set during installation or via CLI (`update_client_name`). The client web UI cannot alter the name (input field removed). For existing clients without a name, `contact_update_all` presents an interactive option to set missing names.
- **Single Shared Database Architecture (The Only Option)**: All clients and contact points on the server share a single SQLite database (`/var/lib/tricurse/contact/contact.sqlite`). Messages are stored once on disk with zero duplicate records. Read receipts and message archiving are tracked independently per domain without duplicating message content.
- **Simultaneous Multi-Client Server Updater (`contact_update_all`)**: Updates all client installations registered on the server simultaneously in a single command, automatically maintaining all push notification subscriptions and VAPID key pairs intact, and prompting for missing client names.
- **Server Installation Registry (`/etc/tricurse/installations.json`)**: Tracks all domain and folder installations on the server, viewable anytime via `contact_list` or `./install.sh --list`.
- **Outside vs. Inside Dual Rate Limits**: Separate rate limits for remote internet websites (outside) vs local server subdomains / loopback peers (inside) identified via `/etc/hosts` and local IPs.
- **Collision-Free Configuration in `/etc/tricurse/`**: Configuration is stored per domain at `/etc/tricurse/<domain>/contact.conf` (and `/etc/tricurse/contact.conf`), allowing multiple client installations on the same server without conflicts.
- **Enforced Database Storage Outside Web Root**: The installer strictly requires the persistent message storage directory (`-m <path>`) to be outside the web root (recommended: `/var/lib/tricurse/contact`). Storing `contact.sqlite` inside the webroot is prohibited to prevent direct HTTP file downloads.
- **Push Notification Maintenance & Prompts**: Updates preserve all existing push subscriptions and VAPID key pairs. When a client signs in without push enabled, a banner prompts them to enable it. When the app is installed and launched for the first time as a standalone PWA, it automatically resets stale subscriptions and prompts the user to reactivate push notifications for the native application container.
- **15-Year Persistent Login with Rolling Renewal**: Client sessions stay logged in for 15 years. Every active visit automatically and silently pushes the cookie expiration forward by 15 years, so existing logged-in clients never need to re-enter their password.

To manage host credentials and client names via CLI:

```bash
# Update client account name interactively or directly:
update_client_name
update_client_name -l
update_client_name -D client.example.com -N "Alpha Branch Office"
update_client_name -a

# Update host password interactively from server CLI:
update_password

# Or update password directly via flag:
update_password -p "NewSecurePassword123"

# Update password for a specific user:
update_password -u admin -p "NewSecurePassword123"

# List all recorded client installations on the server:
contact_list

# Interactively remove an old or relocated client installation:
sudo remove_client

# Automatically clean all orphaned clients (where webroot no longer contains index.php):
sudo remove_client --clean-orphans

# Remove a specific client by domain:
sudo remove_client -D oldclient.example.com -y

# Update all client installations on the server simultaneously:
sudo contact_update_all
```

---

## 🔄 Updating with Git

Because application data is stored in the local SQLite database file outside the web root (untracked by git), you can update your installations at any time without merge conflicts:

### Update All Clients Simultaneously on a Server
```bash
# Using the global CLI command:
sudo contact_update_all

# Or via install.sh directly:
git pull
sudo ./install.sh --update-all
```

### Update a Single Client Installation
```bash
git pull
sudo ./install.sh --update -d /var/www/html/contact -m /var/lib/tricurse/contact/example.com
```

All existing push notification subscriptions, VAPID key pairs, inbox messages, archives, and host passwords are fully maintained during updates.

---

## 📂 Project Structure

```
contact/
├── auth.php                    # Domain-isolated authentication & 6-hour lockout
├── contact_list.php            # CLI installation registry lister (installed to /usr/local/bin)
├── contact_update_all.php      # CLI simultaneous multi-client updater (installed to /usr/local/bin)
├── create_user.php             # CLI user account initializer (installed to /usr/local/bin)
├── db.php                      # Centralized SQLite PDO connection & schema self-healing
├── documents/                  # Architectural guides & security documentation
│   ├── install_for_multiple_clients.pdf
│   ├── install_for_multiple_clients__consideration.pdf
│   └── security_fix.pdf
├── icon-192.png                # PWA app icon (192x192 maskable)
├── icon-512.png                # PWA app icon (512x512 maskable)
├── index.php                   # Core standalone application (Direct SQLite PDO & PWA desktop)
├── install.sh                  # One-step automated CLI installer (SQLite & PWA ready)
├── INSTALL.md                  # Comprehensive installation & CLI management guide
├── manifest.json               # Dynamic site-aware PWA manifest
├── merge_databases.php         # Database migration utility to consolidate separate SQLite DBs
├── README.md                   # Repository overview & deployment guide
├── schema.sql                  # Full SQLite DDL schema
├── set_password.php            # CLI password setter (installed to /usr/local/bin)
├── set_user_password.php       # CLI credential updater (installed to /usr/local/bin)
├── signup.html                 # Classic 1-Bit Macintosh monochrome GUI for login
├── sw.js                       # Site-aware Service Worker (PWA & Web Push dispatcher)
├── update_client_name.php      # Dedicated CLI client name updater (installed to /usr/local/bin)
├── update_password.php         # Dedicated CLI password updater (installed to /usr/local/bin)
└── .gitignore                  # Git exclusions (*.sqlite, db_config.php, logs, sessions)
```

---

## 🛡️ Architecture & Security Highlights

- **SQLite WAL Mode**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) provides high-concurrency read/write transactions without database daemon overhead.
- **Single Shared Database**: All server installations operate against a single consolidated SQLite database with zero redundant storage and independent read/archive state tracking.
- **Direct Stream Transmission**: Inter-site communication uses PHP `stream_context_create()` HTTP POSTs; no Reverse DNS (PTR) or mail daemon required.
- **Login Rate Limiting**: 3 failed login attempts per IP address triggers an automatic 6-hour lock with real-time countdown timer.
- **Domain Scoping**: Multiple domains hosted on the same server share one SQLite database safely via domain-scoped isolation (`UNIQUE` per domain).
- **Inner Message Safeguards**: Intra-server communications, group letters, and transmitter broadcasts cannot be blocked by clients, protecting essential communication infrastructure.
- **CLI Name & Password Management in `/usr/local/bin/`**: Administrator passwords and client account names are managed and updated via dedicated CLI utilities (`update_client_name`, `update_password`); utilities are completely excluded from the public webroot.
- **PWA & Browser Push Notifications**: W3C Web Push alerts delivered directly to browser and desktop via RFC 8292 VAPID over HTTPS; zero email, zero SMTP, zero external mail services required.
