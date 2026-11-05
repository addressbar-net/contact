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
sudo ./install.sh -D yourdomain.com -d /var/www/html/contact -m /var/lib/contact -p 'YourSecurePassword123' -y
```

### Serving from `/tmp` with Persistent Disk Storage

If you run your website from `/tmp` (e.g. tmpfs/ramdisk), your messages remain safe on disk by designating a persistent storage directory:

```bash
sudo ./install.sh -D yourdomain.com -d /tmp/contact -m /var/lib/contact_messages -p 'YourSecurePassword123' -y
```

The installer writes files directly into the target directory provided (e.g. `contact/`), protecting your existing website's root `index.php` from ever being overwritten. The administrator password is provided during installation and hashed directly with bcrypt into the SQLite database. There are no password reset links or forms on the web interface; passwords are set during install and updated via command line.

### Installation Options & Flags

```
Usage: sudo ./install.sh [OPTIONS]

Options:
  -d, -w, --dir, --directory PATH
                               Target directory for installation (e.g. /var/www/html/contact or contact/)
  -m, --messages-dir PATH      Persistent message and database storage directory on disk (REQUIRED, e.g. /var/lib/contact)
  -p, --password PASS          Administrator password (REQUIRED for initial install)
  -D, --domain DOMAIN          Domain name or hostname of your existing website (default: detected hostname)
  -u, --user USER              Initial administrator username (default: admin)
      --db-path PATH           Explicit SQLite database file path (default: <messages-dir>/contact.sqlite)
      --skip-system            Skip system package verification
      --skip-db                Skip database initialization
  -U, --update                 Update an existing installation from git (git pull + sync)
  -y, --yes                    Non-interactive mode (requires -d/-w, -m, -p)
  -h, --help                   Display help message and exit
```

---

## 🔒 Security: Web-Blocked CLI Management Utilities

Administrative utilities (`update_password.php`, `create_user.php`, `set_user_password.php`, `set_password.php`) are strictly restricted to the command-line interface (`php_sapi_name() === 'cli'`). All HTTP / Web access is rejected with HTTP 403 Forbidden directly at the PHP application layer, requiring zero web server configuration. To manage host credentials via CLI:

```bash
# Update host password interactively from server CLI:
update_password

# Or update password directly via flag:
update_password -p "NewSecurePassword123"

# Update password for a specific user:
update_password -u admin -p "NewSecurePassword123"
```

---

## 🔄 Updating with Git

Because application data is stored in the local SQLite database file (untracked by git), you can update your installation at any time without merge conflicts:

```bash
git pull
sudo ./install.sh --update
```

---

## 📂 Project Structure

```
addr.bar/
├── install.sh                  # Root automated Git installer (Zero-configuration SQLite)
├── README.md                   # Repository overview & git installation guide
├── .gitignore                  # Git exclusions (*.sqlite, db_config.php, logs, sessions)
├── addressbar.net/             # Public web assets and contact directory
│   └── contact/                # Mirrored contact application
└── script/
    └── contact/
        ├── index.php           # Core standalone application (Direct SQLite PDO)
        ├── db.php              # Centralized SQLite PDO connection & schema self-healing
        ├── auth.php            # Domain-isolated authentication
        ├── signup.html         # Classic Mac OS GUI for login
        ├── schema.sql          # Full SQLite DDL schema
        ├── update_password.php # Dedicated CLI password updater
        ├── set_password.php    # CLI password & email setter
        ├── set_user_password.php
        ├── create_user.php     # CLI user account initializer
        ├── install.sh          # Module-level installer
        └── INSTALL.md          # Comprehensive installation guide
```

---

## 🛡️ Architecture & Security Highlights

- **SQLite WAL Mode**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) provides high-concurrency read/write transactions without database daemon overhead.
- **Direct Stream Transmission**: Inter-site communication uses PHP `stream_context_create()` HTTP POSTs; no Reverse DNS (PTR) or mail daemon required.
- **Login Rate Limiting**: 3 failed login attempts per IP address triggers an automatic 6-hour lock with real-time countdown timer.
- **Domain Scoping**: Multiple domains hosted on the same server share one SQLite database safely via domain-scoped isolation (`UNIQUE` per domain).
- **CLI Password Management & Notifications**: Administrator password set during installation and updated via `update_password` CLI utility; configurable email notifications for new incoming correspondence.
