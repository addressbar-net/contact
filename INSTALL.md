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
- `set_client_admin.php`: Command-line interface (CLI only) utility to designate or revoke Client Administrator privileges for a client.
- `set_user_password.php`: Command-line interface (CLI only) utility to update host credentials directly in the SQLite database.
- `create_user.php`: Command-line interface (CLI only) utility to initialize host credentials in the SQLite database.
- `set_password.php`: Command-line interface (CLI only) utility to set host password.
- `schema.sql`: Full SQLite schema script defining all tables (`users` with domain scoping, `login_attempts` with 6-hour lock tracking, `site_settings`, `blocked_sites`, `incoming_messages`, `sent_messages`), indexes, and default initial seed data.
- `install.sh`: Automated one-step zero-configuration installer script for git-based deployments and updates.
- `uninstall.sh`: Complete uninstallation script to safely purge CLI binaries, web files, and package stores while preserving `/var/lib/tricurse`.
- `INSTALL.md`: This installation guide.

---

### Security & Storage Architecture

- **Pre-Message Destination Website Availability Check**: Senders can verify whether another website is online, reachable, and ready to accept messages before writing. Clicking `[ 🔍 Check ]` or `[ 🔍 Check Availability ]` verifies the target destination in real-time, displaying availability status, remaining daily quotas, or error reasons. Both root installations (e.g. `novy.addr.bar`) and subdirectory installations (e.g. `tricurse.com/contact`) are resolved automatically.
- **Client Removal & Relocation Cleanup (`remove_client`)**: Easily remove old or relocated client installations from the server registry, configuration, and SQLite database. Stale records where `index.php` is no longer present can be cleaned with `remove_client --clean-orphans` or through the web GUI.
- **Per-Domain Groups Architecture**: Instead of having per-domain clients, the system uses per-domain groups. Each domain (or subdomain) functions as an autonomous group with its own designated Main Contact Point (`server_role = 'main'`).
- **Recipient Folders & Single SSL Verification**: Recipients within a domain are designated as folders (e.g. `novy.band/jason` and `novy.band/xenia`). A single SSL certificate on the domain verifies all recipient folders without requiring subdomains or wildcard certificates.
- **Consolidated Outbound Transmission & Soft Inbound Fan-Out**: When mail is delivered to more than one recipient folder in a group at the same time, only **one copy** is transmitted across the wire in a single HTTP POST request. Upon arrival, the script softly delivers the letter into each targeted recipient folder's inbox in that group's database.
- **Multi-Group Servers with Separate Installations**: More than one group can fit on the same server. **Each group must have a separate installation**—with its own web directory (e.g. `/var/www/novy.band/contact`), its own dedicated SQLite database storage (`/var/lib/tricurse/contact/<domain>/contact.sqlite`), and its own configuration (`/etc/tricurse/<domain>/contact.conf`). Groups on the same server remain fully isolated from one another.
- **Simultaneous Multi-Group Server Updater (`contact_update_all`)**: Updates all domain group installations registered on the server simultaneously in a single command, automatically maintaining all push notification subscriptions and VAPID key pairs intact.
- **Server Installation Registry (`/etc/tricurse/installations.json`)**: Keeps a persistent record of all domain groups, roles, database paths, and website directories installed on the server. Viewable anytime with `contact_list` or `./install.sh --list`.
- **Outside vs. Inside Dual Rate Limits**: Provides distinct message quotas for remote websites (outside) versus local server subdomains / loopback peers (inside) identified via `/etc/hosts`.
- **Enforced Database Storage Outside Web Root**: The installer strictly requires the persistent message storage and database directory (`-m <messages_dir>`) to be located outside the public web root (default: `/var/lib/tricurse/contact`). Storing the SQLite database inside the web root is rejected to prevent direct HTTP file downloads of `contact.sqlite`.
- **Local Server Loopback Support**: Offers automatic addition of domains and subdomains to `/etc/hosts` (`127.0.0.1 <domain>`), enabling clients on the same machine to exchange letters instantly with zero external network latency and no NAT hairpinning issues.
- **Directory-Based Existing Website Deployment**: The program installs front-end assets directly into the directory of your existing website. Your pre-existing web server (Nginx, Apache, Caddy, etc.) handles all HTTP/HTTPS routing, PHP execution, and SSL certificates automatically without modifying web server configuration files.
- **Administrator Password Set on Install & CLI-Only Updates**: The administrator password is provided and configured directly on the command line during installation (`-p <password>` or interactive prompt with hidden input). There are zero password reset links or forms on the web interface. To update the password at any time, run `update_password` from the server terminal.
- **Multi-Client Support on the Same Domain & Shared Group Storage**: Multiple client accounts can exist on the same domain or subdomain as recipient folders (e.g. `novy.band/jason`, `novy.band/xenia`), sharing the group's SQLite database (`contact.sqlite`). The `users` table enforces credentials on `(username, domain)`, while one client can be designated via `set_client_admin` to access the Main Contact Point.
- **Client Physical Login Access via `?folder=`**: Physical login for client accounts is accessed at `https://<domain>/hotletter/?folder=<username>`. Senders address the client cleanly as `<domain>/<username>`.
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

# Or run non-interactively with your group domain, target directory, persistent message storage, and password:
sudo ./install.sh -D novy.band -d /var/www/novy.band/hotletter -m /var/lib/tricurse/contact/novy.band -p 'YourSecurePassword123' -H -y

# Serving from volatile /tmp while keeping messages safely on persistent disk:
sudo ./install.sh -D novy.band -d /tmp/hotletter -m /var/lib/tricurse/contact/novy.band -p 'YourSecurePassword123' -y
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
# Designate 'jason' as the Client Administrator for novy.band:
set_client_admin -D novy.band -u jason

# Check Client Administrator status:
set_client_admin -D novy.band -s

# Revoke Client Administrator privileges:
set_client_admin -D novy.band -r

# Add client accounts to the domain group:
create_user -D novy.band -u jason -p "SecurePass123" -y
create_user -D novy.band -u xenia -p "SecurePass456" -y

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

#### Uninstallation (`uninstall.sh`)

```bash
# Uninstall a particular domain installation:
sudo ./uninstall.sh -D novy.addr.bar
sudo ./uninstall.sh -D novy.addr.bar -y

# Interactive uninstallation (select a domain or full removal):
sudo ./uninstall.sh

# Preview actions without deleting (dry-run):
sudo ./uninstall.sh --dry-run -D novy.addr.bar

# Complete uninstallation across all domains and CLI utilities:
sudo ./uninstall.sh --all -y

# Keep databases and message stores while removing code and configs:
sudo ./uninstall.sh -D novy.addr.bar --keep-data -y
```

> [!NOTE]
> - When uninstalling a specific domain, other domain installations and CLI utilities (if other domains remain) are safely preserved.
> - The uninstaller strictly removes only this package's storage (`/var/lib/tricurse/contact`) and **never deletes `/var/lib/tricurse` itself**, preserving any other packages installed in that directory.

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
