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
ADD_HOSTS=false

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
  -m, --messages-dir PATH      Persistent message and database storage directory on disk (default: /var/lib/tricurse/contact/<domain>)
  -p, --password PASS          Administrator password (REQUIRED for initial install)
  -D, --domain DOMAIN          Domain name or hostname of your existing website (default: detected hostname)
  -u, --user USER              Initial administrator username (default: admin)
  -H, --add-hosts              Add domain to /etc/hosts (127.0.0.1 <domain>) for local server loopback
      --db-path PATH           Explicit SQLite database file path (default: <messages-dir>/contact.sqlite)
      --skip-system            Skip system package verification
      --skip-db                Skip database initialization
  -U, --update                 Update an existing installation from git (git pull + sync)
  -y, --yes                    Non-interactive mode (requires -d/-w, -p for initial install)
  -h, --help                   Display this help message and exit

Examples:
  # Guided interactive installation into contact/ directory:
  sudo ./install.sh

  # Non-interactive installation with domain and custom directory:
  sudo ./install.sh -D client1.example.com -d /var/www/client1 -m /var/lib/tricurse/contact/client1.example.com -p 'MySecretPass123' -H -y

  # Serving from volatile /tmp while keeping messages safely on persistent disk:
  sudo ./install.sh -D client1.example.com -d /tmp/contact -m /var/lib/tricurse/contact/client1.example.com -p 'MySecretPass123' -y

  # Update an existing installation:
  sudo ./install.sh --update -d /var/www/html/contact -m /var/lib/tricurse/contact/client1.example.com -p 'MySecretPass123'
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
        -H|--add-hosts|--hosts)
            ADD_HOSTS=true; shift ;;
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

validate_outside_webroot() {
    local target="$1"
    local label="$2"
    local t_real
    local w_real
    local d_real

    t_real="$(realpath -m "$target" 2>/dev/null || echo "$target")"
    w_real="$(realpath -m "$WEBROOT" 2>/dev/null || echo "$WEBROOT")"

    if [ "$t_real" = "$w_real" ] || [[ "$t_real" == "$w_real"/* ]]; then
        echo -e "${RED}Security Error: The ${label} (${target}) must be located OUTSIDE the web root (${WEBROOT}).${RESET}" >&2
        echo "Storing SQLite database files inside the web root exposes them to direct public HTTP downloads." >&2
        return 1
    fi

    if [ -n "$DETECTED_DOCROOT" ]; then
        d_real="$(realpath -m "$DETECTED_DOCROOT" 2>/dev/null || echo "$DETECTED_DOCROOT")"
        if [ "$t_real" = "$d_real" ] || [[ "$t_real" == "$d_real"/* ]]; then
            echo -e "${RED}Security Error: The ${label} (${target}) must be located OUTSIDE the web server document root (${DETECTED_DOCROOT}).${RESET}" >&2
            echo "Storing SQLite database files inside the web document root exposes them to direct public HTTP downloads." >&2
            return 1
        fi
    fi

    return 0
}

# Require persistent message storage directory on disk (STRICTLY outside webroot)
DEFAULT_MESSAGES_DIR="/var/lib/tricurse/contact/${DOMAIN}"
if [ "$IS_ROOT" = false ]; then
    DEFAULT_MESSAGES_DIR="${HOME}/.local/share/tricurse/contact/${DOMAIN}"
fi

if [ -z "$MESSAGES_DIR" ]; then
    if [ "$NON_INTERACTIVE" = true ]; then
        MESSAGES_DIR="$DEFAULT_MESSAGES_DIR"
        if ! validate_outside_webroot "$MESSAGES_DIR" "database/message storage directory"; then
            echo -e "${RED}Error: Cannot proceed non-interactively with an unsafe message storage directory.${RESET}" >&2
            exit 1
        fi
    else
        while true; do
            echo ""
            echo -e "${BOLD}Persistent Message Storage Configuration:${RESET}"
            echo "Specify a separate directory for storing messages on disk."
            echo -e "${YELLOW}Security Requirement: Must be located OUTSIDE the web root to prevent public downloads of contact.sqlite.${RESET}"
            read -r -p "Message storage directory path [${DEFAULT_MESSAGES_DIR}]: " INPUT_MESSAGES_DIR
            INPUT_MESSAGES_DIR="$(echo "$INPUT_MESSAGES_DIR" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
            TARGET_M_DIR="${INPUT_MESSAGES_DIR:-$DEFAULT_MESSAGES_DIR}"
            if validate_outside_webroot "$TARGET_M_DIR" "database/message storage directory"; then
                MESSAGES_DIR="$TARGET_M_DIR"
                break
            fi
        done
    fi
else
    if ! validate_outside_webroot "$MESSAGES_DIR" "database/message storage directory"; then
        echo -e "${RED}Error: Cannot install with database storage inside the webroot.${RESET}" >&2
        exit 1
    fi
fi
MESSAGES_DIR="${MESSAGES_DIR%/}"

# Target SQLite database path in message storage directory
if [ -z "$DB_PATH" ]; then
    DB_PATH="${MESSAGES_DIR}/contact.sqlite"
fi

if ! validate_outside_webroot "$DB_PATH" "SQLite database file"; then
    echo -e "${RED}Error: Explicit database path cannot be located inside the web root.${RESET}" >&2
    exit 1
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
# STEP 1.5: LOCAL LOOPBACK CONFIGURATION (/etc/hosts)
# ------------------------------------------------------------------------------
if [ -n "$DOMAIN" ] && [ "$DOMAIN" != "localhost" ] && [ "$DOMAIN" != "127.0.0.1" ]; then
    HOSTS_FILE="/etc/hosts"
    if [ -f "$HOSTS_FILE" ]; then
        if grep -qE "[[:space:]]${DOMAIN}([[:space:]]|$)" "$HOSTS_FILE" 2>/dev/null; then
            echo "✓ Local loopback for '${DOMAIN}' is already configured in ${HOSTS_FILE}."
        else
            DO_ADD_HOSTS=false
            if [ "$ADD_HOSTS" = true ]; then
                DO_ADD_HOSTS=true
            elif [ "$NON_INTERACTIVE" = false ]; then
                echo ""
                echo -e "${BOLD}Local Loopback Configuration (/etc/hosts):${RESET}"
                echo "Adding '${DOMAIN}' to /etc/hosts allows clients on this server to deliver letters to each other"
                echo "instantly over local loopback (127.0.0.1), avoiding external network latency and NAT hairpinning."
                read -r -p "Add '127.0.0.1 ${DOMAIN}' to ${HOSTS_FILE}? [Y/n]: " RESP_HOSTS
                RESP_HOSTS="$(echo "$RESP_HOSTS" | tr '[:upper:]' '[:lower:]')"
                if [ -z "$RESP_HOSTS" ] || [ "$RESP_HOSTS" = "y" ] || [ "$RESP_HOSTS" = "yes" ]; then
                    DO_ADD_HOSTS=true
                fi
            fi

            if [ "$DO_ADD_HOSTS" = true ]; then
                if [ -w "$HOSTS_FILE" ] || [ "$IS_ROOT" = true ]; then
                    echo "127.0.0.1 ${DOMAIN} # Added by tricurse contact installer" >> "$HOSTS_FILE"
                    echo "✓ Added '127.0.0.1 ${DOMAIN}' to ${HOSTS_FILE}."
                else
                    echo -e "${YELLOW}Notice: Cannot write to ${HOSTS_FILE} (requires root privileges). Run with sudo or manually add '127.0.0.1 ${DOMAIN}' to ${HOSTS_FILE}.${RESET}"
                fi
            fi
        fi
    fi
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

# Copy only public web application files from APP_SRC to WEBROOT
cp -p "${APP_SRC}/index.php" "${WEBROOT}/index.php"
cp -p "${APP_SRC}/db.php" "${WEBROOT}/db.php"
cp -p "${APP_SRC}/auth.php" "${WEBROOT}/auth.php"
cp -p "${APP_SRC}/signup.html" "${WEBROOT}/signup.html"

# Purge CLI utilities, installers, schemas, and docs from WEBROOT (they must NOT be web-accessible)
rm -f "${WEBROOT}/update_password.php" \
      "${WEBROOT}/set_password.php" \
      "${WEBROOT}/set_user_password.php" \
      "${WEBROOT}/create_user.php" \
      "${WEBROOT}/install.sh" \
      "${WEBROOT}/schema.sql" \
      "${WEBROOT}/INSTALL.md" \
      "${WEBROOT}/README.md" 2>/dev/null || true

# If an exposed contact.sqlite exists in webroot, remove it
if [ -f "${WEBROOT}/contact.sqlite" ]; then
    echo -e "${YELLOW}Notice: Removing exposed database file from webroot (${WEBROOT}/contact.sqlite)...${RESET}"
    rm -f "${WEBROOT}/contact.sqlite" "${WEBROOT}/contact.sqlite-wal" "${WEBROOT}/contact.sqlite-shm" 2>/dev/null || true
fi
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
chmod 644 "${WEBROOT}"/*.php "${WEBROOT}"/*.html 2>/dev/null || true

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
BIN_DIR="/usr/local/bin"
if [ ! -w "$BIN_DIR" ] && [ "$IS_ROOT" = false ]; then
    BIN_DIR="${HOME}/.local/bin"
fi
mkdir -p "$BIN_DIR"

if [ -w "$BIN_DIR" ] || [ "$IS_ROOT" = true ]; then
    echo -e "\n${BOLD}${GREEN}==> Step 5: Installing CLI helper utilities directly to ${BIN_DIR}...${RESET}"
    
    # Save system-wide tricurse configuration for CLI tools
    if [ "$IS_ROOT" = true ] || [ -w /etc ]; then
        mkdir -p /etc/tricurse
        mkdir -p "/etc/tricurse/${DOMAIN}"

        # 1. Per-domain configuration file in client domain subdirectory
        cat > "/etc/tricurse/${DOMAIN}/contact.conf" << EOF
<?php
// Tricurse Contact Suite - Client Subdomain Configuration
\$messages_dir = '${MESSAGES_DIR}';
\$sqlite_path  = '${DB_PATH}';
\$webroot      = '${WEBROOT}';
\$domain       = '${DOMAIN}';
EOF
        chmod 644 "/etc/tricurse/${DOMAIN}/contact.conf" 2>/dev/null || true

        # 2. Per-domain flat file: /etc/tricurse/<domain>.conf
        cp -p "/etc/tricurse/${DOMAIN}/contact.conf" "/etc/tricurse/${DOMAIN}.conf" 2>/dev/null || true

        # 3. Suite default configuration: /etc/tricurse/contact.conf
        cp -p "/etc/tricurse/${DOMAIN}/contact.conf" /etc/tricurse/contact.conf 2>/dev/null || true
        chmod 644 /etc/tricurse/contact.conf 2>/dev/null || true
    fi

    # Install CLI PHP utilities directly into BIN_DIR
    for util in update_password set_user_password create_user set_password; do
        if [ -f "${APP_SRC}/${util}.php" ]; then
            cp -p "${APP_SRC}/${util}.php" "${BIN_DIR}/${util}"
            chmod 755 "${BIN_DIR}/${util}"
            # Also create .php symlink so both commands work
            ln -sf "${BIN_DIR}/${util}" "${BIN_DIR}/${util}.php" 2>/dev/null || true
        fi
    done

    echo "✓ Installed CLI binaries to ${BIN_DIR}: update_password, set_user_password, create_user, set_password"
fi

# ------------------------------------------------------------------------------
# STEP 6: SUMMARY & ACCESS INSTRUCTIONS
# ------------------------------------------------------------------------------
HAS_SSL=false
if command -v ss >/dev/null 2>&1 && ss -tln | grep -q ':443 '; then
    HAS_SSL=true
elif command -v netstat >/dev/null 2>&1 && netstat -tln | grep -q ':443 '; then
    HAS_SSL=true
fi

echo -e "\n${BOLD}${GREEN}=================================================================="
echo "    INSTALLATION & DEPLOYMENT COMPLETE! (SQLite Edition)          "
echo -e "==================================================================${RESET}"
echo -e "Website Directory:      ${BOLD}${WEBROOT}${RESET}"
echo -e "Message Storage (Disk): ${BOLD}${MESSAGES_DIR}${RESET}"
echo -e "Database File:          ${BOLD}${DB_PATH}${RESET}"
echo -e "Website Host / Domain:  ${BOLD}${DOMAIN}${RESET}"
echo -e "Host Username:          ${BOLD}${USER_NAME}${RESET}"
echo -e "Administrator Password: ${BOLD}(configured during install)${RESET}"

if [ "$HAS_SSL" = true ]; then
    echo -e "SSL / HTTPS Status:     ${BOLD}${GREEN}✓ Active (Port 443 listener detected)${RESET}"
else
    echo -e "SSL / HTTPS Status:     ${BOLD}${YELLOW}⚠ Port 443 not detected on server${RESET}"
    echo -e "                        ${YELLOW}Notice: Modern browsers require SSL (HTTPS) for PWA installation"
    echo -e "                        and Web Push alerts. Ensure Certbot / Let's Encrypt is enabled on this domain.${RESET}"
fi

URL_SUBPATH=""
if [ -n "$DETECTED_DOCROOT" ] && [[ "$WEBROOT" == "$DETECTED_DOCROOT"* ]]; then
    URL_SUBPATH="${WEBROOT#$DETECTED_DOCROOT}"
else
    DIR_NAME="$(basename "$WEBROOT")"
    [ -n "$DIR_NAME" ] && URL_SUBPATH="/${DIR_NAME}"
fi
[ -n "$URL_SUBPATH" ] && [[ "$URL_SUBPATH" != /* ]] && URL_SUBPATH="/${URL_SUBPATH}"
URL_SUBPATH="${URL_SUBPATH%/}"

APP_URL_PATH="${URL_SUBPATH:+${URL_SUBPATH}}"

echo ""
echo "Access your installation from your existing website:"
echo "  Web GUI:      https://${DOMAIN}${APP_URL_PATH}/index.php"
echo "  Login Screen: https://${DOMAIN}${APP_URL_PATH}/signup.html"
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
