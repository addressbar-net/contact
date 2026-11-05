# addr.bar Remote Server Directory & Federated Inter-Site Mail System
## Installation & Database Deployment Guide (SQLite Edition)

This deployment package contains everything required to run an autonomous **addr.bar Remote Server Directory** instance with peer-to-peer federated messaging using PHP `stream_context_create()` HTTP POST requests (without requiring Reverse DNS).

Powered by direct **SQLite file access**—no MariaDB/MySQL background daemon, zero database passwords, and zero database configuration required.

---

### Package Contents
- `index.php`: The standalone application script containing the login system (3 login attempts per IP), federated mail form, incoming API endpoint, inbox/outbox, and communication settings management.
- `db.php`: Direct file access SQLite PDO database connector with automatic schema self-healing and WAL mode concurrency.
- `auth.php`: Authentication endpoint enforcing a single user per domain name (multi-domain isolation), 3 login attempts per IP address (with 6-hour lock timer), and session management.
- `signup.html`: Classic Mac OS monochrome GUI for domain host login.
- `update_password.php`: Command-line interface (CLI only) utility to set or update the administrator password directly in the SQLite database.
- `set_user_password.php`: Command-line interface (CLI only) utility to update host credentials directly in the SQLite database.
- `create_user.php`: Command-line interface (CLI only) utility to initialize host credentials in the SQLite database.
- `set_password.php`: Command-line interface (CLI only) utility to set host password.
- `schema.sql`: Full SQLite schema script defining all tables (`users` with domain scoping, `login_attempts` with 6-hour lock tracking, `site_settings`, `blocked_sites`, `incoming_messages`, `sent_messages`), indexes, and default initial seed data.
- `install.sh`: Automated one-step zero-configuration installer script for git-based deployments and updates.
- `INSTALL.md`: This installation guide.

---

### Security & Storage Architecture

- **CLI-Only Management Utilities**: Administrative tools (`update_password.php`, `create_user.php`, `set_user_password.php`, `set_password.php`) are strictly restricted to the command-line interface (`php_sapi_name() === 'cli'`). Any HTTP or web server requests are immediately blocked with HTTP 403 Forbidden directly at the PHP application layer, requiring zero web server configuration.
- **Dedicated Web Root & Separate Message Storage on Disk**: The client explicitly specifies where the front-end files are served from (`-w <webroot>`) and a separate directory for storing messages (`-m <messages_dir>`). This allows serving front-end assets from a fast or volatile directory (like `/tmp/contact`) while keeping all messages, user data, and the SQLite database safely on persistent disk.
- **Directory-Based Existing Website Deployment**: The program installs directly into the directory of your existing website. Your pre-existing web server (Nginx, Apache, Caddy, etc.) handles all HTTP/HTTPS routing, PHP execution, and SSL certificates automatically without modifying web server configuration files.
- **Administrator Password Set on Install & CLI-Only Updates**: The administrator password is provided and configured directly on the command line during installation (`-p <password>` or interactive prompt with hidden input). There are zero password reset links or forms on the web interface. To update the password at any time, run `update_password` from the server terminal.
- **One User Per Domain Name**: Multiple domain names running on the same server can share the same SQLite database (`contact.sqlite`). The `users` table enforces separation on `domain` (`INDEX idx_user_domain (domain)`), so each domain maintains its own independent host user and password.
- **Login Rate Limiting**: Up to 3 failed login attempts per IP address per domain. Once exceeded, login is locked for 6 hours (21,600 seconds) with an automatic countdown timer.

---

### Quick Installation Steps

#### Automated Git Clone & One-Step Installation (Recommended)

You can install the entire suite directly from the git repository:

```bash
# 1. Clone the repository
git clone https://github.com/addressbar-net/contact.git
cd contact

# 2. Run the interactive installer (prompts for target directory, message directory, and password):
sudo ./install.sh

# Or run non-interactively with your target directory, persistent message storage, and password:
sudo ./install.sh -D yourdomain.com -d /var/www/html/contact -m /var/lib/contact -p 'YourSecurePassword123' -y

# Serving from volatile /tmp while keeping messages safely on persistent disk:
sudo ./install.sh -D yourdomain.com -d /tmp/contact -m /var/lib/contact_messages -p 'YourSecurePassword123' -y
```

The Git installer automatically:
1. Verifies server dependencies (`PHP-FPM`, `PHP-SQLite3`) without touching web server configuration.
2. Creates the persistent message storage directory on disk and initializes the `contact.sqlite` message database.
3. Deploys the application files directly into the target directory provided (e.g. `contact/`), never overwriting the root `index.php` of your existing website.
4. Configures `db_config.php` to point to the persistent message storage directory on disk.
5. Secures the administrator password with standard bcrypt hashing directly in SQLite.
6. Installs CLI helper utilities (`update_password`, `set_user_password`, `create_user`, `set_password`) to `/usr/local/bin` (CLI only).

To update an existing installation to the latest version at any time:
```bash
git pull
sudo ./install.sh --update -d /var/www/html/contact -m /var/lib/contact -p 'YourPassword' -e admin@yourdomain.com
```

---

### Command Line Management Utilities (CLI Only)

After installation, administrative utilities are available globally in `/usr/local/bin` (and blocked from web access):

```bash
# Update administrator password interactively (with hidden input):
update_password

# Or update password directly via command-line argument:
update_password -p "NewSecurePassword123"

# Update password for a specific user:
update_password -u admin -p "NewSecurePassword123"
```

---

### Browser Push Notifications & Progressive Web App (PWA)

`addr.bar` uses modern **W3C Web Push Notifications** and runs as a full **Progressive Web App (PWA)**. It requires **zero email**, **zero SMTP**, and **zero Postfix/sendmail**.

#### 1. Installing as a Desktop or Mobile App (PWA)
- **Desktop (Chrome, Edge, Brave)**: Click the **Install** icon in your browser address bar or click `[ 📲 Install App ]` inside the interface. It installs as a native, standalone window with its own icon.
- **iPhone / iPad (iOS 16.4+ Safari)**: Tap the **Share** button in Safari, then tap **Add to Home Screen**. It launches as a full-screen app.
- **Android (Chrome)**: Tap the browser menu and select **Install app** or **Add to Home screen**.

#### 2. Enabling Instant Push Notifications
1. Log in to your `addr.bar` site.
2. Click `[ 🔔 Push Notifications ]` in the top bar or under **Communication Controls**.
3. When prompted by your browser or operating system, click **Allow**.
4. When incoming messages arrive from peer websites, your device will receive an instant OS-level push notification—even when the website or browser is closed!

#### Troubleshooting & Audit Logs:
- Push notification dispatch log: `/tmp/addr_bar_push_notification.log` (records dispatch status and delivery count)
