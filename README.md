# addr.bar

Federated Inter-Site Correspondence Suite & Remote Server Directory (SQLite Edition).

`addr.bar` enables autonomous websites to communicate peer-to-peer over authenticated HTTPS POST requests (`stream_context_create()` / `file_get_contents()`) without requiring Reverse DNS (PTR), SMTP daemons, or external mail providers.

Powered by direct **SQLite file storage** (`contact.sqlite`)—zero database server daemon, zero database passwords, and zero network configuration required.

---

## 🏛️ Architecture: Per-Domain Groups & Subdirectory Access (`/hotletter/`)

Instead of requiring each client to maintain an isolated domain, the system operates as **per-domain groups**:

1. **Unique Domain per Group Installation**:
   - At least one unique domain or subdomain is used for each group's basic installation (e.g. `novy.band` or `group.example.com`).
   - The group's front-end application is accessed from the `<domain-name>/hotletter/` directory (e.g. `https://novy.band/hotletter/`).
2. **Clients as Recipient Folders on the Same Domain**:
   - After the group is installed, clients exist on that same domain or subdomain as recipient folders (e.g. `novy.band/jason`, `novy.band/xenia`).
   - Clients do not require separate domain registrations or separate installations; they share the group's installation and database.
3. **Clean Domain Addressing**:
   - **Main Contact Point**: Addressed in the destination field cleanly as the domain name without any subdirectories: `novy.band`.
   - **Client Accounts**: Addressed with folder names: `novy.band/jason` (or `jason@novy.band`).
4. **Single SSL Verification & Consolidated Wire Delivery**:
   - A single SSL certificate on the domain verifies all recipient folders.
   - When a letter is addressed to multiple recipients at the same domain (e.g. `novy.band/jason; novy.band/xenia`), only **one copy** is transmitted across the wire in a single HTTP POST request to `https://novy.band/hotletter/index.php`.
   - The receiving script softly delivers the letter into each targeted recipient folder's inbox.
5. **Client Administrator Access**:
   - One client user can be granted permission to access the Main Contact Point's account.
   - Set from the server CLI via `set_client_admin -D <domain> -u <username>`.
   - The Client Administrator can switch into the Main Contact Point account directly from the web interface at `https://<domain>/hotletter/`.
6. **Multi-Group Servers**:
   - More than one group can fit on the same server.
   - Each group has a separate installation on its own domain or subdomain, with its own web directory (e.g. `/var/www/html/hotletter`), dedicated database (`/var/lib/tricurse/contact/<domain>/contact.sqlite`), and configuration (`/etc/tricurse/<domain>/contact.conf`).
7. **External Main Contact Point Subscriptions & Broadcast Scopes**:
   - Clients and Main Contact Points can subscribe to an External Main Contact Point. Subscribing sends an automated registration request to that remote server over HTTPS.
   - When multiple clients on the same domain subscribe to an external hub, only **one copy** is transmitted across the wire to that domain in a single HTTP POST, softly fanning out into each subscriber folder's inbox upon arrival.
   - If the Main Contact Point of a domain is subscribed to an External Main Contact Point, all recipients in that domain group receive letters originating from it in the tab grouped with **Revealed Letters** (`tab=revealed`).
   - All letters from the Main Contact Point are automatically **Group Letters** internally. When composing mail, the Main Contact Point can choose whether to broadcast to **External Subscriptions & Internal Group** or restrict delivery to **Internal Group Only**.
   - Internal recipients automatically receive all letters from the Main Contact Point in their group tab; subscribing to the Internal Main Contact Point is optional and enables instant device push alerts/notifications.

---

## 🚀 Quick Installation with Git

Install the entire server suite and web directory in two commands:

```bash
# 1. Clone the repository
git clone https://github.com/addressbar-net/contact.git
cd contact

# 2. Run the interactive installer (prompts for domain, target directory, and password):
sudo ./install.sh
```

### Guided Interactive Installation
The installer automatically detects your hostname, configures the `/hotletter/` directory, initializes the group database outside the web root (`/var/lib/tricurse/contact/<domain>/contact.sqlite`), and secures administrator credentials:

```bash
sudo ./install.sh
```

### Non-Interactive Deployment (Existing Website Directory Deployment)
Specify the group domain (`-D`), target installation directory (`-d` or `-w`), message storage directory (`-m`), and administrator password (`-p`):

```bash
sudo ./install.sh -D novy.band -d /var/www/novy.band/hotletter -m /var/lib/tricurse/contact/novy.band -p 'YourSecurePassword123' -H -y
```

### Serving from `/tmp` with Persistent Disk Storage
If you run your website from `/tmp` (e.g. tmpfs/ramdisk), your messages remain safe on disk by designating a persistent storage directory:

```bash
sudo ./install.sh -D novy.band -d /tmp/hotletter -m /var/lib/tricurse/contact/novy.band -p 'YourSecurePassword123' -y
```

The installer writes files directly into the target directory provided (e.g. `/var/www/html/hotletter`), never overwriting your existing website's root `index.php`. The administrator password is set during install and hashed directly with bcrypt into the SQLite database. There are no password reset links or forms on the public web interface; passwords are set during install and managed via server command line.

### Installation Options & Flags

```
Usage: sudo ./install.sh [OPTIONS]

Options:
  -d, -w, --dir, --directory PATH
                               Target directory for installation (e.g. /var/www/html/hotletter or hotletter/)
  -m, --messages-dir PATH      Persistent message and database storage directory on disk (default: /var/lib/tricurse/contact/<domain>)
  -p, --password PASS          Administrator password (REQUIRED for initial install)
  -N, --name, --client-name NAME
                               Name for this domain group / Main Contact Point
  -D, --domain DOMAIN          Domain name or hostname for this group installation (default: detected hostname)
  -u, --user USER              Initial administrator username (default: admin)
  -r, --role ROLE              Operating role: 'main' (Domain Group with Main Contact Point; default) or 'client' (Subscribed Group)
      --main-point             Operate as a Domain Group with Main Contact Point (default)
      --client                 Operate as a Subscribed Group (subscribes to messages received by main site)
  -s, --subscribe-to DOMAIN    Main website domain/URL to subscribe to (when role is client)
  -S, --shared-db              Use shared database configuration
  -H, --add-hosts              Add domain or subdomain to /etc/hosts (127.0.0.1 <domain>) for local server loopback
      --db-path PATH           Explicit SQLite database file path (default: <messages-dir>/contact.sqlite)
      --skip-system            Skip system package verification
      --skip-db                Skip database initialization
  -U, --update                 Update an existing installation from git (git pull + sync)
  -A, --update-all             Simultaneously update all group installations on this server
  -R, --remove [DOMAIN]        Remove an existing or orphaned group installation from the server
      --clean-orphans          Remove all group installations whose webroot no longer contains index.php
      --uninstall              Completely uninstall the package (CLI tools, web files, logs)
  -l, --list, --list-installs  List all recorded installations on this server and exit
  -y, --yes                    Non-interactive mode (requires -d/-w, -p for initial install)
  -h, --help                   Display help message and exit
```

---

## 🔒 Security: CLI Utilities in `/usr/local/bin/` & Isolated Storage

- **Pre-Message Destination Website Availability Check**: Senders can verify whether another website is online, reachable, and available to receive messages *before* writing or sending mail. Clicking `[ 🔍 Check ]` on any destination row or `[ 🔍 Check Availability ]` in the `To:` toolbar performs an immediate availability probe, returning real-time status indicators (🟢 Available with remaining daily quota, ⏳ Rate Limited, 🚫 Communications Disabled, 🚫 Blocked, or 🔴 Unreachable). Both root destinations (`novy.band`) and folder accounts (`novy.band/jason`) are resolved automatically to `<domain>/hotletter/index.php`.
- **Client Administrator Designation (`set_client_admin`)**: The server administrator can designate one client user as the Client Administrator for a domain group via CLI. When logged into `https://<domain>/hotletter/`, this user receives a `[ 🔑 Access Main Contact Point Account ]` control to switch into the Main Contact Point account and manage group-level correspondence, with a `[ ↩ Switch Back to Client Account ]` button to return.
- **Client Account Creation on the Same Domain (`create_user`)**: Add individual client accounts to the domain group at any time (`create_user -D <domain> -u <username> -p <password> -y`). Clients log in directly at `https://<domain>/hotletter/?folder=<username>`.
- **Consolidated Outbound Transmission & Soft Fan-Out**: When sending to multiple recipient folders on the same domain at once, only a single HTTP POST request is sent over the wire. The receiving server reads the `recipient_users` header and softly fans out delivery into each user's inbox folder.
- **Intra-Group Letters**: Letters exchanged between members of the same domain group are visible to the entire group under `tab=group`, while preserving each specific recipient's folder name badge.
- **Inner Message Protection & Block Site Suppression**: The `[ 🚫 Block Site ]` button is completely removed for all inner messages (messages originating within the local server group, intra-group letters, broadcasts from the main contact point, or local loopback domains). Only messages from remote/outside websites display the block option.
- **Dedicated Per-Group Database Storage**: Each domain group maintains its own isolated SQLite database at `/var/lib/tricurse/contact/<domain>/contact.sqlite`. Database storage is strictly enforced outside the public web document root to prevent direct HTTP file downloads.
- **15-Year Persistent Login with Rolling Renewal**: Client sessions stay logged in for 15 years. Every active visit automatically and silently pushes the session cookie expiration forward by 15 years.
- **Login Rate Limiting**: Up to 3 failed login attempts per IP address per domain. Once exceeded, login is locked for 6 hours (21,600 seconds) with an automatic countdown timer.
- **CLI Management Utilities in `/usr/local/bin/`**: Administrative tools are installed directly to `/usr/local/bin/` as executable system utilities and are never copied to the public web root:
  - `set_client_admin`: Designate or revoke Client Administrator privileges for a client.
  - `create_user`: Initialize new client accounts for a domain group.
  - `set_user_password`: Update client credentials directly in the SQLite database.
  - `update_password`: Set or update the administrator password via CLI.
  - `update_client_name`: Set or update group and client display names.
  - `contact_list`: List all recorded domain group installations on the server.
  - `contact_update_all`: Simultaneously update all group installations on the server.
  - `remove_client`: Remove an existing or orphaned group installation.
  - `wipe_database`: Purge or reset database records.
  - `merge_databases`: Database consolidation and migration utility.

### Managing Domain Groups via CLI

```bash
# Designate 'jason' as the Client Administrator for novy.band:
set_client_admin -D novy.band -u jason

# Check Client Administrator status:
set_client_admin -D novy.band -s

# Revoke Client Administrator privileges:
set_client_admin -D novy.band -r

# Add client accounts to the domain group:
create_user -D novy.band -u jason -p "SecurePass123" -y
create_user -D novy.band -u xenia -p "SecurePass456" -y

# Update host password interactively:
update_password

# Or update password directly via flag:
update_password -p "NewSecurePassword123"

# List all recorded installations on the server:
contact_list

# Interactively remove an old or relocated installation:
sudo remove_client

# Automatically clean all orphaned installations:
sudo remove_client --clean-orphans

# Update all installations on the server simultaneously:
sudo contact_update_all
```

### Uninstallation (`uninstall.sh`)

Safely remove a specific domain installation or completely uninstall the entire suite:

```bash
# 1. Uninstall a particular installation for a specific domain:
sudo ./uninstall.sh -D novy.addr.bar
sudo ./uninstall.sh -D novy.addr.bar -y

# 2. Interactive menu (select a domain from discovered installations or choose full removal):
sudo ./uninstall.sh

# 3. Completely remove ALL domain installations and all CLI utilities:
sudo ./uninstall.sh --all -y

# 4. Preview what would be removed without deleting anything (dry-run):
sudo ./uninstall.sh --dry-run -D novy.addr.bar
sudo ./uninstall.sh --dry-run --all

# 5. Remove web files and configs while preserving databases and message storage:
sudo ./uninstall.sh -D novy.addr.bar --keep-data -y

# 6. Uninstall via install.sh:
sudo ./install.sh --uninstall -D novy.addr.bar
```

> [!NOTE]
> - When uninstalling a particular domain, other domain installations and CLI utilities (if other domains remain) are safely preserved.
> - The uninstaller strictly removes only this package's storage (`/var/lib/tricurse/contact`) and **never deletes `/var/lib/tricurse` itself**, preserving other packages installed in that directory.

---

## 🌐 Web Access & Login URLs

```
Main Contact Point:   https://<domain>/hotletter/ (or https://<domain>/hotletter/index.php)
Client Account Login: https://<domain>/hotletter/?folder=<username>
Classic GUI Login:    https://<domain>/hotletter/signup.html
```

- When addressing the Main Contact Point in letters, senders specify strictly the clean domain: `<domain>` (e.g. `novy.band`).
- When addressing client accounts, senders specify the user folder: `<domain>/<username>` (e.g. `novy.band/jason`).

---

## 🔄 Updating with Git

Because application data is stored in dedicated SQLite database files outside the web root (untracked by git), you can update your installations at any time without merge conflicts:

### Update All Groups Simultaneously on a Server
```bash
# Using the global CLI command:
sudo contact_update_all

# Or via install.sh directly:
git pull
sudo ./install.sh --update-all
```

### Update a Single Group Installation
```bash
git pull
sudo ./install.sh --update -d /var/www/novy.band/hotletter -D novy.band
```

All existing push notification subscriptions, VAPID key pairs, inbox messages, archives, and credentials are fully maintained during updates.

---

## 📂 Project Structure

```
contact/
├── auth.php                    # Domain-isolated authentication, role-switching API, & 6-hour lockout
├── contact_list.php            # CLI installation registry lister (installed to /usr/local/bin)
├── contact_update_all.php      # CLI simultaneous multi-group updater (installed to /usr/local/bin)
├── create_user.php             # CLI user account initializer (installed to /usr/local/bin)
├── db.php                      # SQLite PDO connection, /hotletter/ routing, & client admin helpers
├── documents/                  # Architectural guides & security documentation
│   ├── install_for_multiple_clients.pdf
│   ├── install_for_multiple_clients__consideration.pdf
│   ├── security_fix.pdf
│   └── separate_installation_per_group.pdf
├── icon-192.png                # PWA app icon (192x192 maskable)
├── icon-512.png                # PWA app icon (512x512 maskable)
├── index.php                   # Core standalone application (Direct SQLite PDO, /hotletter/ gateway, & desktop)
├── install.sh                  # One-step automated CLI installer (SQLite & PWA ready)
├── INSTALL.md                  # Comprehensive installation & CLI management guide
├── manifest.json               # Dynamic site-aware PWA manifest
├── merge_databases.php         # Database migration utility to consolidate separate SQLite DBs
├── README.md                   # Repository overview & deployment guide
├── remove_client.php           # CLI installation removal & orphan cleanup utility (installed to /usr/local/bin)
├── schema.sql                  # Full SQLite DDL schema
├── set_client_admin.php        # CLI Client Administrator configuration utility (installed to /usr/local/bin)
├── set_password.php            # CLI password setter (installed to /usr/local/bin)
├── set_user_password.php       # CLI credential updater (installed to /usr/local/bin)
├── signup.html                 # Classic 1-Bit Macintosh monochrome GUI for login
├── sw.js                       # Site-aware Service Worker (PWA & Web Push dispatcher)
├── uninstall.sh                # Complete package uninstallation script (preserves /var/lib/tricurse)
├── update_client_name.php      # Dedicated CLI client name updater (installed to /usr/local/bin)
├── update_password.php         # Dedicated CLI password updater (installed to /usr/local/bin)
├── wipe_database.php           # CLI database reset and cleanup utility (installed to /usr/local/bin)
└── .gitignore                  # Git exclusions (*.sqlite, db_config.php, logs, sessions)
```

---

## 🛡️ Architecture & Security Highlights

- **Per-Domain Groups**: Each domain or subdomain functions as an autonomous group with its own Main Contact Point. Clients exist on the same domain as recipient folders.
- **Subdirectory Routing (`/hotletter/`)**: Physical script and client accounts are accessed through the `<domain>/hotletter/` subdirectory, while senders address the Main Contact Point using the clean domain name.
- **Client Administrator Account Switching**: One client user can be designated to access the Main Contact Point's account, with immediate bidirectional session switching in the web UI.
- **Consolidated Outbound POSTs**: Transmits exactly one HTTP request across the wire when sending to multiple recipients at the same destination host.
- **Soft Inbound Fan-Out**: Received letters are distributed softly into all targeted recipient folders within the group database.
- **Direct Stream Transmission**: Inter-site communication uses PHP `stream_context_create()` HTTP POSTs; no Reverse DNS (PTR) or mail daemon required.
- **SQLite WAL Mode**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) provides high-concurrency read/write transactions without database daemon overhead.
- **Login Rate Limiting**: 3 failed login attempts per IP address triggers an automatic 6-hour lock with real-time countdown timer.
- **Inner Message Safeguards**: Intra-group communications, group letters, and broadcasts cannot be blocked by clients.
- **CLI Management in `/usr/local/bin/`**: Administrative tools are managed via dedicated CLI utilities and are strictly blocked on the web server (HTTP 403).
- **PWA & Browser Push Notifications**: W3C Web Push alerts delivered directly to browser and desktop via RFC 8292 VAPID over HTTPS; zero email, zero SMTP, zero external mail services required.
