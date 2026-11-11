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
- `update_client_name.php`: Command-line interface (CLI only) utility to update the administrator-configured names of existing client accounts.
- `update_password.php`: Command-line interface (CLI only) utility to set or update the administrator password directly in the SQLite database.
- `set_user_password.php`: Command-line interface (CLI only) utility to update host credentials directly in the SQLite database.
- `create_user.php`: Command-line interface (CLI only) utility to initialize host credentials in the SQLite database.
- `set_password.php`: Command-line interface (CLI only) utility to set host password.
- `schema.sql`: Full SQLite schema script defining all tables (`users` with domain scoping, `login_attempts` with 6-hour lock tracking, `site_settings`, `blocked_sites`, `incoming_messages`, `sent_messages`), indexes, and default initial seed data.
- `install.sh`: Automated one-step zero-configuration installer script for git-based deployments and updates.
- `INSTALL.md`: This installation guide.

---

### Security & Storage Architecture

- **Pre-Message Destination Website Availability Check**: Senders can verify whether another website is online, reachable, and ready to accept messages before writing. Clicking `[ 🔍 Check ]` or `[ 🔍 Check Availability ]` verifies the target destination in real-time, displaying availability status, remaining daily quotas, or error reasons (communications disabled, rate limited, blocked, or unreachable).
- **Mandatory Unique Domain or Subdomain Per Client**: Every client must have its `index.php` at a unique domain or subdomain (e.g. `client1.example.com`, `client2.example.com`). Sharing a single domain via subpaths is prohibited because SSL/TLS SNI certificates and per-website rate limiting operate per domain/hostname. Uniqueness is enforced automatically during installation by `install.sh`.
- **CLI Management Utilities Installed to `/usr/local/bin/`**: Administrative tools (`update_client_name`, `update_password`, `create_user`, `set_user_password`, `set_password`, `contact_list`, `contact_update_all`, `merge_databases`) are installed directly to `/usr/local/bin/` as executable system utilities and are strictly excluded from the web directory. Configuration is stored per domain in `/etc/tricurse/<domain>/contact.conf` to avoid name collisions.
- **Main Recipient Point vs. Individual Client Roles**: When installing contact, the administrator is prompted to decide whether the installation is for an individual client or should function as the main recipient point.
- **Main Website Subscriptions & Unified Broadcast**: Individual clients can subscribe to the main website. Whenever a federated message is sent to the main website, all individual clients automatically get a copy in their inbox.
- **Single Shared Database Architecture (The Only Option)**: All clients and contact points on the server share a single SQLite database (`/var/lib/tricurse/contact/contact.sqlite`). Messages sent to multiple clients or broadcast from the main point are stored only once on disk with zero duplicate records. Client-specific read receipts and message archives are tracked independently per domain without duplicating message content or database rows. Archived messages are moved to separate lists accessible by month and year.
- **Simultaneous Multi-Client Server Updater (`contact_update_all`)**: Updates all client installations registered on the server simultaneously in a single command, automatically maintaining all push notification subscriptions and VAPID key pairs intact.
- **Server Installation Registry (`/etc/tricurse/installations.json`)**: Keeps a persistent record of all domains, subdomains, roles, and folder locations where contact is installed on the server. Viewable anytime with `contact_list` or `./install.sh --list`.
- **Outside vs. Inside Dual Rate Limits**: Provides distinct message quotas for remote websites (outside) versus local server subdomains / loopback peers (inside) identified via `/etc/hosts`.
- **Enforced Database Storage Outside Web Root**: The installer strictly requires the persistent message storage and database directory (`-m <messages_dir>`) to be located outside the public web root (default: `/var/lib/tricurse/contact`). Storing the SQLite database inside the web root is rejected to prevent direct HTTP file downloads of `contact.sqlite`.
- **Local Server Loopback Support**: Offers automatic addition of domains and subdomains to `/etc/hosts` (`127.0.0.1 <domain>`), enabling clients on the same machine to exchange letters instantly with zero external network latency and no NAT hairpinning issues.
- **Directory-Based Existing Website Deployment**: The program installs front-end assets directly into the directory of your existing website. Your pre-existing web server (Nginx, Apache, Caddy, etc.) handles all HTTP/HTTPS routing, PHP execution, and SSL certificates automatically without modifying web server configuration files.
- **Administrator Password Set on Install & CLI-Only Updates**: The administrator password is provided and configured directly on the command line during installation (`-p <password>` or interactive prompt with hidden input). There are zero password reset links or forms on the web interface. To update the password at any time, run `update_password` from the server terminal.
- **One User Per Domain Name**: Multiple domain names running on the same server can share the same SQLite database (`contact.sqlite`). The `users` table enforces separation on `domain` (`INDEX idx_user_domain (domain)`), so each domain maintains its own independent host user and password.
- **Login Rate Limiting**: Up to 3 failed login attempts per IP address per domain. Once exceeded, login is locked for 6 hours (21,600 seconds) with an automatic countdown timer.
- **15-Year Persistent Login with Rolling Renewal**: Client sessions stay logged in for 15 years. Every active visit automatically and silently pushes the cookie expiration forward by 15 years, so existing logged-in clients never need to re-enter their password.
- **Push Notification Maintenance & Prompts**: Updates preserve all existing browser push subscriptions and VAPID key pairs. When a client signs in without push enabled, a banner prompts them to enable it. When the app is installed and launched for the first time as a standalone PWA, it automatically resets stale subscriptions and prompts the user to reactivate push notifications for the native application container. When installed as an app, the visitor must be logged in before push notifications can be activated.

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
sudo ./install.sh -D client1.example.com -d /var/www/client1 -m /var/lib/tricurse/contact/client1.example.com -p 'YourSecurePassword123' -H -y

# Serving from volatile /tmp while keeping messages safely on persistent disk:
sudo ./install.sh -D client1.example.com -d /tmp/contact -m /var/lib/tricurse/contact/client1.example.com -p 'YourSecurePassword123' -y
```

The Git installer automatically:
1. Verifies server dependencies (`PHP-FPM`, `PHP-SQLite3`) without touching web server configuration.
2. Creates the persistent message storage directory on disk and initializes the `contact.sqlite` message database.
3. Deploys the application files directly into the target directory provided (e.g. `contact/`), never overwriting the root `index.php` of your existing website.
4. Configures `db_config.php` to point to the persistent message storage directory on disk.
5. Secures the administrator password with standard bcrypt hashing directly in SQLite.
6. Installs CLI helper utilities (`update_password`, `set_user_password`, `create_user`, `set_password`, `contact_list`, `contact_update_all`) to `/usr/local/bin` (CLI only).
7. Records installation details into the server installation registry (`/etc/tricurse/installations.json`).

#### Updating Installations:

**Simultaneous Multi-Client Server Update (All Clients on Server):**
```bash
# Update all client installations across the server simultaneously:
sudo contact_update_all

# Or via install.sh directly:
git pull
sudo ./install.sh --update-all
```

**Single Installation Update:**
```bash
git pull
sudo ./install.sh --update -d /var/www/html/contact -m /var/lib/tricurse/contact/example.com
```

*Note: All existing push notifications, VAPID key pairs, inbox messages, and host credentials are fully maintained and verified during both update methods.*

---

### Command Line Management Utilities (CLI Only)

After installation, administrative utilities are available globally in `/usr/local/bin` (and blocked from web access):

```bash
# Interactively update client names or list registered clients:
update_client_name
update_client_name -l
update_client_name -D client.example.com -N "Alpha Branch Office"
update_client_name -a

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
2. If push notifications are not yet enabled for your browser or device, a prompt banner automatically appears upon host login encouraging you to activate them.
3. Click `[ 🔔 Enable Push Notifications ]` on the banner or in the top navigation bar.
4. When prompted by your browser or operating system, click **Allow**.
5. When incoming messages arrive from peer websites, your device will receive an instant OS-level push notification—even when the website or browser is closed!

#### 3. Standalone App First-Launch Reactivation & Login Requirement
When the application is installed and opened for the first time as a standalone desktop or mobile app container (PWA), any existing browser-tab push subscriptions are automatically deactivated to prevent stale subscriptions. When installed as an app, the visitor must be logged into their host account before push notifications can be activated. If a visitor opens the installed application while not logged in, the application prompts them to sign in first; once authenticated, the prompt enables activating push notifications directly for the standalone application container. Click `[ 🔔 Reactivate Push Notifications ]` to ensure desktop and mobile notifications route directly to your installed app.

#### 4. Push Notification Filtering & Delivery Options
Granular options for push notifications are available under **Communication Controls**:
- **Master Toggle**: Enable or disable all Web Push alerts on the domain.
- **Outside Sources**: Push notifications can be disabled from outside sources (remote websites over the internet). When disabled, incoming messages still arrive safely in your inbox, but push alerts will not be sounded or sent to your devices.
- **Inside Sources**: Push notifications can be toggled for inside sources (local server websites and subdomains in `/etc/hosts`).
- **Main Contact Point**: Push notifications can be disabled from the main contact point. Individual clients subscribed to a main contact point can disable this option so that broadcasts and direct alerts from the main website do not trigger push notifications, while direct messages to the client from other allowed sources still do.
- **Individual Sources**: Push notifications can be disabled from individual sources (specific peer domains or websites). When disabled, incoming messages from those sources will not trigger device push alerts, but until marked as read the inbox count will be present for messages. Individual sources can be muted or unmuted directly from inbox message cards or managed in Communication Controls.

#### Troubleshooting & Audit Logs:
- Push notification dispatch log: `/tmp/addr_bar_push_notification.log` (records dispatch status and delivery count)
