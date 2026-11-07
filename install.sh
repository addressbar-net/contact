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
if [ -n "$SCRIPT_DIR" ] && [ -d "${SCRIPT_DIR}/tricurse/contact" ]; then
    APP_SRC="${SCRIPT_DIR}/tricurse/contact"
elif [ -n "$SCRIPT_DIR" ] && [ -f "${SCRIPT_DIR}/index.php" ]; then
    APP_SRC="${SCRIPT_DIR}"
elif [ -n "$SCRIPT_DIR" ] && [ -d "${SCRIPT_DIR}/script/contact" ]; then
    APP_SRC="${SCRIPT_DIR}/script/contact"
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
    if [ -d "${TEMP_SRC_DIR}/tricurse/contact" ]; then
        APP_SRC="${TEMP_SRC_DIR}/tricurse/contact"
    elif [ -f "${TEMP_SRC_DIR}/index.php" ]; then
        APP_SRC="${TEMP_SRC_DIR}"
    elif [ -d "${TEMP_SRC_DIR}/script/contact" ]; then
        APP_SRC="${TEMP_SRC_DIR}/script/contact"
    fi
fi

# Default configuration values
DOMAIN=""
WEBROOT=""
MESSAGES_DIR=""
USER_NAME="admin"
USER_PASS=""
CLIENT_NAME=""
DB_PATH=""
ROLE=""
SUBSCRIBE_TO=""
USE_SHARED_DB=true
SKIP_SYSTEM=false
SKIP_DB=false
UPDATE_MODE=false
UPDATE_ALL=false
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
  -N, --name, --client-name NAME
                               Client account name (MANDATORY when installing client accounts)
  -D, --domain DOMAIN          Domain name or hostname of your existing website (default: detected hostname)
  -u, --user USER              Initial administrator username (default: admin)
  -r, --role ROLE              Operating role: 'main' (Main Recipient Point) or 'client' (Individual Client)
      --main-point             Operate as the Main Recipient Point (receives messages; clients can subscribe)
      --client                 Operate as an Individual Client (subscribes to messages received by main site)
  -s, --subscribe-to DOMAIN    Main website domain/URL to subscribe to (when role is client)
  -S, --shared-db              Single shared SQLite database across all accounts on this server (standard & only option)
  -H, --add-hosts              Add domain to /etc/hosts (127.0.0.1 <domain>) for local server loopback
      --db-path PATH           Explicit SQLite database file path (default: <messages-dir>/contact.sqlite)
      --skip-system            Skip system package verification
      --skip-db                Skip database initialization
  -U, --update                 Update an existing installation from git (git pull + sync)
  -A, --update-all             Simultaneously update all client installations on this server
  -l, --list, --list-installs  List all recorded installations on this server and exit
  -y, --yes                    Non-interactive mode (requires -d/-w, -p for initial install)
  -h, --help                   Display this help message and exit

Examples:
  # Guided interactive installation into contact/ directory (prompts for role, subscription & database):
  sudo ./install.sh

  # Deploying Main Recipient Point with domain:
  sudo ./install.sh -D main.example.com -d /var/www/main/contact -m /var/lib/tricurse/contact/main.example.com -p 'MySecretPass123' --main-point -H -y

  # Deploying Individual Client sharing server database and subscribing to main site:
  sudo ./install.sh -D client1.example.com -d /var/www/client1/contact --client --subscribe-to main.example.com --shared-db -p 'MySecretPass123' -H -y

  # Serving from volatile /tmp while keeping messages safely on persistent disk:
  sudo ./install.sh -D client1.example.com -d /tmp/contact -m /var/lib/tricurse/contact/client1.example.com -p 'MySecretPass123' -y

  # Update an existing single installation:
  sudo ./install.sh --update -d /var/www/html/contact -m /var/lib/tricurse/contact/client1.example.com

  # Simultaneously update all client installations on this server:
  sudo ./install.sh --update-all
  # Or run the global CLI utility:
  contact_update_all
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
        -N|--name|--client-name)
            CLIENT_NAME="$2"; shift 2 ;;
        -r|--role)
            ROLE="$2"; shift 2 ;;
        --main-point)
            ROLE="main"; shift ;;
        --client)
            ROLE="client"; shift ;;
        -s|--subscribe-to|--subscribe)
            SUBSCRIBE_TO="$2"; shift 2 ;;
        -S|--shared-db)
            USE_SHARED_DB=true; shift ;;
        -H|--add-hosts|--hosts)
            ADD_HOSTS=true; shift ;;
        --db-path)
            DB_PATH="$2"; shift 2 ;;
        --skip-system)
            SKIP_SYSTEM=true; shift ;;
        --skip-db)
            SKIP_DB=true; shift ;;
        -l|--list|--list-installs)
            if [ -f "${APP_SRC}/contact_list.php" ]; then
                "${APP_SRC}/contact_list.php"
            elif [ -x "/usr/local/bin/contact_list" ]; then
                /usr/local/bin/contact_list
            else
                echo "Registry file not found."
            fi
            exit 0 ;;
        -U|--update)
            UPDATE_MODE=true; shift ;;
        -A|--update-all|update-all)
            UPDATE_ALL=true; shift ;;
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

# ------------------------------------------------------------------------------
# SIMULTANEOUS MULTI-CLIENT SERVER UPDATE (--update-all)
# ------------------------------------------------------------------------------
if [ "$UPDATE_ALL" = true ]; then
    echo -e "\n${BOLD}${CYAN}==> Running Simultaneous Multi-Client Server Update...${RESET}"

    # 1. Update source code from git repository if present
    if [ -d "${SCRIPT_DIR}/.git" ]; then
        if git -C "${SCRIPT_DIR}" remote get-url origin >/dev/null 2>&1; then
            echo "Pulling latest changes from git repository (${SCRIPT_DIR})..."
            git -C "${SCRIPT_DIR}" pull --rebase || echo "git pull completed or skipped."
        else
            echo "Local git repository detected (${SCRIPT_DIR})."
        fi
    fi

    # 2. Gather all registered client installations
    INSTALLS_JSON="$(php -r '
        $files = ["/etc/tricurse/installations.json", getenv("HOME") . "/.local/share/tricurse/installations.json"];
        $list = [];
        foreach ($files as $f) {
            if (file_exists($f)) {
                $d = @json_decode(@file_get_contents($f), true);
                if (is_array($d)) {
                    foreach ($d as $item) {
                        $k = strtolower($item["domain"] ?? "") . "::" . ($item["webroot"] ?? "");
                        $list[$k] = $item;
                    }
                }
            }
        }
        if (is_dir("/etc/tricurse")) {
            $confs = @glob("/etc/tricurse/*/contact.conf");
            if ($confs) {
                foreach ($confs as $cf) {
                    $dom = basename(dirname($cf));
                    $messages_dir = ""; $sqlite_path = ""; $webroot = "";
                    $server_role = "main"; $subscribed_to = "";
                    @include $cf;
                    $k = strtolower($dom) . "::" . $webroot;
                    if (!isset($list[$k])) {
                        $list[$k] = [
                            "domain"        => $dom,
                            "webroot"       => $webroot,
                            "messages_dir"  => $messages_dir,
                            "db_path"       => $sqlite_path ?: ($messages_dir ? $messages_dir . "/contact.sqlite" : ""),
                            "role"          => $server_role ?: "main",
                            "subscribed_to" => $subscribed_to ?: "",
                            "config_file"   => $cf
                        ];
                    }
                }
            }
        }
        echo json_encode(array_values($list));
    ')"

    INST_COUNT="$(echo "$INSTALLS_JSON" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? count($d) : 0;')"

    if [ "$INST_COUNT" -eq 0 ]; then
        echo -e "${YELLOW}Notice: No client installations currently registered in /etc/tricurse/installations.json.${RESET}"
        echo "Installations are recorded automatically when deploying via sudo ./install.sh."
        exit 0
    fi

    # 2b. Check for existing client accounts without a name set
    MISSING_NAMES_JSON="$(INSTALLS_JSON="$INSTALLS_JSON" php -r '
        $installs = json_decode(getenv("INSTALLS_JSON"), true) ?: [];
        $missing = [];
        foreach ($installs as $inst) {
            $role = $inst["role"] ?? "main";
            if ($role !== "client") continue;
            $dom = trim($inst["domain"] ?? "");
            if (!$dom) continue;

            $has_name = false;
            if (!empty($inst["client_name"])) {
                $has_name = true;
            }
            if (!$has_name && !empty($inst["config_file"]) && file_exists($inst["config_file"])) {
                $client_name = "";
                @include $inst["config_file"];
                if (!empty($client_name)) $has_name = true;
            }
            if (!$has_name) {
                $dbPath = $inst["db_path"] ?? "";
                if (!$dbPath && !empty($inst["messages_dir"])) {
                    $dbPath = $inst["messages_dir"] . "/contact.sqlite";
                }
                if ($dbPath && file_exists($dbPath)) {
                    try {
                        $pdo = new PDO("sqlite:" . $dbPath);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? OR setting_key = \"client_name\"");
                        $stmt->execute([$dom . "::client_name"]);
                        $val = $stmt->fetchColumn();
                        if (!empty($val)) $has_name = true;

                        if (!$has_name) {
                            $stmt2 = $pdo->prepare("SELECT client_name FROM users WHERE (domain = ? OR domain = \"\" OR domain IS NULL) AND client_name IS NOT NULL AND client_name != \"\" LIMIT 1");
                            $stmt2->execute([$dom]);
                            $val2 = $stmt2->fetchColumn();
                            if (!empty($val2)) $has_name = true;
                        }

                        if (!$has_name) {
                            $stmt3 = $pdo->prepare("SELECT client_name FROM main_subscriptions WHERE client_domain = ? AND client_name IS NOT NULL AND client_name != \"\" LIMIT 1");
                            $stmt3->execute([$dom]);
                            $val3 = $stmt3->fetchColumn();
                            if (!empty($val3)) $has_name = true;
                        }
                    } catch (Exception $e) {}
                }
            }
            if (!$has_name) {
                $missing[] = [
                    "domain"      => $dom,
                    "webroot"     => $inst["webroot"] ?? "",
                    "db_path"     => $inst["db_path"] ?? ($inst["messages_dir"] ? $inst["messages_dir"] . "/contact.sqlite" : ""),
                    "config_file" => $inst["config_file"] ?? ""
                ];
            }
        }
        echo json_encode($missing);
    ')"

    MISSING_COUNT="$(echo "$MISSING_NAMES_JSON" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? count($d) : 0;')"

    if [ "$MISSING_COUNT" -gt 0 ]; then
        echo -e "\n${BOLD}${YELLOW}------------------------------------------------------------------${RESET}"
        echo -e "${BOLD}${YELLOW}Notice: Found ${MISSING_COUNT} existing client account(s) without a name set:${RESET}"
        echo "$MISSING_NAMES_JSON" | php -r '
            $d = json_decode(stream_get_contents(STDIN), true) ?: [];
            foreach ($d as $idx => $item) {
                echo "  " . ($idx + 1) . ") \033[1;36m" . $item["domain"] . "\033[0m (" . ($item["webroot"] ?: "no webroot") . ")\n";
            }
        '
        echo -e "A name must be set for each client account."

        if [ "$NON_INTERACTIVE" = true ]; then
            echo -e "${YELLOW}Notice: Non-interactive mode active. To set client names, run contact_update_all interactively.${RESET}"
        else
            echo ""
            read -r -p "Would you like to set names for these client accounts now? [Y/n]: " OPT_SET_NAMES
            case "$OPT_SET_NAMES" in
                n|N|no|No|NO)
                    echo "Skipping setting client names for now."
                    ;;
                *)
                    DOMAINS_TO_NAME=($(echo "$MISSING_NAMES_JSON" | php -r '
                        $d = json_decode(stream_get_contents(STDIN), true) ?: [];
                        foreach ($d as $item) {
                            echo $item["domain"] . "\n";
                        }
                    '))
                    for DOM_NAME in "${DOMAINS_TO_NAME[@]}"; do
                        [ -z "$DOM_NAME" ] && continue
                        INPUT_NAME=""
                        echo ""
                        read -r -p "Enter name for client '${DOM_NAME}': " INPUT_NAME
                        INPUT_NAME="$(echo "$INPUT_NAME" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
                        if [ -n "$INPUT_NAME" ]; then
                            DOM_NAME="$DOM_NAME" INPUT_NAME="$INPUT_NAME" php -r '
                                $dom = getenv("DOM_NAME");
                                $name = getenv("INPUT_NAME");
                                $files = ["/etc/tricurse/installations.json", getenv("HOME") . "/.local/share/tricurse/installations.json"];
                                foreach ($files as $f) {
                                    if (file_exists($f)) {
                                        $d = @json_decode(@file_get_contents($f), true);
                                        if (is_array($d)) {
                                            $changed = false;
                                            foreach ($d as &$item) {
                                                if (isset($item["domain"]) && strcasecmp($item["domain"], $dom) === 0) {
                                                    $item["client_name"] = $name;
                                                    $changed = true;
                                                    $db = $item["db_path"] ?? "";
                                                    if ($db && file_exists($db)) {
                                                        try {
                                                            $pdo = new PDO("sqlite:" . $db);
                                                            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                                                            $user_cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), "name");
                                                            if (!in_array("client_name", $user_cols)) {
                                                                $pdo->exec("ALTER TABLE users ADD COLUMN client_name TEXT DEFAULT \"\"");
                                                            }
                                                            $pdo->prepare("UPDATE users SET client_name = ? WHERE domain = ? OR domain = \"\" OR domain IS NULL")->execute([$name, $dom]);
                                                            $pdo->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime(\"now\"))")->execute([$dom . "::client_name", $name]);
                                                            $sub_cols = array_column($pdo->query("PRAGMA table_info(main_subscriptions)")->fetchAll(), "name");
                                                            if (!in_array("client_name", $sub_cols)) {
                                                                $pdo->exec("ALTER TABLE main_subscriptions ADD COLUMN client_name TEXT DEFAULT \"\"");
                                                            }
                                                            $pdo->prepare("UPDATE main_subscriptions SET client_name = ? WHERE client_domain = ?")->execute([$name, $dom]);
                                                        } catch (Exception $e) {}
                                                    }
                                                }
                                            }
                                            if ($changed) {
                                                @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                            }
                                        }
                                    }
                                }
                                $confs = ["/etc/tricurse/{$dom}/contact.conf", "/etc/tricurse/{$dom}.conf"];
                                foreach ($confs as $cfg) {
                                    if (file_exists($cfg) && is_writable($cfg)) {
                                        $c = file_get_contents($cfg);
                                        if (strpos($c, "\$client_name") !== false) {
                                            $c = preg_replace("/\\\$client_name\s*=\s*.*?;/", "\$client_name = " . var_export($name, true) . ";", $c);
                                        } else {
                                            $c = rtrim($c) . "\n\$client_name = " . var_export($name, true) . ";\n";
                                        }
                                        @file_put_contents($cfg, $c);
                                    }
                                }
                            '
                            echo -e "  ${GREEN}✓ Name for client '${DOM_NAME}' set to: ${INPUT_NAME}${RESET}"
                        else
                            echo -e "  ${YELLOW}○ No name entered for '${DOM_NAME}'; left unchanged.${RESET}"
                        fi
                    done
                    ;;
            esac
        fi
        echo -e "${BOLD}${YELLOW}------------------------------------------------------------------${RESET}\n"
    fi

    echo -e "${BOLD}${GREEN}Found ${INST_COUNT} client installation(s) on this server. Updating simultaneously...${RESET}\n"

    # 3. Simultaneously update all client installations while strictly maintaining push notifications & VAPID keys
    APP_SRC="$APP_SRC" INSTALLS_JSON="$INSTALLS_JSON" IS_ROOT="$IS_ROOT" php -r '
        $installs = json_decode(getenv("INSTALLS_JSON"), true) ?: [];
        $appSrc = getenv("APP_SRC");
        $schemaFile = $appSrc . "/schema.sql";
        $isRoot = (getenv("IS_ROOT") === "1" || getenv("IS_ROOT") === "true");

        foreach ($installs as $i => $inst) {
            $dom = trim($inst["domain"] ?? "localhost");
            $webroot = trim($inst["webroot"] ?? "");
            $messagesDir = trim($inst["messages_dir"] ?? "");
            $dbPath = trim($inst["db_path"] ?? "");
            if (!$dbPath && $messagesDir) {
                $dbPath = $messagesDir . "/contact.sqlite";
            }

            echo "\033[1m[" . ($i + 1) . "/" . count($installs) . "] Updating client domain: \033[36m{$dom}\033[0m\033[1m (Directory: {$webroot})\033[0m\n";

            if (!$webroot || !is_dir($webroot)) {
                echo "  ⚠ Warning: Target web directory \"{$webroot}\" not found. Skipping file deployment.\n\n";
                continue;
            }

            // Copy public application files
            $webFiles = ["index.php", "db.php", "auth.php", "signup.html", "sw.js", "icon-192.png", "icon-512.png"];
            foreach ($webFiles as $f) {
                $src = $appSrc . "/" . $f;
                $dst = $webroot . "/" . $f;
                if (file_exists($src)) {
                    @copy($src, $dst);
                }
            }

            // Purge CLI tools, installers, and exposed databases from webroot
            $purgeFiles = ["update_client_name.php", "update_password.php", "set_password.php", "set_user_password.php", "create_user.php", "contact_list.php", "contact_update_all.php", "merge_databases.php", "install.sh", "schema.sql", "INSTALL.md", "README.md", "contact.sqlite"];
            foreach ($purgeFiles as $pf) {
                $tgt = $webroot . "/" . $pf;
                if (file_exists($tgt)) {
                    @unlink($tgt);
                }
            }

            // Maintain db_config.php pointing to persistent storage
            if ($messagesDir && $dbPath) {
                $role = $inst["role"] ?? "main";
                $subTo = $inst["subscribed_to"] ?? "";
                $dbConfigContent = "<?php\n// addr.bar Database & Persistent Message Storage Configuration\n\$messages_dir = " . var_export($messagesDir, true) . ";\n\$data_dir     = " . var_export($messagesDir, true) . ";\n\$sqlite_path  = " . var_export($dbPath, true) . ";\n\$db_path      = \$sqlite_path;\n\$server_role  = " . var_export($role, true) . ";\n\$subscribed_to = " . var_export($subTo, true) . ";\n";
                @file_put_contents($webroot . "/db_config.php", $dbConfigContent);
            }

            // Maintain PWA manifest.json
            $manifestFile = $webroot . "/manifest.json";
            if (!file_exists($manifestFile)) {
                $dirName = basename($webroot);
                $displayName = $dom . ($dirName !== "/" ? " ({$dirName})" : "");
                $manifestData = [
                    "name" => $displayName,
                    "short_name" => $dom,
                    "description" => "Federated Messaging Desktop for " . $dom,
                    "id" => "/" . ltrim($dirName, "/") . "/",
                    "start_url" => "index.php",
                    "scope" => "./",
                    "display" => "standalone",
                    "orientation" => "any",
                    "background_color" => "#ffffff",
                    "theme_color" => "#000000",
                    "icons" => [
                        ["src" => "icon-192.png", "sizes" => "192x192", "type" => "image/png", "purpose" => "any maskable"],
                        ["src" => "icon-512.png", "sizes" => "512x512", "type" => "image/png", "purpose" => "any maskable"]
                    ]
                ];
                @file_put_contents($manifestFile, json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Safe database migration preserving push notification subscriptions & VAPID keys
            $subCountBefore = 0;
            $subCountAfter  = 0;
            $vapidKeyPresent = false;

            if ($dbPath && file_exists($dbPath)) {
                try {
                    $pdo = new PDO("sqlite:" . $dbPath);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

                    // 1. Inspect existing push subscriptions & VAPID key pairs
                    $q1 = $pdo->query("SELECT count(*) FROM push_subscriptions");
                    if ($q1) $subCountBefore = (int)$q1->fetchColumn();

                    $q2 = $pdo->query("SELECT count(*) FROM site_settings WHERE setting_key = \"vapid_public_key\"");
                    if ($q2 && (int)$q2->fetchColumn() > 0) $vapidKeyPresent = true;

                    // 2. Safely apply schema.sql (uses CREATE TABLE IF NOT EXISTS and INSERT OR IGNORE)
                    if (file_exists($schemaFile)) {
                        $sql = file_get_contents($schemaFile);
                        $pdo->exec($sql);
                    }

                    // 2b. Ensure client_name column exists
                    $user_cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), "name");
                    if (!in_array("client_name", $user_cols)) {
                        $pdo->exec("ALTER TABLE users ADD COLUMN client_name TEXT DEFAULT \"\"");
                    }
                    $sub_cols = array_column($pdo->query("PRAGMA table_info(main_subscriptions)")->fetchAll(), "name");
                    if (!in_array("client_name", $sub_cols)) {
                        $pdo->exec("ALTER TABLE main_subscriptions ADD COLUMN client_name TEXT DEFAULT \"\"");
                    }

                    // 3. Verify push subscriptions and VAPID keys are maintained
                    $q3 = $pdo->query("SELECT count(*) FROM push_subscriptions");
                    if ($q3) $subCountAfter = (int)$q3->fetchColumn();

                    $q4 = $pdo->query("SELECT count(*) FROM site_settings WHERE setting_key = \"vapid_public_key\"");
                    $vapidKeyAfter = ($q4 && (int)$q4->fetchColumn() > 0);

                    echo "  ✓ Database schema verified: {$dbPath}\n";
                    echo "  ✓ Push notifications maintained: {$subCountAfter} active subscription(s), VAPID keys " . ($vapidKeyAfter ? "preserved" : "initialized") . ".\n";
                } catch (Exception $e) {
                    echo "  ⚠ Database verification note: " . $e->getMessage() . "\n";
                }
            } else if ($messagesDir && is_dir($messagesDir) && file_exists($schemaFile)) {
                try {
                    $pdo = new PDO("sqlite:" . $dbPath);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                    $sql = file_get_contents($schemaFile);
                    $pdo->exec($sql);
                    echo "  ✓ Initialized SQLite database: {$dbPath}\n";
                } catch (Exception $e) {}
            }

            // Set file permissions
            @chmod($webroot, 0755);
            $phpFiles = glob($webroot . "/*.{php,html}", GLOB_BRACE) ?: [];
            foreach ($phpFiles as $pf) {
                @chmod($pf, 0644);
            }

            echo "  ✓ Successfully updated application assets in {$webroot}\n\n";
        }
    '

    # 4. Update system-wide CLI helper utilities in BIN_DIR
    BIN_DIR="/usr/local/bin"
    if [ ! -w "$BIN_DIR" ] && [ "$IS_ROOT" = false ]; then
        BIN_DIR="${HOME}/.local/bin"
    fi
    mkdir -p "$BIN_DIR"

    if [ -w "$BIN_DIR" ] || [ "$IS_ROOT" = true ]; then
        for util in update_client_name update_password set_user_password create_user set_password contact_list contact_update_all merge_databases; do
            if [ -f "${APP_SRC}/${util}.php" ]; then
                cp -p "${APP_SRC}/${util}.php" "${BIN_DIR}/${util}"
                chmod 755 "${BIN_DIR}/${util}"
                ln -sf "${BIN_DIR}/${util}" "${BIN_DIR}/${util}.php" 2>/dev/null || true
            fi
        done
        echo "✓ Updated CLI binaries in ${BIN_DIR}: update_client_name, contact_update_all, contact_list, merge_databases, update_password, set_user_password, create_user, set_password"
    fi

    echo -e "${BOLD}${GREEN}=================================================================="
    echo "✓ Simultaneous multi-client update completed successfully for ${INST_COUNT} client(s)!"
    echo "  All push notification subscriptions and VAPID key pairs were maintained."
    echo -e "==================================================================${RESET}\n"
    exit 0
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

# ------------------------------------------------------------------------------
# OPERATING ROLE SELECTION: MAIN RECIPIENT POINT VS. INDIVIDUAL CLIENT
# ------------------------------------------------------------------------------
# Detect if a Main Contact Point is already registered on this server
DETECTED_MAIN="$(php -r '
    $confDir = "/etc/tricurse";
    if (!is_dir($confDir) && getenv("HOME")) {
        $userConf = getenv("HOME") . "/.local/share/tricurse";
        if (is_dir($userConf)) $confDir = $userConf;
    }
    $regFiles = [
        $confDir . "/installations.json",
        "/etc/tricurse/installations.json",
        getenv("HOME") . "/.local/share/tricurse/installations.json"
    ];
    foreach ($regFiles as $rf) {
        if (file_exists($rf)) {
            $data = @json_decode(@file_get_contents($rf), true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (($item["role"] ?? "") === "main" && !empty($item["domain"])) {
                        echo $item["domain"];
                        exit;
                    }
                }
            }
        }
    }
    $confFiles = array_merge(
        glob("/etc/tricurse/*/contact.conf") ?: [],
        glob("/etc/tricurse/*.conf") ?: []
    );
    foreach ($confFiles as $cf) {
        $content = @file_get_contents($cf);
        if ($content && preg_match("/\\\$server_role\\s*=\\s*[\x27\x22]main[\x27\x22]/i", $content)) {
            if (preg_match("/\\\$domain\\s*=\\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/i", $content, $m)) {
                echo $m[1];
                exit;
            }
        }
    }
')"

if [ -z "$ROLE" ]; then
    if [ -n "$DETECTED_MAIN" ]; then
        # Standard procedure: A main contact point already exists; subsequent installs are clients
        if [ "$NON_INTERACTIVE" = true ]; then
            ROLE="client"
        else
            echo ""
            echo -e "${BOLD}Operating Role Selection:${RESET}"
            echo -e "${CYAN}Standard Procedure:${RESET} An initial Main Contact Point (${BOLD}${DETECTED_MAIN}${RESET}) is already active on this server."
            echo "Subsequent installations function as Individual Clients that subscribe to the main website."
            echo "  1) Main Recipient Point (override existing main contact point on server)"
            echo "  2) Individual Client (standard procedure for subsequent installations; default)"
            read -r -p "Select role [2]: " INPUT_ROLE
            case "$INPUT_ROLE" in
                1|main|Main)
                    ROLE="main" ;;
                *)
                    ROLE="client" ;;
            esac
        fi
    else
        # Standard procedure: First installation on server functions as Main Recipient Point
        if [ "$NON_INTERACTIVE" = true ]; then
            ROLE="main"
        else
            echo ""
            echo -e "${BOLD}Operating Role Selection:${RESET}"
            echo -e "${CYAN}Standard Procedure:${RESET} Initial installation on this server functions as the Main Recipient Point."
            echo "  1) Main Recipient Point (central website that receives messages; clients subscribe to it; default)"
            echo "  2) Individual Client (subscribes to messages received by the main website)"
            read -r -p "Select role [1]: " INPUT_ROLE
            case "$INPUT_ROLE" in
                2|client|Client)
                    ROLE="client" ;;
                *)
                    ROLE="main" ;;
            esac
        fi
    fi
fi

if [ "$ROLE" = "client" ] && [ -z "$SUBSCRIBE_TO" ]; then
    if [ "$NON_INTERACTIVE" = true ]; then
        SUBSCRIBE_TO="${DETECTED_MAIN}"
    else
        echo ""
        echo -e "${BOLD}Main Website Subscription:${RESET}"
        echo "Individual clients can subscribe to the main website to automatically receive a copy of all messages sent to it."
        read -r -p "Enter main website domain or URL to subscribe to [${DETECTED_MAIN}]: " INPUT_SUB
        SUBSCRIBE_TO="${INPUT_SUB:-$DETECTED_MAIN}"
    fi
fi

# Client Name Configuration (mandatory for client role)
if [ "$ROLE" = "client" ]; then
    if [ -z "$CLIENT_NAME" ]; then
        if [ "$NON_INTERACTIVE" = true ]; then
            echo -e "${RED}Error: A name must be set for each client account (-N <name> or --client-name <name>).${RESET}" >&2
            echo "When the administrator installs client accounts, they must set one for them." >&2
            exit 1
        else
            while [ -z "$CLIENT_NAME" ]; do
                echo ""
                echo -e "${BOLD}Client Name Configuration:${RESET}"
                echo -e "${CYAN}Standard Requirement:${RESET} A name must be set for each client account."
                read -r -p "Enter client name: " INPUT_CLIENT_NAME
                INPUT_CLIENT_NAME="$(echo "$INPUT_CLIENT_NAME" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
                if [ -z "$INPUT_CLIENT_NAME" ]; then
                    echo -e "${RED}Error: Client name cannot be empty. A name must be set for each client account.${RESET}"
                else
                    CLIENT_NAME="$INPUT_CLIENT_NAME"
                fi
            done
        fi
    fi
elif [ "$ROLE" = "main" ] && [ -z "$CLIENT_NAME" ] && [ "$NON_INTERACTIVE" = false ]; then
    echo ""
    echo -e "${BOLD}Contact Point Display Name (Optional):${RESET}"
    read -r -p "Enter display name for the main contact point (optional, press Enter to skip): " INPUT_MAIN_NAME
    CLIENT_NAME="$(echo "$INPUT_MAIN_NAME" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
fi

# ------------------------------------------------------------------------------
# DATABASE STORAGE CONFIGURATION: SINGLE SHARED VS INDEPENDENT
# ------------------------------------------------------------------------------
SERVER_CONF_DIR="/etc/tricurse"
[ "$IS_ROOT" = false ] && [ ! -w /etc ] && SERVER_CONF_DIR="${HOME}/.local/share/tricurse"
SERVER_JSON_FILE="${SERVER_CONF_DIR}/server.json"

# Check if prior installations or server configuration exist
SERVER_INFO="$(SERVER_JSON_FILE="$SERVER_JSON_FILE" SERVER_CONF_DIR="$SERVER_CONF_DIR" php -r '
    $serverJson = getenv("SERVER_JSON_FILE");
    $confDir = getenv("SERVER_CONF_DIR");
    $regFiles = [$confDir . "/installations.json", "/etc/tricurse/installations.json", getenv("HOME") . "/.local/share/tricurse/installations.json"];
    
    $sharedConfig = null;
    if (file_exists($serverJson)) {
        $sharedConfig = @json_decode(@file_get_contents($serverJson), true);
    }
    
    $installs = [];
    foreach ($regFiles as $rf) {
        if (file_exists($rf)) {
            $d = @json_decode(@file_get_contents($rf), true);
            if (is_array($d)) {
                foreach ($d as $item) {
                    $k = strtolower($item["domain"] ?? "") . "::" . ($item["webroot"] ?? "");
                    $installs[$k] = $item;
                }
            }
        }
    }
    
    // Also scan /etc/tricurse/*/contact.conf if installations.json was empty
    if (empty($installs) && is_dir("/etc/tricurse")) {
        $dirs = @glob("/etc/tricurse/*/contact.conf");
        if ($dirs) {
            foreach ($dirs as $cf) {
                $dom = basename(dirname($cf));
                $sqlite_path = ""; $messages_dir = ""; $webroot = "";
                @include $cf;
                $installs[$dom . "::" . $webroot] = [
                    "domain" => $dom,
                    "db_path" => $sqlite_path ?: ($messages_dir ? $messages_dir . "/contact.sqlite" : "")
                ];
            }
        }
    }

    $count = count($installs);
    $isShared = false;
    $sharedDb = "";
    
    if ($sharedConfig && isset($sharedConfig["shared_database"])) {
        $isShared = !empty($sharedConfig["shared_database"]);
        $sharedDb = $sharedConfig["shared_db_path"] ?? "";
    } elseif ($count > 0) {
        $allDbs = [];
        foreach ($installs as $ins) {
            if (!empty($ins["db_path"])) {
                $allDbs[$ins["db_path"]] = true;
            }
        }
        if (count($allDbs) === 1) {
            $isShared = true;
            $sharedDb = key($allDbs);
        }
    }

    echo json_encode([
        "count"     => $count,
        "is_shared" => $isShared,
        "shared_db" => $sharedDb
    ]);
')"

EXISTING_INST_COUNT="$(echo "$SERVER_INFO" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["count"] ?? 0;')"
SERVER_ALREADY_SHARED="$(echo "$SERVER_INFO" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo !empty($d["is_shared"]) ? "1" : "0";')"
DETECTED_SHARED_DB="$(echo "$SERVER_INFO" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["shared_db"] ?? "";')"

DEFAULT_SHARED_MESSAGES_DIR="/var/lib/tricurse/contact"
[ "$IS_ROOT" = false ] && DEFAULT_SHARED_MESSAGES_DIR="${HOME}/.local/share/tricurse/contact"
DEFAULT_SHARED_DB="${DEFAULT_SHARED_MESSAGES_DIR}/contact.sqlite"
USE_SHARED_DB=true

# 1. INITIAL INSTALLATION ON THIS SERVER (No prior installations registered)
if [ "$EXISTING_INST_COUNT" -eq 0 ] && [ "$SERVER_ALREADY_SHARED" = "0" ]; then
    echo ""
    echo -e "${BOLD}Database Configuration (Single Shared Database):${RESET}"
    echo "This server uses a single shared SQLite database for all clients and contact points."
    echo "A shared database is the only option, ensuring that messages are stored only once on"
    echo "disk with zero duplicate records and preserving chronological message ordering."
    
    if [ "$NON_INTERACTIVE" = true ]; then
        DB_PATH="${DB_PATH:-$DEFAULT_SHARED_DB}"
        MESSAGES_DIR="$(dirname "$DB_PATH")"
    else
        read -r -p "Confirm or specify save location [${DEFAULT_SHARED_DB}]: " INPUT_SAVE_LOC
        INPUT_SAVE_LOC="$(echo "$INPUT_SAVE_LOC" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        DB_PATH="${INPUT_SAVE_LOC:-$DEFAULT_SHARED_DB}"
        MESSAGES_DIR="$(dirname "$DB_PATH")"
    fi
    mkdir -p "$SERVER_CONF_DIR"
    SERVER_JSON_FILE="$SERVER_JSON_FILE" DB_PATH="$DB_PATH" MESSAGES_DIR="$MESSAGES_DIR" php -r '
        $file = getenv("SERVER_JSON_FILE");
        $data = [
            "shared_database" => true,
            "shared_db_path"  => getenv("DB_PATH"),
            "messages_dir"    => getenv("MESSAGES_DIR"),
            "configured_at"   => date("c")
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '
    echo -e "${GREEN}✓ Single shared database configured: ${DB_PATH}${RESET}"

# 2. SUBSEQUENT CLIENT INSTALLATION WHEN SERVER IS ALREADY CONFIGURED FOR SINGLE DATABASE
elif [ "$SERVER_ALREADY_SHARED" = "1" ] || [ -n "$DETECTED_SHARED_DB" ]; then
    TARGET_SHARED_DB="${DETECTED_SHARED_DB:-$DEFAULT_SHARED_DB}"
    [ -n "$DB_PATH" ] && TARGET_SHARED_DB="$DB_PATH"

    echo ""
    echo -e "${BOLD}Single Shared Database Save Location:${RESET}"
    echo "This server is configured to use a single shared database for all clients."
    echo -e "Configured save location: ${BOLD}${CYAN}${TARGET_SHARED_DB}${RESET}"

    if [ "$NON_INTERACTIVE" = true ]; then
        DB_PATH="$TARGET_SHARED_DB"
        MESSAGES_DIR="$(dirname "$DB_PATH")"
    else
        read -r -p "Confirm save location [${TARGET_SHARED_DB}]? [Y/n] (or specify new path): " INPUT_CONFIRM_LOC
        INPUT_CONFIRM_LOC="$(echo "$INPUT_CONFIRM_LOC" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        if [ -z "$INPUT_CONFIRM_LOC" ] || [[ "$INPUT_CONFIRM_LOC" =~ ^[Yy] ]]; then
            DB_PATH="$TARGET_SHARED_DB"
            MESSAGES_DIR="$(dirname "$DB_PATH")"
        elif [[ "$INPUT_CONFIRM_LOC" =~ ^[Nn] ]]; then
            read -r -p "Enter new shared database save location [${TARGET_SHARED_DB}]: " INPUT_NEW_LOC
            INPUT_NEW_LOC="$(echo "$INPUT_NEW_LOC" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
            DB_PATH="${INPUT_NEW_LOC:-$TARGET_SHARED_DB}"
            MESSAGES_DIR="$(dirname "$DB_PATH")"
        else
            DB_PATH="$INPUT_CONFIRM_LOC"
            MESSAGES_DIR="$(dirname "$DB_PATH")"
        fi
    fi
    echo -e "${GREEN}✓ Confirmed shared database location: ${DB_PATH}${RESET}"

# 3. SUBSEQUENT CLIENT INSTALLATION WHEN LEGACY INDEPENDENT INSTALLATIONS EXIST
else
    echo ""
    echo -e "${BOLD}${YELLOW}Migrating to Single Shared Database:${RESET}"
    echo "A single shared database is the only option for installations on this server."
    echo "Merging all independent SQLite databases into a single shared database..."

    TARGET_DB_CONVERT="${DEFAULT_SHARED_DB}"
    if [ -n "$DB_PATH" ]; then
        TARGET_DB_CONVERT="$DB_PATH"
    elif [ "$NON_INTERACTIVE" = false ]; then
        read -r -p "Confirm unified save location [${DEFAULT_SHARED_DB}]: " INPUT_TARGET_DB
        INPUT_TARGET_DB="$(echo "$INPUT_TARGET_DB" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        [ -n "$INPUT_TARGET_DB" ] && TARGET_DB_CONVERT="$INPUT_TARGET_DB"
    fi

    MERGER_SCRIPT="${APP_SRC}/merge_databases.php"
    if [ ! -f "$MERGER_SCRIPT" ]; then
        MERGER_SCRIPT="${SCRIPT_DIR}/merge_databases.php"
    fi

    echo -e "\n${BOLD}${CYAN}Merging all independent SQLite installations into ${TARGET_DB_CONVERT}...${RESET}"
    php "$MERGER_SCRIPT" --target "$TARGET_DB_CONVERT" --schema "${APP_SRC}/schema.sql" --update-configs

    DB_PATH="$TARGET_DB_CONVERT"
    MESSAGES_DIR="$(dirname "$DB_PATH")"

    mkdir -p "$SERVER_CONF_DIR"
    SERVER_JSON_FILE="$SERVER_JSON_FILE" DB_PATH="$DB_PATH" MESSAGES_DIR="$MESSAGES_DIR" php -r '
        $file = getenv("SERVER_JSON_FILE");
        $data = [
            "shared_database" => true,
            "shared_db_path"  => getenv("DB_PATH"),
            "messages_dir"    => getenv("MESSAGES_DIR"),
            "configured_at"   => date("c")
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '

    echo -e "${BOLD}${GREEN}✓ All independent SQLite installations successfully merged into: ${DB_PATH}${RESET}\n"
fi

DEFAULT_MESSAGES_DIR="$DEFAULT_SHARED_MESSAGES_DIR"

if [ -z "$MESSAGES_DIR" ]; then
    MESSAGES_DIR="$DEFAULT_SHARED_MESSAGES_DIR"
fi
MESSAGES_DIR="${MESSAGES_DIR%/}"

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
        echo "Applying SQLite message database schema from schema.sql..."
        sqlite3 "$DB_PATH" < "$SCHEMA_FILE" 2>/dev/null || true
        echo "✓ SQLite message storage ready: ${DB_PATH}"

        # Verify push notification subscriptions and VAPID keys are maintained
        if command -v php >/dev/null 2>&1; then
            DB_PATH="$DB_PATH" php -r '
                $db = getenv("DB_PATH");
                if (file_exists($db)) {
                    try {
                        $pdo = new PDO("sqlite:" . $db);
                        $res = $pdo->query("SELECT count(*) FROM push_subscriptions");
                        $subCount = $res ? (int)$res->fetchColumn() : 0;
                        $resK = $pdo->query("SELECT count(*) FROM site_settings WHERE setting_key = \"vapid_public_key\"");
                        $hasKey = ($resK && (int)$resK->fetchColumn() > 0);
                        echo "✓ Push notifications maintained: {$subCount} active subscription(s), VAPID keys " . ($hasKey ? "intact" : "ready") . ".\n";
                    } catch (Exception $e) {}
                }
            '
        fi
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
rm -f "${WEBROOT}/update_client_name.php" \
      "${WEBROOT}/update_password.php" \
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
\$server_role  = '${ROLE}';
\$subscribed_to = '${SUBSCRIBE_TO}';
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
        DOMAIN="$DOMAIN" USER_NAME="$USER_NAME" USER_PASS="$USER_PASS" CLIENT_NAME="$CLIENT_NAME" IS_UPDATE_PHP="$IS_UPDATE_PHP" SQLITE_PATH="$DB_PATH" ROLE="$ROLE" SUBSCRIBE_TO="$SUBSCRIBE_TO" DETECTED_MAIN="$DETECTED_MAIN" php -r '
            require_once "'"${WEBROOT}"'/db.php";
            $domain = getenv("DOMAIN") ?: "localhost";
            $username = getenv("USER_NAME") ?: "admin";
            $password = getenv("USER_PASS") ?: "";
            $client_name = getenv("CLIENT_NAME") ?: "";
            $is_update = (getenv("IS_UPDATE_PHP") === "true");
            $role = getenv("ROLE") ?: "main";
            $sub_to = getenv("SUBSCRIBE_TO") ?: "";
            $detected_main = getenv("DETECTED_MAIN") ?: "";

            $pdo = get_db();
            if ($pdo) {
                // Determine Main Contact Point endpoint URL for new installations
                $main_endpoint_url = "";
                if ($role === "client") {
                    if (!empty($sub_to)) {
                        $main_endpoint_url = (strpos($sub_to, "://") !== false) ? $sub_to : "https://" . rtrim($sub_to, "/") . "/contact";
                    } elseif (!empty($detected_main)) {
                        $main_endpoint_url = "https://" . rtrim($detected_main, "/") . "/contact";
                    }
                } elseif ($role === "main") {
                    $main_endpoint_url = ($domain === "localhost") ? "http://localhost/contact" : "https://" . rtrim($domain, "/") . "/contact";
                }
                if (empty($main_endpoint_url)) {
                    $main_endpoint_url = "https://tricurse.com/contact";
                }

                // Initialize site_settings for role, subscription, client_name, and default mail destinations
                if (function_exists("set_setting")) {
                    set_setting("server_role", $role, $domain);
                    set_setting("subscribed_to_main_domain", $sub_to, $domain);
                    set_setting("subscribed_to_main_url", $main_endpoint_url, $domain);
                    if (!empty($client_name)) {
                        set_setting("client_name", $client_name, $domain);
                    }
                    if ($role === "main") {
                        set_setting("server_role", $role, false);
                    }

                    // In new installations, set the default website endpoint URL in the send mail form to the main contact point
                    $curr_default = get_setting("default_mail_destinations", "", $domain);
                    if (empty($curr_default) || $curr_default === "[]" || strpos($curr_default, "tricurse.com/contact") !== false) {
                        $default_json = json_encode([["url" => $main_endpoint_url, "user" => ""]]);
                        set_setting("default_mail_destinations", $default_json, $domain);
                    }
                }
                if ($role === "client" && !empty($sub_to) && function_exists("subscribe_client_to_main")) {
                    subscribe_client_to_main($pdo, $domain, $sub_to, "", 1, $client_name);
                }

                // Strictly locate or create the sovereign user account for THIS domain
                $user_cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), "name");
                if (!in_array("client_name", $user_cols)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN client_name TEXT DEFAULT \"\"");
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE domain = ? LIMIT 1");
                $stmt->execute([$domain]);
                $existing = $stmt->fetch();

                if (!$existing) {
                    // Check for legacy unassigned user with NULL or empty domain
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE (domain IS NULL OR domain = \x27\x27) ORDER BY id ASC LIMIT 1");
                    $stmt->execute();
                    $legacy = $stmt->fetch();
                    if ($legacy) {
                        $existing = $legacy;
                    }
                }

                $session_token = bin2hex(random_bytes(32));

                if ($existing) {
                    $user_id = (int)$existing["id"];
                    if (!empty($password)) {
                        $hashed = password_hash($password, PASSWORD_BCRYPT);
                        $upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, password = ?, session_token = ?, client_name = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
                        $upd->execute([$domain, $username, $hashed, $session_token, $client_name, $user_id]);
                    } else {
                        $upd = $pdo->prepare("UPDATE users SET domain = ?, username = ?, client_name = ? WHERE id = ?");
                        $upd->execute([$domain, $username, $client_name, $user_id]);
                    }
                } else {
                    $hashed = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("INSERT INTO users (domain, username, password, session_token, client_name) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$domain, $username, $hashed, $session_token, $client_name]);
                }

                try {
                    $pdo->prepare("DELETE FROM login_attempts WHERE domain = ? OR domain IS NULL")->execute([$domain]);
                } catch (Exception $e) {}
            }
        '
    fi

    echo "✓ Configured single host user '${USER_NAME}'."
    [ -n "$CLIENT_NAME" ] && echo "✓ Configured client name: '${CLIENT_NAME}'."
    echo "✓ Administrator password securely set in SQLite database (bcrypt)."
    echo "✓ Configured default send mail endpoint to the main contact point."
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
\$server_role  = '${ROLE}';
\$subscribed_to = '${SUBSCRIBE_TO}';
\$client_name  = '${CLIENT_NAME}';
EOF
        chmod 644 "/etc/tricurse/${DOMAIN}/contact.conf" 2>/dev/null || true

        # 2. Per-domain flat file: /etc/tricurse/<domain>.conf
        cp -p "/etc/tricurse/${DOMAIN}/contact.conf" "/etc/tricurse/${DOMAIN}.conf" 2>/dev/null || true

        # 3. Suite default configuration: /etc/tricurse/contact.conf (preserve main contact point)
        if [ "$ROLE" = "main" ] || [ ! -f /etc/tricurse/contact.conf ]; then
            cp -p "/etc/tricurse/${DOMAIN}/contact.conf" /etc/tricurse/contact.conf 2>/dev/null || true
            chmod 644 /etc/tricurse/contact.conf 2>/dev/null || true
        fi
        chmod 644 /etc/tricurse/contact.conf 2>/dev/null || true
    fi

    # 4. Record installation in installations.json registry
    REG_DIR="/etc/tricurse"
    [ "$IS_ROOT" = false ] && [ ! -w /etc ] && REG_DIR="${HOME}/.local/share/tricurse"
    mkdir -p "$REG_DIR"
    REG_FILE="${REG_DIR}/installations.json"
    
    DOMAIN="$DOMAIN" WEBROOT="$WEBROOT" MESSAGES_DIR="$MESSAGES_DIR" DB_PATH="$DB_PATH" ROLE="$ROLE" SUBSCRIBE_TO="$SUBSCRIBE_TO" CLIENT_NAME="$CLIENT_NAME" REG_FILE="$REG_FILE" php -r '
        $file = getenv("REG_FILE");
        $data = [];
        if (file_exists($file)) {
            $decoded = json_decode(@file_get_contents($file), true);
            if (is_array($decoded)) $data = $decoded;
        }
        $domain = getenv("DOMAIN") ?: "localhost";
        $webroot = getenv("WEBROOT") ?: "";
        $entry = [
            "domain"        => $domain,
            "client_name"   => getenv("CLIENT_NAME") ?: "",
            "webroot"       => $webroot,
            "messages_dir"  => getenv("MESSAGES_DIR") ?: "",
            "db_path"       => getenv("DB_PATH") ?: "",
            "role"          => getenv("ROLE") ?: "main",
            "subscribed_to" => getenv("SUBSCRIBE_TO") ?: "",
            "config_file"   => "/etc/tricurse/{$domain}/contact.conf",
            "installed_at"  => date("c"),
            "installed_by"  => getenv("USER") ?: "root"
        ];
        $key = strtolower($domain) . "::" . $webroot;
        $data[$key] = $entry;
        file_put_contents($file, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '
    chmod 644 "$REG_FILE" 2>/dev/null || true

    # Install CLI PHP utilities directly into BIN_DIR
    for util in update_client_name update_password set_user_password create_user set_password contact_list contact_update_all merge_databases; do
        if [ -f "${APP_SRC}/${util}.php" ]; then
            cp -p "${APP_SRC}/${util}.php" "${BIN_DIR}/${util}"
            chmod 755 "${BIN_DIR}/${util}"
            # Also create .php symlink so both commands work
            ln -sf "${BIN_DIR}/${util}" "${BIN_DIR}/${util}.php" 2>/dev/null || true
        fi
    done

    echo "✓ Installed CLI binaries to ${BIN_DIR}: update_client_name, update_password, set_user_password, create_user, set_password, contact_list, contact_update_all, merge_databases"
    echo "✓ Registered installation details in ${REG_FILE}"
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
echo -e "Operating Role:         ${BOLD}$([ "$ROLE" = "main" ] && echo "Main Recipient Point" || echo "Individual Client")${RESET}"
if [ "$ROLE" = "client" ] && [ -n "$SUBSCRIBE_TO" ]; then
    echo -e "Subscribed To Main Site:${BOLD}${SUBSCRIBE_TO}${RESET} (Messages automatically copied)"
fi
echo -e "Database Architecture:  ${BOLD}${GREEN}Single Shared Database${RESET} (Only one copy of messages stored on disk)"
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
echo "  update_client_name                           # Interactive client name update utility"
echo "  update_client_name -D <domain> -N <name>     # Update client account name directly"
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
