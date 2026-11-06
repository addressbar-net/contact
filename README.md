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

- **CLI Management Utilities in `/usr/local/bin/`**: Administrative tools (`update_password`, `create_user`, `set_user_password`, `set_password`) are installed directly to `/usr/local/bin/` as executable system utilities and are never copied to the public webroot.
- **Collision-Free Configuration in `/etc/tricurse/`**: Configuration is stored per domain at `/etc/tricurse/<domain>/contact.conf` (and `/etc/tricurse/contact.conf`), allowing multiple client installations on the same server without conflicts.
- **Enforced Database Storage Outside Web Root**: The installer strictly requires the persistent message storage directory (`-m <path>`) to be outside the web root (recommended: `/var/lib/tricurse/contact/<domain>`). Storing `contact.sqlite` inside the webroot is prohibited to prevent direct HTTP file downloads.

To manage host credentials via CLI:

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
contact/
├── auth.php                    # Domain-isolated authentication & 6-hour lockout
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
├── README.md                   # Repository overview & deployment guide
├── schema.sql                  # Full SQLite DDL schema
├── set_password.php            # CLI password setter (installed to /usr/local/bin)
├── set_user_password.php       # CLI credential updater (installed to /usr/local/bin)
├── signup.html                 # Classic 1-Bit Macintosh monochrome GUI for login
├── sw.js                       # Site-aware Service Worker (PWA & Web Push dispatcher)
├── update_password.php         # Dedicated CLI password updater (installed to /usr/local/bin)
└── .gitignore                  # Git exclusions (*.sqlite, db_config.php, logs, sessions)
```

---

## 🛡️ Architecture & Security Highlights

- **SQLite WAL Mode**: Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) provides high-concurrency read/write transactions without database daemon overhead.
- **Direct Stream Transmission**: Inter-site communication uses PHP `stream_context_create()` HTTP POSTs; no Reverse DNS (PTR) or mail daemon required.
- **Login Rate Limiting**: 3 failed login attempts per IP address triggers an automatic 6-hour lock with real-time countdown timer.
- **Domain Scoping**: Multiple domains hosted on the same server share one SQLite database safely via domain-scoped isolation (`UNIQUE` per domain).
- **CLI Password Management in `/usr/local/bin/`**: Administrator password set during installation and updated via `update_password` CLI utility; utilities are completely excluded from the public webroot.
- **PWA & Browser Push Notifications**: W3C Web Push alerts delivered directly to browser and desktop via RFC 8292 VAPID over HTTPS; zero email, zero SMTP, zero external mail services required.
