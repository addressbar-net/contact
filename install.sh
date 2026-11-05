#!/usr/bin/env bash
# ==============================================================================
# addr.bar - Automated Git Installation & Deployment Suite (SQLite Edition)
# ==============================================================================
# Zero-configuration, one-step installer for addr.bar Remote Server Directory and
# Federated Inter-Site Mail System. Powered by SQLite direct file storage.
# Zero database server daemon, zero database passwords, zero network setup required.
#
# Usage:
#   git clone https://github.com/addressbar-net/contact.git
#   cd contact
#   sudo ./install.sh [options]
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "")"
if [ -n "$SCRIPT_DIR" ] && [ -d "${SCRIPT_DIR}/script/contact" ]; then
    APP_SRC="${SCRIPT_DIR}/script/contact"
elif [ -n "$SCRIPT_DIR" ] && [ -f "${SCRIPT_DIR}/index.php" ]; then
    APP_SRC="${SCRIPT_DIR}"
else
    APP_SRC="${SCRIPT_DIR:-.}"
fi

# If executed via curl | bash or without local files, auto-clone source files
if [ ! -f "${APP_SRC}/index.php" ]; then
    REPO_URL="${ADDRBAR_REPO_URL:-https://github.com/addressbar-net/contact.git}"
    TEMP_SRC_DIR="$(mktemp -d /tmp/contact_install_XXXXXX)"
    trap 'rm -rf "$TEMP_SRC_DIR"' EXIT
    if command -v git &>/dev/null; then
        echo -e "${CYAN}Source files not found locally. Fetching latest package from ${REPO_URL}...${RESET}"
        git clone --depth 1 "$REPO_URL" "$TEMP_SRC_DIR" >/dev/null 2>&1 || true
    fi
    if [ -d "${TEMP_SRC_DIR}/script/contact" ]; then
        APP_SRC="${TEMP_SRC_DIR}/script/contact"
    elif [ -f "${TEMP_SRC_DIR}/index.php" ]; then
        APP_SRC="${TEMP_SRC_DIR}"
    fi
fi

# Default configuration values
DOMAIN=""
WEBROOT=""
MESSAGES_DIR=""
USER_NAME="admin"
USER_PASS=""
USER_EMAIL=""
DB_PATH=""
SKIP_SYSTEM=false
SKIP_DB=false
UPDATE_MODE=false
NON_INTERACTIVE=false

# ANSI colors
BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
CYAN="\033[0;36m"
RED="\033[0;31m"
RESET="\033[0m"

# Print banner
echo -e "${BOLD}${CYAN}"
echo "=================================================================="
echo "    addr.bar Federated Server & Directory Suite (SQLite)          "
echo "=================================================================="
echo -e "${RESET}"

show_help() {
    cat << EOF
Usage: sudo ./install.sh [OPTIONS]

Zero-configuration one-step installer for the addr.bar Federated Correspondence Suite.
Direct file access using SQLite—no MariaDB/MySQL database server required!
Installs directly into the directory provided—never overwriting your existing site's index.php.

Options:
  -d, -w, --dir, --directory PATH
                               Target directory for installation (e.g. /var/www/html/contact or contact/)
  -m, --messages-dir PATH      Persistent message and database storage directory on disk (REQUIRED, e.g. /var/lib/contact)
  -p, --password PASS          Administrator password (REQUIRED for initial install)
  -e, --email EMAIL            Client administrator notification email (REQUIRED for initial install)
  -D, --domain DOMAIN          Domain name or hostname of your existing website (default: detected hostname)
  -u, --user USER              Initial administrator username (default: admin)
      --db-path PATH           Explicit SQLite database file path (default: <messages-dir>/contact.sqlite)
      --skip-system            Skip system package verification
      --skip-db                Skip database initialization
  -U, --update                 Update an existing installation from git (git pull + sync)
  -y, --yes                    Non-interactive mode (requires -d/-w, -m, -p, -e for initial install)
  -h, --help                   Display this help message and exit

Examples:
  # Guided interactive installation into contact/ directory:
  sudo ./install.sh

  # Non-interactive installation into contact/ directory:
  sudo ./install.sh -D mydomain.com -d /var/www/html/contact -m /var/lib/contact -p 'MySecretPass123' -e admin@mydomain.com -y

  # Serving from volatile /tmp while keeping messages safely on disk:
  sudo ./install.sh -D mydomain.com -d /tmp/contact -m /var/lib/contact_messages -p 'MySecretPass123' -e admin@mydomain.com -y

  # Update an existing installation:
  sudo ./install.sh --update -d /var/www/html/contact -m /var/lib/contact -p 'MySecretPass123' -e admin@mydomain.com
EOF
    exit 0
}

# Parse command line options
while [[ $# -gt 0 ]]; do
    case "$1" in
        -D|--domain)
            DOMAIN="$2"; shift 2 ;;
        -d|-w|--dir|--directory|--webroot|--target-dir)
            WEBROOT="$2"; shift 2 ;;
        -m|--messages-dir|--data-dir|--storage-dir)
            MESSAGES_DIR="$2"; shift 2 ;;
        -u|--user)
            USER_NAME="$2"; shift 2 ;;
        -p|--password)
            USER_PASS="$2"; shift 2 ;;
        -e|--email)
            USER_EMAIL="$2"; shift 2 ;;
        --db-path)
            DB_PATH="$2"; shift 2 ;;
        --skip-system)
            SKIP_SYSTEM=true; shift ;;
        --skip-db)
            SKIP_DB=true; shift ;;
        -U|--update)
            UPDATE_MODE=true; shift ;;
        -y|--yes)
            NON_INTERACTIVE=true; shift ;;
        -h|--help)
            show_help ;;
        *)
            echo -e "${RED}Unknown option: $1${RESET}" >&2
            echo "Run ./install.sh --help for usage." >&2
            exit 1 ;;
    esac
done

# Check root privileges if system modifications are required
IS_ROOT=false
if [ "$(id -u)" -eq 0 ]; then
    IS_ROOT=true
fi

if [ "$IS_ROOT" = false ] && [ "$SKIP_SYSTEM" = false ]; then
    echo -e "${YELLOW}Notice: Running as non-root user. System package installations will be skipped unless run with sudo.${RESET}"
    SKIP_SYSTEM=true
fi

# Detect domain if not provided
if [ -z "$DOMAIN" ]; then
    if [ "$NON_INTERACTIVE" = true ]; then
        DOMAIN="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "localhost")"
    else
        DEFAULT_DOM="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "localhost")"
        read -r -p "Enter your pre-existing website domain name [${DEFAULT_DOM}]: " INPUT_DOM
        DOMAIN="${INPUT_DOM:-$DEFAULT_DOM}"
    fi
fi
DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's|^https\?://||' -e 's|/.*$||')"

# Detect document root / location of pre-existing website
DETECTED_DOCROOT=""
if [ -d /etc/nginx ]; then
    for conf in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf /etc/nginx/sites-available/*; do
        [ -f "$conf" ] || continue
        if [ -n "$DOMAIN" ] && grep -qi "server_name.*[[:space:]]${DOMAIN}" "$conf" 2>/dev/null; then
            r="$(grep -E "^[[:space:]]*root[[:space:]]+" "$conf" 2>/dev/null | head -n1 | awk '{print $2}' | tr -d ';\"'\''')"
            [ -n "$r" ] && [ -d "$r" ] && DETECTED_DOCROOT="$r" && break
        fi
    done
    if [ -z "$DETECTED_DOCROOT" ]; then
        for conf in /etc/nginx/sites-enabled/* /etc/nginx/sites-available/default; do
            [ -f "$conf" ] || continue
            r="$(grep -E "^[[:space:]]*root[[:space:]]+" "$conf" 2>/dev/null | head -n1 | awk '{print $2}' | tr -d ';\"'\''')"
            [ -n "$r" ] && [ -d "$r" ] && DETECTED_DOCROOT="$r" && break
        done
    fi
fi
if [ -z "$DETECTED_DOCROOT" ]; then
    if [ -n "$DOMAIN" ] && [ -d "/var/www/${DOMAIN}" ]; then
        DETECTED_DOCROOT="/var/www/${DOMAIN}"
    elif [ -d "/var/www/html" ]; then
        DETECTED_DOCROOT="/var/www/html"
    fi
fi

DETECTED_DOCROOT="${DETECTED_DOCROOT%/}"
if [ -n "$DETECTED_DOCROOT" ]; then
    if [[ "$DETECTED_DOCROOT" == */contact ]]; then
        DEFAULT_TARGET_DIR="$DETECTED_DOCROOT"
    else
        DEFAULT_TARGET_DIR="${DETECTED_DOCROOT}/contact"
    fi
else
    DEFAULT_TARGET_DIR="/var/www/html/contact"
fi

# Require target directory for installation
if [ -z "$WEBROOT" ]; then
    if [ "$NON_INTERACTIVE" = true ]; then
        WEBROOT="$DEFAULT_TARGET_DIR"
    else
        echo ""
        echo -e "${BOLD}Target Directory Configuration:${RESET}"
        echo "Specify the directory where the application will be installed (e.g. ${DEFAULT_TARGET_DIR} or contact/)."
        echo "The installation script writes files to this directory, never overwriting the index.php of your existing website."
        read -r -p "Installation directory [${DEFAULT_TARGET_DIR}]: " INPUT_WEBROOT
        INPUT_WEBROOT="$(echo "$INPUT_WEBROOT" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        WEBROOT="${INPUT_WEBROOT:-$DEFAULT_TARGET_DIR}"
    fi
fi

# Resolve relative path against detected website root or current dir
if [[ "$WEBROOT" != /* ]]; then
    CLEAN_REL="${WEBROOT#/}"
    CLEAN_REL="${CLEAN_REL%/}"
    if [ -n "$DETECTED_DOCROOT" ] && [ -d "$DETECTED_DOCROOT" ]; then
        if [[ "$DETECTED_DOCROOT" == */contact ]] && [ "$CLEAN_REL" = "contact" ]; then
            WEBROOT="$DETECTED_DOCROOT"
        else
            WEBROOT="${DETECTED_DOCROOT}/${WEBROOT}"
        fi
    else
        WEBROOT="$(pwd)/${WEBROOT}"
    fi
fi
WEBROOT="$(realpath -m "$WEBROOT" 2>/dev/null || echo "$WEBROOT")"
WEBROOT="${WEBROOT%/}"

# Require persistent message storage directory on disk
if [ -z "$MESSAGES_DIR" ]; then
    if [ "$NON_INTERACTIVE" = true ]; then
        echo -e "${RED}Error: Message storage directory is required (-m /path/to/messages or --messages-dir /path/to/messages).${RESET}" >&2
        echo "The client must specify a separate directory for storing messages on disk (e.g. /var/lib/contact)." >&2
        exit 1
    else
        while [ -z "$MESSAGES_DIR" ]; do
            echo ""
            echo -e "${BOLD}Persistent Message Storage Configuration:${RESET}"
            echo "Specify a separate directory for storing messages on disk (keeps data safe even if web root is in /tmp):"
            read -r -p "Message storage directory path: " INPUT_MESSAGES_DIR
            INPUT_MESSAGES_DIR="$(echo "$INPUT_MESSAGES_DIR" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
            if [ -n "$INPUT_MESSAGES_DIR" ]; then
                MESSAGES_DIR="$INPUT_MESSAGES_DIR"
            else
                echo -e "${RED}Error: You must specify a message storage directory.${RESET}"
            fi
        done
    fi
fi
MESSAGES_DIR="${MESSAGES_DIR%/}"

# Target SQLite database path in message storage directory
if [ -z "$DB_PATH" ]; then
    DB_PATH="${MESSAGES_DIR}/contact.sqlite"
fi

# Require administrator password (must be provided during installation)
if [ "$UPDATE_MODE" = false ]; then
    if [ -z "$USER_PASS" ]; then
        if [ "$NON_INTERACTIVE" = true ]; then
            echo -e "${RED}Error: Administrator password is required (-p <password> or --password <password>).${RESET}" >&2
            echo "During installation, an administrator password must be provided to access the host control panel." >&2
            exit 1
        else
            while [ -z "$USER_PASS" ]; do
                echo ""
                echo -e "${BOLD}Administrator Password Configuration:${RESET}"
                echo "The administrator password must be set during installation to secure your control panel."
                read -r -s -p "Enter administrator password: " INPUT_PASS
                echo ""
                if [ -z "$INPUT_PASS" ]; then
                    echo -e "${RED}Error: Password cannot be empty. Please try again.${RESET}"
                    continue
                fi
                read -r -s -p "Confirm administrator password: " CONFIRM_PASS
                echo ""
                if [ "$INPUT_PASS" != "$CONFIRM_PASS" ]; then
                    echo -e "${RED}Error: Passwords do not match. Please try again.${RESET}"
                else
                    USER_PASS="$INPUT_PASS"
                fi
            done
        fi
    fi
fi


# ------------------------------------------------------------------------------
# STEP 0: UPDATE MODE (IF REQUESTED)
# ------------------------------------------------------------------------------
if [ "$UPDATE_MODE" = true ]; then
    echo -e "\n${BOLD}${GREEN}==> Updating addr.bar from git...${RESET}"
    if [ -d "${SCRIPT_DIR}/.git" ]; then
        if git -C "${SCRIPT_DIR}" remote get-url origin >/dev/null 2>&1; then
            echo "Pulling latest git changes..."
            git -C "${SCRIPT_DIR}" pull --rebase || echo "git pull completed or skipped."
        else
            echo "Local git repository detected (no remote origin configured)."
        fi
    fi
fi

# ------------------------------------------------------------------------------
# STEP 1: SYSTEM PACKAGES & DEPENDENCIES (LIGHTWEIGHT - NO MARIADB!)
# ------------------------------------------------------------------------------
if [ "$SKIP_SYSTEM" = false ] && [ "$IS_ROOT" = true ]; then
    echo -e "\n${BOLD}${GREEN}==> Step 1: Checking server dependencies...${RESET}"
    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        MISSING_PKGS=()
        for pkg in curl unzip php-fpm php-cli php-sqlite3 sqlite3 fail2ban; do
            dpkg -s "$pkg" >/dev/null 2>&1 || MISSING_PKGS+=("$pkg")
        done
        if [ ${#MISSING_PKGS[@]} -gt 0 ]; then
            apt-get update -y || true
            apt-get install -y "${MISSING_PKGS[@]}" || true
        fi
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y curl unzip php-fpm php-cli php-sqlite3 sqlite || true
    elif command -v yum >/dev/null 2>&1; then
        yum install -y curl unzip php-fpm php-cli php-sqlite3 sqlite || true
    fi
    echo "✓ Server dependencies verified."
else
    echo -e "\n${BOLD}==> Step 1: System packages step skipped (--skip-system or non-root).${RESET}"
fi

# ------------------------------------------------------------------------------
# STEP 2: PROVISION PERSISTENT MESSAGE STORAGE DIRECTORY ON DISK
# ------------------------------------------------------------------------------
mkdir -p "${MESSAGES_DIR}"
chmod 775 "${MESSAGES_DIR}" 2>/dev/null || true

if [ "$SKIP_DB" = false ]; then
    echo -e "\n${BOLD}${GREEN}==> Step 2: Provisioning persistent message storage on disk (${DB_PATH})...${RESET}"
    SCHEMA_FILE="${APP_SRC}/schema.sql"
    if [ -f "$SCHEMA_FILE" ] && command -v sqlite3 >/dev/null 2>&1; then
        echo "Initializing SQLite message database from schema.sql..."
        sqlite3 "$DB_PATH" < "$SCHEMA_FILE" 2>/dev/null || true
        echo "✓ SQLite message storage ready: ${DB_PATH}"
    else
        echo "✓ SQLite message storage will be initialized on first launch in ${MESSAGES_DIR}."
    fi
fi

# ------------------------------------------------------------------------------
# STEP 3: DEPLOY FRONT-END APPLICATION TO TARGET DIRECTORY
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}${GREEN}==> Step 3: Deploying application to directory (${WEBROOT})...${RESET}"
mkdir -p "${WEBROOT}"

# Protect any existing non-addr.bar index.php in the target directory
if [ -f "${WEBROOT}/index.php" ] && ! grep -qi "addr.bar" "${WEBROOT}/index.php" 2>/dev/null; then
    BACKUP_FILE="${WEBROOT}/index.php.pre_contact_backup.$(date +%s)"
    cp -p "${WEBROOT}/index.php" "$BACKUP_FILE" 2>/dev/null || true
    echo -e "${YELLOW}Notice: Backed up existing non-contact index.php to ${BACKUP_FILE}.${RESET}"
fi

# Copy application files from APP_SRC
cp -p "${APP_SRC}/index.php" "${WEBROOT}/index.php"
cp -p "${APP_SRC}/db.php" "${WEBROOT}/db.php"
cp -p "${APP_SRC}/auth.php" "${WEBROOT}/auth.php"
cp -p "${APP_SRC}/signup.html" "${WEBROOT}/signup.html"
cp -p "${APP_SRC}/schema.sql" "${WEBROOT}/schema.sql"
cp -p "${APP_SRC}/update_password.php" "${WEBROOT}/update_password.php"
cp -p "${APP_SRC}/set_password.php" "${WEBROOT}/set_password.php"
cp -p "${APP_SRC}/set_user_password.php" "${WEBROOT}/set_user_password.php"
cp -p "${APP_SRC}/create_user.php" "${WEBROOT}/create_user.php"
cp -p "${APP_SRC}/install.sh" "${WEBROOT}/install.sh" 2>/dev/null || true
# Compute URL subpath relative to webroot
URL_SUBPATH=""
if [ -n "$DETECTED_DOCROOT" ] && [[ "$WEBROOT" == "$DETECTED_DOCROOT"* ]]; then
    URL_SUBPATH="${WEBROOT#$DETECTED_DOCROOT}"
else
    DIR_NAME="$(basename "$WEBROOT")"
    [ -n "$DIR_NAME" ] && URL_SUBPATH="/${DIR_NAME}"
fi
[ -n "$URL_SUBPATH" ] && [[ "$URL_SUBPATH" != /* ]] && URL_SUBPATH="/${URL_SUBPATH}"

APP_SCOPE="${URL_SUBPATH:-/}"
[[ "$APP_SCOPE" != */ ]] && APP_SCOPE="${APP_SCOPE}/"
APP_DIR_LABEL="$(basename "$WEBROOT")"
[ "$APP_DIR_LABEL" = "/" ] && APP_DIR_LABEL=""
APP_DISPLAY_NAME="${DOMAIN}${APP_DIR_LABEL:+ (${APP_DIR_LABEL})}"

# Copy Service Worker & PWA icon assets
cp -p "${APP_SRC}/sw.js" "${WEBROOT}/sw.js" 2>/dev/null || true
cp -p "${APP_SRC}/icon-192.png" "${WEBROOT}/icon-192.png" 2>/dev/null || true
cp -p "${APP_SRC}/icon-512.png" "${WEBROOT}/icon-512.png" 2>/dev/null || true

# Generate site-specific manifest.json locked to the target domain and directory
cat > "${WEBROOT}/manifest.json" << EOF
{
  "name": "${APP_DISPLAY_NAME}",
  "short_name": "${DOMAIN}",
  "description": "Federated Messaging Desktop for ${DOMAIN}",
  "id": "${APP_SCOPE}",
  "start_url": "index.php",
  "scope": "./",
  "display": "standalone",
  "orientation": "any",
  "background_color": "#ffffff",
  "theme_color": "#000000",
  "icons": [
    {
      "src": "icon-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "icon-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ]
}
EOF
chmod 644 "${WEBROOT}/manifest.json" 2>/dev/null || true

# Generate local db_config.php in WEBROOT connecting front-end to MESSAGES_DIR on disk
cat > "${WEBROOT}/db_config.php" << EOF
<?php
// addr.bar Database & Persistent Message Storage Configuration
// Front-end files can reside anywhere (e.g. /tmp), while all messages remain safely on disk.
\$messages_dir = '${MESSAGES_DIR}';
\$data_dir     = '${MESSAGES_DIR}';
\$sqlite_path  = '${DB_PATH}';
\$db_path      = \$sqlite_path;
EOF
chmod 644 "${WEBROOT}/db_config.php" 2>/dev/null || true
echo "✓ Connected front-end webroot to message storage: ${MESSAGES_DIR}"

# Set proper ownership and permissions
if [ "$IS_ROOT" = true ]; then
    WWW_USER="www-data"
    id -u www-data >/dev/null 2>&1 || WWW_USER="nginx"
    id -u "$WWW_USER" >/dev/null 2>&1 || WWW_USER="$(id -un)"
    chown -R "${WWW_USER}:${WWW_USER}" "${WEBROOT}" 2>/dev/null || true
    chown -R "${WWW_USER}:${WWW_USER}" "${MESSAGES_DIR}" 2>/dev/null || true
    if [ -f "$DB_PATH" ]; then
        chown "${WWW_USER}:${WWW_USER}" "$DB_PATH" 2>/dev/null || true
        chmod 664 "$DB_PATH" 2>/dev/null || true
    fi
fi
chmod -R 755 "${WEBROOT}"
chmod 644 "${WEBROOT}"/*.php "${WEBROOT}"/*.html "${WEBROOT}"/*.sql "${WEBROOT}"/phpmailer/*.php 2>/dev/null || true
chmod 755 "${WEBROOT}"/update_password.php "${WEBROOT}"/set_password.php "${WEBROOT}"/set_user_password.php "${WEBROOT}"/create_user.php 2>/dev/null || true

echo "✓ Application deployed and permissions secured."

# ------------------------------------------------------------------------------
# STEP 4: INITIALIZE DOMAIN HOST USER & SET ADMINISTRATOR PASSWORD
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}${GREEN}==> Step 4: Initializing host account and setting password for domain '${DOMAIN}'...${RESET}"
IS_UPDATE_PHP="false"
[ "$UPDATE_MODE" = true ] && IS_UPDATE_PHP="true"

if command -v php >/dev/null 2>&1; then
    if php -m 2>/dev/null | grep -qi pdo_sqlite && [ -f "${WEBROOT}/db.php" ]; then
        DOMAIN="$DOMAIN" USER_NAME="$USER_NAME" USER_PASS="$USER_PASS" USER_EMAIL="$USER_EMAIL" IS_UPDATE_PHP="$IS_UPDATE_PHP" SQLITE_PATH="$DB_PATH" php -r '
            require_once "'"${WEBROOT}"'/db.php";
            $domain = getenv("DOMAIN") ?: "localhost";
            $username = getenv("USER_NAME") ?: "admin";
            $password = getenv("USER_PASS") ?: "";
            $email = getenv("USER_EMAIL") ?: "";
            $is_update = (getenv("IS_UPDATE_PHP") === "true");

            $pdo = get_db();
            if ($pdo) {
                $existing = get_or_init_domain_user($pdo, $domain);
                $user_id = $existing ? (int)$existing["id"] : null;
                $target_email = !empty($email) ? $email : ($existing["email"] ?? "");
                $session_token = bin2hex(random_bytes(32));

                if ($existing) {
                    if (!empty($password)) {
                        $hashed = password_hash($password, PASSWORD_BCRYPT);
                        $upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, password = ?, email = ?, session_token = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
                        $upd->execute([$domain, $username, $hashed, $target_email, $session_token, $user_id]);
                    } else {
                        $upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, email = ? WHERE id = ?");
                        $upd->execute([$domain, $username, $target_email, $user_id]);
                    }
                    $pdo->prepare("DELETE FROM users WHERE id != ?")->execute([$user_id]);
                } else {
                    $hashed = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("INSERT INTO users (domain, username, password, email, session_token) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$domain, $username, $hashed, $target_email, $session_token]);
                }
                try {
                    $pdo->exec("DELETE FROM login_attempts");
                } catch (Exception $e) {}
            }
        '
    fi

    echo "✓ Configured single host user '${USER_NAME}'."
    echo "✓ Administrator password securely set in SQLite database (bcrypt)."
fi

# ------------------------------------------------------------------------------
# STEP 5: INSTALL CLI BINARIES TO /usr/local/bin
# ------------------------------------------------------------------------------
if [ -w /usr/local/bin ] || [ "$IS_ROOT" = true ]; then
    echo -e "\n${BOLD}${GREEN}==> Step 5: Installing CLI helper utilities to /usr/local/bin...${RESET}"
    mkdir -p /usr/local/bin
    
    # Wrapper for update_password
    cat > /usr/local/bin/update_password << EOF
#!/usr/bin/env bash
export SQLITE_PATH="${DB_PATH}"
export MESSAGES_DIR="${MESSAGES_DIR}"
TARGET_SCRIPT="${WEBROOT}/update_password.php"
[ -f "\$TARGET_SCRIPT" ] || TARGET_SCRIPT="${APP_SRC}/update_password.php"
exec php "\$TARGET_SCRIPT" "\$@"
EOF
    chmod 755 /usr/local/bin/update_password

    # Wrapper for set_user_password
    cat > /usr/local/bin/set_user_password << EOF
#!/usr/bin/env bash
export SQLITE_PATH="${DB_PATH}"
export MESSAGES_DIR="${MESSAGES_DIR}"
TARGET_SCRIPT="${WEBROOT}/set_user_password.php"
[ -f "\$TARGET_SCRIPT" ] || TARGET_SCRIPT="${APP_SRC}/set_user_password.php"
exec php "\$TARGET_SCRIPT" "\$@"
EOF
    chmod 755 /usr/local/bin/set_user_password

    # Wrapper for create_user
    cat > /usr/local/bin/create_user << EOF
#!/usr/bin/env bash
export SQLITE_PATH="${DB_PATH}"
export MESSAGES_DIR="${MESSAGES_DIR}"
TARGET_SCRIPT="${WEBROOT}/create_user.php"
[ -f "\$TARGET_SCRIPT" ] || TARGET_SCRIPT="${APP_SRC}/create_user.php"
exec php "\$TARGET_SCRIPT" "\$@"
EOF
    chmod 755 /usr/local/bin/create_user

    # Wrapper for set_password
    cat > /usr/local/bin/set_password << EOF
#!/usr/bin/env bash
export SQLITE_PATH="${DB_PATH}"
export MESSAGES_DIR="${MESSAGES_DIR}"
TARGET_SCRIPT="${WEBROOT}/set_password.php"
[ -f "\$TARGET_SCRIPT" ] || TARGET_SCRIPT="${APP_SRC}/set_password.php"
exec php "\$TARGET_SCRIPT" "\$@"
EOF
    chmod 755 /usr/local/bin/set_password

    echo "✓ Installed commands: update_password, set_user_password, create_user, set_password"
fi

# ------------------------------------------------------------------------------
# STEP 6: SUMMARY & ACCESS INSTRUCTIONS
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}${GREEN}=================================================================="
echo "    INSTALLATION & DEPLOYMENT COMPLETE! (SQLite Edition)          "
echo -e "==================================================================${RESET}"
echo -e "Website Directory:      ${BOLD}${WEBROOT}${RESET}"
echo -e "Message Storage (Disk): ${BOLD}${MESSAGES_DIR}${RESET}"
echo -e "Database File:          ${BOLD}${DB_PATH}${RESET}"
echo -e "Website Host / Domain:  ${BOLD}${DOMAIN}${RESET}"
echo -e "Host Username:          ${BOLD}${USER_NAME}${RESET}"
echo -e "Administrator Password: ${BOLD}(configured during install)${RESET}"

URL_SUBPATH=""
if [ -n "$DETECTED_DOCROOT" ] && [[ "$WEBROOT" == "$DETECTED_DOCROOT"* ]]; then
    URL_SUBPATH="${WEBROOT#$DETECTED_DOCROOT}"
else
    DIR_NAME="$(basename "$WEBROOT")"
    [ -n "$DIR_NAME" ] && URL_SUBPATH="/${DIR_NAME}"
fi
[ -n "$URL_SUBPATH" ] && [[ "$URL_SUBPATH" != /* ]] && URL_SUBPATH="/${URL_SUBPATH}"

echo ""
echo "Access your installation from your existing website:"
echo "  Web GUI:      https://${DOMAIN}${URL_SUBPATH}/index.php"
echo "  Login Screen: https://${DOMAIN}${URL_SUBPATH}/signup.html"
echo ""
echo "Command Line Management Utilities (CLI Only - Blocked on Web):"
echo "  update_password                              # Interactive password update utility"
echo "  update_password   -p <new-password>          # Update administrator password directly"
echo "  set_user_password -u ${USER_NAME} -p <new-password> -y"
echo "  create_user       -u <username> -p <password> -y"

echo ""
echo "Browser Push Notifications & Progressive Web App (PWA):"
echo "  1. Desktop / Mobile App: Tap '[ 📲 Install App ]' to install addr.bar to your device."
echo "  2. Browser Push Alerts:  Click '[ 🔔 Push Notifications ]' to receive instant alerts."
echo "  3. Zero email, zero SMTP, and zero external mail services required."

echo ""
echo "Git Updates:"
echo "  To update this installation with future git updates, run:"
echo "    git pull"
echo "    sudo ./install.sh --update -d ${WEBROOT} -m ${MESSAGES_DIR} -p <password>"
echo "=================================================================="
