#!/usr/bin/env bash
# ==============================================================================
# addr.bar / HotLetter / Contact Uninstallation Script
# ==============================================================================
# Safely uninstalls a particular domain installation or removes the entire suite:
#   - Single Domain Mode (-D <domain>): Removes only that domain's webroot files,
#     configuration, registry entries, and dedicated storage (preserving other domains
#     and retaining CLI utilities if other installations remain).
#   - Full Suite Mode (--all): Removes all domain installations, web files,
#     configuration registries, and CLI utilities.
#
# CRITICAL SAFETY CONSTRAINTS:
#   - NEVER delete /var/lib/tricurse itself (other packages may reside there).
#   - When uninstalling a single domain, NEVER delete other domains' data or configs.
# ==============================================================================

set -e

# Terminal colors
RESET="\033[0m"
BOLD="\033[1m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"

NON_INTERACTIVE=false
DRY_RUN=false
KEEP_DATA=false
TARGET_DOMAIN=""
EXTRA_WEBROOT=""
UNINSTALL_ALL=false
PURGE_CLI=false

# Helper: Show usage instructions
show_help() {
    cat << EOF
Usage: sudo ./uninstall.sh [OPTIONS]

addr.bar / HotLetter Uninstallation Utility
Safely removes a specific domain installation or completely purges the entire suite.

Options:
  -D, --domain DOMAIN  Target domain to uninstall (removes only that domain's installation)
  -w, -d, --dir DIR    Specify webroot directory to clean
  --all                Completely uninstall all domain installations and CLI tools
  --keep-data          Remove application scripts and configs, but preserve message & DB stores
  --purge-cli          Remove CLI utilities from /usr/local/bin even if single domain mode
  -y, --yes            Non-interactive mode (proceed without confirmation prompts)
  -n, --dry-run        Show what files and directories would be removed without deleting
  -h, --help           Display this help message and exit

Examples:
  # Uninstall a particular domain installation:
  sudo ./uninstall.sh -D novy.addr.bar
  sudo ./uninstall.sh -D novy.addr.bar -y

  # Interactive menu (select a specific domain or all):
  sudo ./uninstall.sh

  # Completely remove all installations and the entire suite:
  sudo ./uninstall.sh --all -y

  # Uninstall via install.sh:
  sudo ./install.sh --uninstall -D novy.addr.bar
EOF
    exit 0
}

# Parse command line options
while [[ $# -gt 0 ]]; do
    case "$1" in
        -D|--domain)
            TARGET_DOMAIN="$2"; shift 2 ;;
        -w|-d|--dir|--directory|--webroot)
            EXTRA_WEBROOT="$2"; shift 2 ;;
        --all|--uninstall-all)
            UNINSTALL_ALL=true; shift ;;
        --keep-data)
            KEEP_DATA=true; shift ;;
        --purge-cli)
            PURGE_CLI=true; shift ;;
        -y|--yes)
            NON_INTERACTIVE=true; shift ;;
        -n|--dry-run)
            DRY_RUN=true; shift ;;
        -h|--help)
            show_help ;;
        *)
            # Check if bare domain argument was passed
            if [ -z "$TARGET_DOMAIN" ] && [[ "$1" != -* ]]; then
                TARGET_DOMAIN="$1"; shift
            else
                echo -e "${RED}Unknown option: $1${RESET}" >&2
                echo "Run ./uninstall.sh --help for usage." >&2
                exit 1
            fi ;;
    esac
done

IS_ROOT=false
if [ "$(id -u)" -eq 0 ]; then
    IS_ROOT=true
fi

echo -e "\n${BOLD}${CYAN}=================================================================="
echo "    addr.bar / HotLetter Uninstallation Utility                   "
echo -e "==================================================================${RESET}\n"

if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Notice: Running in DRY-RUN mode. No files will be modified or removed.${RESET}\n"
fi

if [ "$KEEP_DATA" = true ]; then
    echo -e "${YELLOW}Notice: --keep-data is enabled. Databases and message stores will be preserved.${RESET}\n"
fi

# Track removal counts
REMOVED_FILES=0
REMOVED_DIRS=0

remove_path() {
    local target="$1"
    local desc="$2"
    if [ -e "$target" ] || [ -L "$target" ]; then
        if [ "$DRY_RUN" = true ]; then
            echo -e "  ${YELLOW}[DRY-RUN] Would remove ${desc:-path}:${RESET} $target"
        else
            if rm -rf "$target" 2>/dev/null; then
                echo -e "  ${GREEN}✓ Removed ${desc:-path}:${RESET} $target"
                ((REMOVED_FILES++)) || true
            else
                # Try with sudo if not root
                if [ "$IS_ROOT" = false ] && command -v sudo >/dev/null 2>&1; then
                    if sudo rm -rf "$target" 2>/dev/null; then
                        echo -e "  ${GREEN}✓ Removed ${desc:-path} (via sudo):${RESET} $target"
                        ((REMOVED_FILES++)) || true
                    else
                        echo -e "  ${RED}✗ Failed to remove:${RESET} $target (permission denied)"
                    fi
                else
                    echo -e "  ${RED}✗ Failed to remove:${RESET} $target (permission denied)"
                fi
            fi
        fi
    fi
}

remove_dir_if_empty() {
    local dir="$1"
    if [ -d "$dir" ] && [ ! -L "$dir" ]; then
        if [ -z "$(ls -A "$dir" 2>/dev/null)" ]; then
            if [ "$DRY_RUN" = true ]; then
                echo -e "  ${YELLOW}[DRY-RUN] Would remove empty directory:${RESET} $dir"
            else
                if rmdir "$dir" 2>/dev/null; then
                    echo -e "  ${GREEN}✓ Removed empty directory:${RESET} $dir"
                    ((REMOVED_DIRS++)) || true
                fi
            fi
        fi
    fi
}

# Standard package files to clean from webroots
PACKAGE_FILES=(
    "index.php"
    "db.php"
    "auth.php"
    "signup.html"
    "sw.js"
    "manifest.json"
    "icon-192.png"
    "icon-512.png"
    "db_config.php"
    "contact.sqlite"
    "contact.sqlite-wal"
    "contact.sqlite-shm"
    "INSTALL.md"
    "README.md"
    "schema.sql"
    "install.sh"
    "uninstall.sh"
    "set_client_admin.php"
    "update_client_name.php"
    "update_password.php"
    "set_password.php"
    "set_user_password.php"
    "create_user.php"
    "contact_list.php"
    "contact_update_all.php"
    "merge_databases.php"
    "wipe_database.php"
    "remove_client.php"
)

# CLI utilities
CLI_NAMES=(
    "set_client_admin"
    "update_client_name"
    "update_password"
    "set_user_password"
    "create_user"
    "set_password"
    "contact_list"
    "contact_update_all"
    "remove_client"
    "wipe_database"
    "merge_databases"
)

BIN_DIRS=(
    "/usr/local/bin"
    "${HOME}/.local/bin"
)

# ------------------------------------------------------------------------------
# DISCOVERY: Parse registry files and configuration directories
# ------------------------------------------------------------------------------
REG_FILES=(
    "/etc/tricurse/installations.json"
    "${HOME}/.local/share/tricurse/installations.json"
)

# Read all installations using PHP if available
DISCOVERED_JSON="[]"
if command -v php >/dev/null 2>&1; then
    DISCOVERED_JSON="$(php -r '
        $installs = [];
        $reg_files = ["/etc/tricurse/installations.json", (getenv("HOME") ?: "") . "/.local/share/tricurse/installations.json"];
        foreach ($reg_files as $rf) {
            if ($rf !== "" && file_exists($rf)) {
                $d = @json_decode(@file_get_contents($rf), true);
                if (is_array($d)) {
                    foreach ($d as $item) {
                        $dom = strtolower(trim(preg_replace("#^https?://#i", "", $item["domain"] ?? "")));
                        $dom = preg_replace("#/.*$#", "", $dom);
                        if ($dom === "") continue;
                        $wb = $item["webroot"] ?? "";
                        $key = $dom . "::" . $wb;
                        $item["domain"] = $dom;
                        $installs[$key] = $item;
                    }
                }
            }
        }
        if (is_dir("/etc/tricurse")) {
            foreach (glob("/etc/tricurse/*/contact.conf") ?: [] as $cf) {
                $dom = strtolower(trim(basename(dirname($cf))));
                $found = false;
                foreach ($installs as $k => $v) {
                    if (strpos($k, $dom . "::") === 0) { $found = true; break; }
                }
                if (!$found) {
                    $client_name = ""; $server_role = "main"; $webroot = ""; $messages_dir = ""; $sqlite_path = "";
                    @include $cf;
                    $installs[$dom . "::" . $webroot] = [
                        "domain" => $dom,
                        "client_name" => $client_name,
                        "role" => $server_role ?: "main",
                        "webroot" => $webroot,
                        "messages_dir" => $messages_dir,
                        "db_path" => $sqlite_path,
                        "config_file" => $cf
                    ];
                }
            }
        }
        echo json_encode(array_values($installs));
    ' 2>/dev/null || echo "[]")"
fi

# Count discovered installations
DISCOVERED_COUNT=0
if [ -n "$DISCOVERED_JSON" ] && [ "$DISCOVERED_JSON" != "[]" ]; then
    DISCOVERED_COUNT="$(php -r '$a = json_decode($argv[1], true); echo is_array($a) ? count($a) : 0;' "$DISCOVERED_JSON" 2>/dev/null || echo 0)"
fi

# Normalize TARGET_DOMAIN if provided
if [ -n "$TARGET_DOMAIN" ]; then
    TARGET_DOMAIN="$(echo "$TARGET_DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's|^https\?://||' -e 's|/.*$||')"
fi

# ------------------------------------------------------------------------------
# INTERACTIVE SELECTION MENU (When no domain or --all specified on CLI)
# ------------------------------------------------------------------------------
if [ -z "$TARGET_DOMAIN" ] && [ "$UNINSTALL_ALL" = false ] && [ "$NON_INTERACTIVE" = false ]; then
    if [ "$DISCOVERED_COUNT" -gt 0 ]; then
        echo -e "${BOLD}Discovered Domain Installations on this server:${RESET}"
        php -r '
            $list = json_decode($argv[1], true);
            foreach ($list as $idx => $inst) {
                $num = $idx + 1;
                $dom = $inst["domain"] ?? "unknown";
                $wb  = $inst["webroot"] ?? "(no webroot)";
                $role = $inst["role"] ?? "group";
                echo "  {$num}) \033[1m{$dom}\033[0m [{$role}] -> {$wb}\n";
            }
        ' "$DISCOVERED_JSON" 2>/dev/null || true
        echo ""
        echo "  A) Completely uninstall ALL installations and the entire suite"
        echo "  Q) Cancel / Quit"
        echo ""
        read -r -p "Select an installation to uninstall (1-${DISCOVERED_COUNT}, A, or Q) [Q]: " USER_CHOICE
        USER_CHOICE="$(echo "$USER_CHOICE" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"

        case "$USER_CHOICE" in
            [aA]|[aA][lL][lL])
                UNINSTALL_ALL=true ;;
            [qQ]|""|[eE][xX][iI][tT])
                echo -e "\n${YELLOW}Uninstallation cancelled.${RESET}\n"
                exit 0 ;;
            *)
                if [[ "$USER_CHOICE" =~ ^[0-9]+$ ]] && [ "$USER_CHOICE" -ge 1 ] && [ "$USER_CHOICE" -le "$DISCOVERED_COUNT" ]; then
                    TARGET_DOMAIN="$(php -r '
                        $list = json_decode($argv[1], true);
                        $sel = (int)$argv[2] - 1;
                        echo $list[$sel]["domain"] ?? "";
                    ' "$DISCOVERED_JSON" "$USER_CHOICE" 2>/dev/null || true)"
                else
                    # Assume user typed domain name directly
                    TARGET_DOMAIN="$(echo "$USER_CHOICE" | tr '[:upper:]' '[:lower:]' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's|^https\?://||' -e 's|/.*$||')"
                fi ;;
        esac
    else
        echo -e "${YELLOW}No recorded installations found in /etc/tricurse/installations.json.${RESET}"
        echo "Options:"
        echo "  1) Enter a specific domain name to uninstall"
        echo "  2) Perform full system-wide cleanup of default directories & CLI tools"
        echo "  Q) Cancel / Quit"
        echo ""
        read -r -p "Select option [1/2/Q]: " EMPTY_CHOICE
        case "$EMPTY_CHOICE" in
            1)
                read -r -p "Enter domain name to uninstall: " INPUT_DOM
                TARGET_DOMAIN="$(echo "$INPUT_DOM" | tr '[:upper:]' '[:lower:]' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's|^https\?://||' -e 's|/.*$||')"
                if [ -z "$TARGET_DOMAIN" ]; then
                    echo -e "\n${YELLOW}No domain entered. Uninstallation cancelled.${RESET}\n"
                    exit 0
                fi ;;
            2)
                UNINSTALL_ALL=true ;;
            *)
                echo -e "\n${YELLOW}Uninstallation cancelled.${RESET}\n"
                exit 0 ;;
        esac
    fi
fi

# ==============================================================================
# MODE 1: UNINSTALL A PARTICULAR INSTALLATION FOR A SPECIFIC DOMAIN
# ==============================================================================
if [ -n "$TARGET_DOMAIN" ]; then
    echo -e "${BOLD}${CYAN}==> Target Installation: ${BOLD}${GREEN}${TARGET_DOMAIN}${RESET}"
    echo ""

    # Confirmation prompt if interactive
    if [ "$NON_INTERACTIVE" = false ] && [ "$DRY_RUN" = false ]; then
        echo "This action will remove the installation for domain '${TARGET_DOMAIN}':"
        echo "  - Front-end application files in its webroot"
        if [ "$KEEP_DATA" = false ]; then
            echo "  - Dedicated message storage in /var/lib/tricurse/contact/${TARGET_DOMAIN} (leaving /var/lib/tricurse intact)"
            echo "  - SQLite database records for this domain"
        fi
        echo "  - Configuration files in /etc/tricurse/${TARGET_DOMAIN}"
        echo "  - Registry entries in installations.json"
        echo "  - Other domain installations on this server will remain completely untouched."
        echo ""
        read -r -p "Are you sure you want to uninstall '${TARGET_DOMAIN}'? [y/N]: " CONFIRM_DOM
        case "$CONFIRM_DOM" in
            y|Y|yes|Yes|YES) ;;
            *)
                echo -e "\n${YELLOW}Uninstallation cancelled.${RESET}\n"
                exit 0 ;;
        esac
        echo ""
    fi

    # 1. Discover webroots and storage specific to this domain
    SPECIFIC_WEBROOTS=()
    SPECIFIC_MSGDIRS=()

    if [ -n "$EXTRA_WEBROOT" ]; then
        SPECIFIC_WEBROOTS+=("$EXTRA_WEBROOT")
    fi

    if [ -n "$DISCOVERED_JSON" ] && [ "$DISCOVERED_JSON" != "[]" ]; then
        MATCHED_DATA="$(php -r '
            $list = json_decode($argv[1], true);
            $target = $argv[2];
            foreach ($list as $inst) {
                if (strtolower($inst["domain"] ?? "") === $target) {
                    if (!empty($inst["webroot"])) echo "WEBROOT:" . $inst["webroot"] . "\n";
                    if (!empty($inst["messages_dir"])) echo "MSGDIR:" . $inst["messages_dir"] . "\n";
                    if (!empty($inst["db_path"])) echo "MSGDIR:" . dirname($inst["db_path"]) . "\n";
                }
            }
        ' "$DISCOVERED_JSON" "$TARGET_DOMAIN" 2>/dev/null || true)"

        while IFS= read -r line; do
            if [[ "$line" == WEBROOT:* ]]; then
                SPECIFIC_WEBROOTS+=("${line#WEBROOT:}")
            elif [[ "$line" == MSGDIR:* ]]; then
                SPECIFIC_MSGDIRS+=("${line#MSGDIR:}")
            fi
        done <<< "$MATCHED_DATA"
    fi

    # Only clean webroots that were registered or explicitly provided via -d / -w

    # 2. Clean Webroot Application Files
    echo -e "${BOLD}==> Step 1: Cleaning webroot files for ${TARGET_DOMAIN}...${RESET}"
    UNIQUE_SPECIFIC_WEBROOTS=()
    for w in "${SPECIFIC_WEBROOTS[@]}"; do
        w_clean="$(echo "$w" | sed 's:/*$::')"
        [ -z "$w_clean" ] && continue
        already=false
        for uw in "${UNIQUE_SPECIFIC_WEBROOTS[@]}"; do
            if [ "$uw" = "$w_clean" ]; then already=true; break; fi
        done
        [ "$already" = false ] && UNIQUE_SPECIFIC_WEBROOTS+=("$w_clean")
    done

    for w in "${UNIQUE_SPECIFIC_WEBROOTS[@]}"; do
        if [ -L "$w" ]; then
            remove_path "$w" "webroot symlink"
        elif [ -d "$w" ]; then
            IS_OUR_APP=false
            if [ -f "${w}/index.php" ]; then
                if grep -q -E "(addr\.bar|HotLetter|Federated Inter-Site|format_address_display|get_logged_in_username)" "${w}/index.php" 2>/dev/null; then
                    IS_OUR_APP=true
                fi
            elif [ -f "${w}/db_config.php" ] || [ -f "${w}/signup.html" ]; then
                IS_OUR_APP=true
            fi

            bname="$(basename "$w")"
            if [ "$bname" = "hotletter" ] || [ "$bname" = "contact" ]; then
                IS_OUR_APP=true
            fi

            if [ "$IS_OUR_APP" = true ]; then
                echo -e "  Cleaning web directory: ${CYAN}${w}${RESET}"
                if [ -d "${w}/documents" ]; then
                    remove_path "${w}/documents" "package documents directory"
                fi
                for pf in "${PACKAGE_FILES[@]}"; do
                    remove_path "${w}/${pf}" "app file"
                done
                if [ "$bname" = "hotletter" ] || [ "$bname" = "contact" ]; then
                    remove_dir_if_empty "$w"
                    if [ -d "$w" ] && [ "$(ls -A "$w" 2>/dev/null | wc -l)" -eq 0 ]; then
                        remove_path "$w" "empty web directory"
                    fi
                fi
            fi
        fi
    done

    # 3. Clean Message & Database Storage for this domain
    echo -e "\n${BOLD}==> Step 2: Cleaning message & database storage for ${TARGET_DOMAIN}...${RESET}"
    if [ "$KEEP_DATA" = true ]; then
        echo "  --keep-data enabled: message storage and database files preserved."
    else
        # Dedicated disk storage in /var/lib/tricurse/contact/<domain>
        # STRICT CONSTRAINT: NEVER DELETE /var/lib/tricurse ITSELF!
        if [ -d "/var/lib/tricurse/contact/${TARGET_DOMAIN}" ]; then
            remove_path "/var/lib/tricurse/contact/${TARGET_DOMAIN}" "domain message directory"
        fi

        # Dedicated user-level storage in ~/.local/share/tricurse/<domain>
        if [ -d "${HOME}/.local/share/tricurse/${TARGET_DOMAIN}" ]; then
            remove_path "${HOME}/.local/share/tricurse/${TARGET_DOMAIN}" "user domain storage"
        fi

        # Any extra discovered message dirs for this domain
        for md in "${SPECIFIC_MSGDIRS[@]}"; do
            md_clean="$(echo "$md" | sed 's:/*$::')"
            [ -z "$md_clean" ] && continue
            if [ "$md_clean" = "/var/lib/tricurse" ] || [ "$md_clean" = "/var/lib" ] || [ "$md_clean" = "/var" ] || [ "$md_clean" = "/" ] || [ "$md_clean" = "/var/lib/tricurse/contact" ]; then
                continue
            fi
            if [ -d "$md_clean" ]; then
                remove_path "$md_clean" "recorded domain message directory"
            fi
        done

        # If shared contact.sqlite exists, purge records for this domain
        SHARED_DBS=(
            "/var/lib/tricurse/contact/contact.sqlite"
            "${HOME}/.local/share/tricurse/contact.sqlite"
        )
        for sdb in "${SHARED_DBS[@]}"; do
            if [ -f "$sdb" ] && command -v php >/dev/null 2>&1; then
                if [ "$DRY_RUN" = true ]; then
                    echo -e "  ${YELLOW}[DRY-RUN] Would purge database records for '${TARGET_DOMAIN}' from:${RESET} ${sdb}"
                else
                    TARGET_DOM="$TARGET_DOMAIN" DB_FILE="$sdb" php -r '
                        $db = getenv("DB_FILE");
                        $dom = getenv("TARGET_DOM");
                        try {
                            $pdo = new PDO("sqlite:" . $db);
                            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                            $pdo->prepare("DELETE FROM users WHERE LOWER(domain) = ?")->execute([$dom]);
                            $pdo->prepare("DELETE FROM main_subscriptions WHERE LOWER(client_domain) = ?")->execute([$dom]);
                            $pdo->prepare("DELETE FROM site_settings WHERE setting_key LIKE ? OR setting_key LIKE ?")->execute([$dom . "::%", "%::" . $dom]);
                            $pdo->prepare("DELETE FROM login_attempts WHERE LOWER(domain) = ?")->execute([$dom]);
                            $pdo->prepare("DELETE FROM push_subscriptions WHERE LOWER(domain) = ?")->execute([$dom]);
                            $pdo->prepare("DELETE FROM push_disabled_sources WHERE LOWER(domain) = ?")->execute([$dom]);
                        } catch (Exception $e) {}
                    ' 2>/dev/null || true
                    echo -e "  ${GREEN}✓ Purged SQLite records for '${TARGET_DOMAIN}' from:${RESET} ${sdb}"
                fi
            fi
        done
    fi

    # 4. Clean Configuration & Registry Entries for this domain
    echo -e "\n${BOLD}==> Step 3: Cleaning configurations & registries for ${TARGET_DOMAIN}...${RESET}"
    remove_path "/etc/tricurse/${TARGET_DOMAIN}/contact.conf" "domain config"
    remove_path "/etc/tricurse/${TARGET_DOMAIN}.conf" "domain flat config"
    remove_path "/etc/tricurse/${TARGET_DOMAIN}" "domain config directory"
    remove_path "${HOME}/.local/share/tricurse/${TARGET_DOMAIN}/contact.conf" "user domain config"
    remove_path "${HOME}/.local/share/tricurse/${TARGET_DOMAIN}" "user domain config directory"

    # Remove from installations.json registries
    for rf in "${REG_FILES[@]}"; do
        if [ -f "$rf" ] && command -v php >/dev/null 2>&1; then
            if [ "$DRY_RUN" = true ]; then
                echo -e "  ${YELLOW}[DRY-RUN] Would remove '${TARGET_DOMAIN}' registry entry from:${RESET} ${rf}"
            else
                TARGET_DOM="$TARGET_DOMAIN" REG_PATH="$rf" php -r '
                    $rf = getenv("REG_PATH");
                    $dom = getenv("TARGET_DOM");
                    $data = @json_decode(@file_get_contents($rf), true);
                    if (is_array($data)) {
                        $new_data = [];
                        foreach ($data as $item) {
                            $idom = strtolower(trim(preg_replace("#^https?://#i", "", $item["domain"] ?? "")));
                            $idom = preg_replace("#/.*$#", "", $idom);
                            if ($idom === $dom) continue;
                            $new_data[] = $item;
                        }
                        @file_put_contents($rf, json_encode($new_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                ' 2>/dev/null || true
                echo -e "  ${GREEN}✓ Removed registry entry for '${TARGET_DOMAIN}' from:${RESET} ${rf}"
            fi
        fi
    done

    # 5. Clean loopback host entry in /etc/hosts
    if [ -f "/etc/hosts" ] && grep -qi "\b${TARGET_DOMAIN}\b" "/etc/hosts" 2>/dev/null; then
        if [ "$DRY_RUN" = true ]; then
            echo -e "  ${YELLOW}[DRY-RUN] Would clean loopback entry for '${TARGET_DOMAIN}' in /etc/hosts${RESET}"
        else
            if sed -i -E "s/[[:space:]]+${TARGET_DOMAIN}\b//g" /etc/hosts 2>/dev/null; then
                sed -i -E '/^[0-9\.:]+[[:space:]]*$/d' /etc/hosts 2>/dev/null || true
                echo -e "  ${GREEN}✓ Cleaned loopback host entry for '${TARGET_DOMAIN}' in /etc/hosts${RESET}"
            elif [ "$IS_ROOT" = false ] && command -v sudo >/dev/null 2>&1; then
                sudo sed -i -E "s/[[:space:]]+${TARGET_DOMAIN}\b//g" /etc/hosts 2>/dev/null || true
                sudo sed -i -E '/^[0-9\.:]+[[:space:]]*$/d' /etc/hosts 2>/dev/null || true
                echo -e "  ${GREEN}✓ Cleaned loopback host entry for '${TARGET_DOMAIN}' in /etc/hosts (via sudo)${RESET}"
            fi
        fi
    fi

    # 6. Check remaining installations to decide on CLI utilities
    REMAINING_COUNT=0
    for rf in "${REG_FILES[@]}"; do
        if [ -f "$rf" ] && command -v php >/dev/null 2>&1; then
            c="$(php -r '$d = @json_decode(@file_get_contents($argv[1]), true); echo is_array($d) ? count($d) : 0;' "$rf" 2>/dev/null || echo 0)"
            ((REMAINING_COUNT += c)) || true
        fi
    done

    echo -e "\n${BOLD}==> Step 4: Assessing CLI management utilities...${RESET}"
    if [ "$REMAINING_COUNT" -gt 0 ]; then
        echo -e "  ${CYAN}Notice: Retained CLI utilities in /usr/local/bin (${REMAINING_COUNT} other domain installation(s) remain on this server).${RESET}"
    else
        echo -e "  ${YELLOW}Notice: No other domain installations remain on this server.${RESET}"
        DO_PURGE_CLI="$PURGE_CLI"
        if [ "$DO_PURGE_CLI" = false ] && [ "$NON_INTERACTIVE" = false ] && [ "$DRY_RUN" = false ]; then
            read -r -p "Would you like to remove the CLI management utilities from /usr/local/bin as well? [y/N]: " PURGE_PROMPT
            case "$PURGE_PROMPT" in
                y|Y|yes|Yes|YES) DO_PURGE_CLI=true ;;
            esac
        fi

        if [ "$DO_PURGE_CLI" = true ]; then
            for bdir in "${BIN_DIRS[@]}"; do
                if [ -d "$bdir" ]; then
                    for util in "${CLI_NAMES[@]}"; do
                        remove_path "${bdir}/${util}" "CLI binary"
                        remove_path "${bdir}/${util}.php" "CLI symlink"
                    done
                fi
            done
        else
            echo "  Retained CLI management utilities in /usr/local/bin."
        fi
    fi

    echo -e "\n${BOLD}${GREEN}=================================================================="
    if [ "$DRY_RUN" = true ]; then
        echo "    DRY-RUN COMPLETE: No files were removed.                      "
    else
        echo "    UNINSTALLATION OF '${TARGET_DOMAIN}' COMPLETE!                "
    fi
    echo -e "==================================================================${RESET}"
    if [ "$DRY_RUN" = false ]; then
        echo -e "Removed: ${BOLD}${GREEN}${REMOVED_FILES}${RESET} file(s) / symlink(s), and ${BOLD}${GREEN}${REMOVED_DIRS}${RESET} directory/directories."
        echo -e "Parent directory ${BOLD}/var/lib/tricurse${RESET} was safely left untouched."
    fi
    echo ""
    exit 0
fi

# ==============================================================================
# MODE 2: COMPLETE SUITE UNINSTALLATION (ALL DOMAINS & SYSTEM UTILITIES)
# ==============================================================================

# Confirmation prompt if interactive
if [ "$NON_INTERACTIVE" = false ] && [ "$DRY_RUN" = false ]; then
    echo "This script will completely uninstall the addr.bar / HotLetter package from this server."
    echo "It will remove:"
    echo "  - CLI management utilities in /usr/local/bin and ~/.local/bin"
    echo "  - Application files in webroots (index.php, db.php, auth.php, etc.)"
    if [ "$KEEP_DATA" = false ]; then
        echo "  - Contact message storage in /var/lib/tricurse/contact (leaving /var/lib/tricurse intact)"
        echo "  - Contact database storage in ~/.local/share/tricurse (leaving parent directory intact)"
    fi
    echo "  - Package configuration files and registries"
    echo "  - Temporary dispatch logs in /tmp"
    echo ""
    read -r -p "Are you sure you want to completely remove this package? [y/N]: " CONFIRM
    case "$CONFIRM" in
        y|Y|yes|Yes|YES) ;;
        *)
            echo -e "\n${YELLOW}Uninstallation cancelled.${RESET}\n"
            exit 0 ;;
    esac
    echo ""
fi

# ------------------------------------------------------------------------------
# STEP 1: DISCOVER REGISTERED INSTALLATIONS & WEBROOTS BEFORE CLEANUP
# ------------------------------------------------------------------------------
echo -e "${BOLD}==> Step 1: Discovering registered installations...${RESET}"

WEBROOTS_TO_CLEAN=()
MESSAGES_TO_CLEAN=()

if [ -n "$EXTRA_WEBROOT" ]; then
    WEBROOTS_TO_CLEAN+=("$EXTRA_WEBROOT")
fi

for rf in "${REG_FILES[@]}"; do
    if [ -f "$rf" ]; then
        if command -v php >/dev/null 2>&1; then
            FOUND_DATA="$(php -r '
                $f = $argv[1];
                $data = @json_decode(@file_get_contents($f), true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (!empty($item["webroot"])) echo "WEBROOT:" . $item["webroot"] . "\n";
                        if (!empty($item["messages_dir"])) echo "MSGDIR:" . $item["messages_dir"] . "\n";
                        if (!empty($item["db_path"])) echo "MSGDIR:" . dirname($item["db_path"]) . "\n";
                    }
                }
            ' "$rf" 2>/dev/null || true)"
            while IFS= read -r line; do
                if [[ "$line" == WEBROOT:* ]]; then
                    WEBROOTS_TO_CLEAN+=("${line#WEBROOT:}")
                elif [[ "$line" == MSGDIR:* ]]; then
                    MESSAGES_TO_CLEAN+=("${line#MSGDIR:}")
                fi
            done <<< "$FOUND_DATA"
        fi
    fi
done

# Standard default locations to check
COMMON_WEBROOTS=(
    "/var/www/html/hotletter"
    "/var/www/html/contact"
    "/var/www/hotletter"
    "/var/www/contact"
)
for cw in "${COMMON_WEBROOTS[@]}"; do
    if [ -e "$cw" ] || [ -L "$cw" ]; then
        WEBROOTS_TO_CLEAN+=("$cw")
    fi
done

echo "  Found ${#WEBROOTS_TO_CLEAN[@]} webroot candidate(s) to verify."

# ------------------------------------------------------------------------------
# STEP 2: REMOVE CLI MANAGEMENT BINARIES & SYMLINKS
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}==> Step 2: Removing CLI utilities in /usr/local/bin and ~/.local/bin...${RESET}"

for bdir in "${BIN_DIRS[@]}"; do
    if [ -d "$bdir" ]; then
        for util in "${CLI_NAMES[@]}"; do
            remove_path "${bdir}/${util}" "CLI binary"
            remove_path "${bdir}/${util}.php" "CLI symlink"
        done
    fi
done

# ------------------------------------------------------------------------------
# STEP 3: CLEAN WEBROOT FILES & SYMLINKS
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}==> Step 3: Cleaning application files from webroots...${RESET}"

# Deduplicate webroots
UNIQUE_WEBROOTS=()
for w in "${WEBROOTS_TO_CLEAN[@]}"; do
    w_clean="$(echo "$w" | sed 's:/*$::')"
    [ -z "$w_clean" ] && continue
    already=false
    for uw in "${UNIQUE_WEBROOTS[@]}"; do
        if [ "$uw" = "$w_clean" ]; then already=true; break; fi
    done
    [ "$already" = false ] && UNIQUE_WEBROOTS+=("$w_clean")
done

for w in "${UNIQUE_WEBROOTS[@]}"; do
    if [ -L "$w" ]; then
        remove_path "$w" "webroot symlink"
    elif [ -d "$w" ]; then
        IS_OUR_APP=false
        if [ -f "${w}/index.php" ]; then
            if grep -q -E "(addr\.bar|HotLetter|Federated Inter-Site|format_address_display|get_logged_in_username)" "${w}/index.php" 2>/dev/null; then
                IS_OUR_APP=true
            fi
        elif [ -f "${w}/db_config.php" ] || [ -f "${w}/signup.html" ]; then
            IS_OUR_APP=true
        fi

        bname="$(basename "$w")"
        if [ "$bname" = "contact" ] || [ "$bname" = "hotletter" ]; then
            IS_OUR_APP=true
        fi

        if [ "$IS_OUR_APP" = true ]; then
            echo -e "  Cleaning web directory: ${CYAN}${w}${RESET}"
            if [ -d "${w}/documents" ]; then
                remove_path "${w}/documents" "package documents directory"
            fi
            for pf in "${PACKAGE_FILES[@]}"; do
                remove_path "${w}/${pf}" "app file"
            done
            if [ "$bname" = "contact" ] || [ "$bname" = "hotletter" ]; then
                remove_dir_if_empty "$w"
                if [ -d "$w" ] && [ "$(ls -A "$w" 2>/dev/null | wc -l)" -eq 0 ]; then
                    remove_path "$w" "empty web directory"
                fi
            fi
        fi
    fi
done

if [ -L "/var/www/html/hotletter" ]; then
    remove_path "/var/www/html/hotletter" "webroot symlink"
fi

# ------------------------------------------------------------------------------
# STEP 4: CLEAN DATABASE & MESSAGE STORAGE
# (CRITICAL CONSTRAINT: NEVER DELETE /var/lib/tricurse ITSELF!)
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}==> Step 4: Cleaning message & database storage...${RESET}"

if [ "$KEEP_DATA" = true ]; then
    echo "  --keep-data was set: skipping database removal."
else
    # 1. Clean /var/lib/tricurse/contact (ONLY the contact subdirectory!)
    if [ -d "/var/lib/tricurse/contact" ]; then
        remove_path "/var/lib/tricurse/contact" "message storage directory"
        echo -e "  ${CYAN}Notice: Preserved parent directory /var/lib/tricurse (other packages may reside here).${RESET}"
    fi

    # 2. Clean ~/.local/share/tricurse contact databases
    USER_TRICURSE="${HOME}/.local/share/tricurse"
    if [ -d "$USER_TRICURSE" ]; then
        remove_path "${USER_TRICURSE}/contact.sqlite" "database file"
        remove_path "${USER_TRICURSE}/contact.sqlite-wal" "database WAL"
        remove_path "${USER_TRICURSE}/contact.sqlite-shm" "database SHM"
        remove_path "${USER_TRICURSE}/contact" "user contact directory"

        for sub in "${USER_TRICURSE}"/*; do
            if [ -d "$sub" ]; then
                if [ -f "${sub}/contact.sqlite" ] || [ -f "${sub}/contact.conf" ]; then
                    remove_path "${sub}/contact.sqlite" "domain database"
                    remove_path "${sub}/contact.sqlite-wal" "domain WAL"
                    remove_path "${sub}/contact.sqlite-shm" "domain SHM"
                    remove_path "${sub}/contact.conf" "domain config"
                    remove_dir_if_empty "$sub"
                fi
            fi
        done
        echo -e "  ${CYAN}Notice: Preserved parent directory ${USER_TRICURSE}.${RESET}"
    fi

    # 3. Clean any additional message directories recorded in installations.json
    for md in "${MESSAGES_TO_CLEAN[@]}"; do
        md_clean="$(echo "$md" | sed 's:/*$::')"
        [ -z "$md_clean" ] && continue
        if [ "$md_clean" = "/var/lib/tricurse" ] || [ "$md_clean" = "/var/lib" ] || [ "$md_clean" = "/var" ] || [ "$md_clean" = "/" ] || [ "$md_clean" = "/etc" ] || [ "$md_clean" = "/etc/tricurse" ]; then
            continue
        fi
        if [ -d "$md_clean" ]; then
            remove_path "$md_clean" "recorded message directory"
        fi
    done
fi

# ------------------------------------------------------------------------------
# STEP 5: CLEAN CONFIGURATION & REGISTRIES
# (PRESERVES /etc/tricurse ITSELF IF OTHER FILES EXIST)
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}==> Step 5: Cleaning package configurations & registries...${RESET}"

if [ -d "/etc/tricurse" ]; then
    remove_path "/etc/tricurse/contact.conf" "configuration file"
    remove_path "/etc/tricurse/installations.json" "installations registry"
    remove_path "/etc/tricurse/server.json" "server config"

    for dconf in /etc/tricurse/*/contact.conf; do
        if [ -f "$dconf" ]; then
            ddir="$(dirname "$dconf")"
            remove_path "$dconf" "domain config"
            remove_dir_if_empty "$ddir"
        fi
    done
    remove_dir_if_empty "/etc/tricurse"
fi

if [ -d "${HOME}/.local/share/tricurse" ]; then
    remove_path "${HOME}/.local/share/tricurse/installations.json" "user installations registry"
    remove_path "${HOME}/.local/share/tricurse/server.json" "user server config"
    remove_path "${HOME}/.local/share/tricurse/contact.conf" "user config"
    remove_dir_if_empty "${HOME}/.local/share/tricurse"
fi

# ------------------------------------------------------------------------------
# STEP 6: CLEAN TEMPORARY LOGS IN /tmp
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}==> Step 6: Cleaning temporary log files in /tmp...${RESET}"

remove_path "/tmp/addr_bar_push_notification.log" "push log"
for tlog in /tmp/addr_bar_*.log /tmp/hotletter_*.log /tmp/contact_*.log; do
    if [ -f "$tlog" ]; then
        remove_path "$tlog" "temp log"
    fi
done

# ------------------------------------------------------------------------------
# SUMMARY
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}${GREEN}=================================================================="
if [ "$DRY_RUN" = true ]; then
    echo "    DRY-RUN COMPLETE: No files were removed.                      "
else
    echo "    UNINSTALLATION COMPLETE!                                      "
fi
echo -e "==================================================================${RESET}"
if [ "$DRY_RUN" = false ]; then
    echo -e "Removed: ${BOLD}${GREEN}${REMOVED_FILES}${RESET} file(s) / symlink(s), and ${BOLD}${GREEN}${REMOVED_DIRS}${RESET} directory/directories."
    echo -e "Parent directory ${BOLD}/var/lib/tricurse${RESET} was safely left untouched."
fi
echo ""
