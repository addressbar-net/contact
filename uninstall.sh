#!/usr/bin/env bash
# ==============================================================================
# addr.bar / HotLetter / Contact Complete Uninstallation Script
# ==============================================================================
# Safely and completely removes the addr.bar federated messaging suite:
#   - CLI utilities and symlinks from /usr/local/bin and ~/.local/bin
#   - Web frontend files and symlinks from webroot (e.g. /var/www/html/contact, hotletter)
#   - Database and message storage in /var/lib/tricurse/contact (PRESERVES /var/lib/tricurse itself!)
#   - User-level database storage in ~/.local/share/tricurse (PRESERVES parent directory)
#   - Package configuration files and registries
#   - Temporary logs in /tmp
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
EXTRA_WEBROOT=""

# Parse command line options
while [[ $# -gt 0 ]]; do
    case "$1" in
        -y|--yes)
            NON_INTERACTIVE=true; shift ;;
        -n|--dry-run)
            DRY_RUN=true; shift ;;
        --keep-data)
            KEEP_DATA=true; shift ;;
        -w|-d|--dir|--directory|--webroot)
            EXTRA_WEBROOT="$2"; shift 2 ;;
        -h|--help)
            echo "Usage: sudo ./uninstall.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  -y, --yes          Non-interactive mode (proceed without confirmation)"
            echo "  -n, --dry-run      Show what files and directories would be removed without deleting"
            echo "  --keep-data        Remove application scripts and CLI tools, but keep databases/messages"
            echo "  -w, -d, --dir DIR  Specify an additional webroot directory to clean"
            echo "  -h, --help         Display this help message and exit"
            exit 0 ;;
        *)
            echo -e "${RED}Unknown option: $1${RESET}" >&2
            exit 1 ;;
    esac
done

IS_ROOT=false
if [ "$(id -u)" -eq 0 ]; then
    IS_ROOT=true
fi

echo -e "\n${BOLD}${CYAN}=================================================================="
echo "    addr.bar / HotLetter Complete Package Uninstaller             "
echo -e "==================================================================${RESET}\n"

if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Notice: Running in DRY-RUN mode. No files will be modified or removed.${RESET}\n"
fi

if [ "$KEEP_DATA" = true ]; then
    echo -e "${YELLOW}Notice: --keep-data is enabled. Databases and message stores will be preserved.${RESET}\n"
fi

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
        # Check if directory is empty
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

# ------------------------------------------------------------------------------
# STEP 1: DISCOVER REGISTERED INSTALLATIONS & WEBROOTS BEFORE CLEANUP
# ------------------------------------------------------------------------------
echo -e "${BOLD}==> Step 1: Discovering registered installations...${RESET}"

WEBROOTS_TO_CLEAN=()
MESSAGES_TO_CLEAN=()

# Include extra webroot if provided
if [ -n "$EXTRA_WEBROOT" ]; then
    WEBROOTS_TO_CLEAN+=("$EXTRA_WEBROOT")
fi

# Check installations.json registries
REG_FILES=(
    "/etc/tricurse/installations.json"
    "${HOME}/.local/share/tricurse/installations.json"
)

for rf in "${REG_FILES[@]}"; do
    if [ -f "$rf" ]; then
        # Parse webroots and message storage dirs with PHP if available
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
        # If webroot is a symlink (e.g. /var/www/html/hotletter -> contact), remove the symlink
        remove_path "$w" "webroot symlink"
    elif [ -d "$w" ]; then
        # Check if this index.php belongs to addr.bar before deleting
        IS_OUR_APP=false
        if [ -f "${w}/index.php" ]; then
            if grep -q -E "(addr\.bar|HotLetter|Federated Inter-Site|format_address_display|get_logged_in_username)" "${w}/index.php" 2>/dev/null; then
                IS_OUR_APP=true
            fi
        elif [ -f "${w}/db_config.php" ] || [ -f "${w}/signup.html" ]; then
            IS_OUR_APP=true
        fi

        # If directory basename is 'contact' or 'hotletter', it is our dedicated directory
        bname="$(basename "$w")"
        if [ "$bname" = "contact" ] || [ "$bname" = "hotletter" ]; then
            IS_OUR_APP=true
        fi

        if [ "$IS_OUR_APP" = true ]; then
            echo -e "  Cleaning web directory: ${CYAN}${w}${RESET}"
            # Remove documents directory if inside webroot
            if [ -d "${w}/documents" ]; then
                remove_path "${w}/documents" "package documents directory"
            fi
            # Remove all package files
            for pf in "${PACKAGE_FILES[@]}"; do
                remove_path "${w}/${pf}" "app file"
            done
            # If dedicated contact/hotletter dir and now empty (or contains no custom files), remove dir
            if [ "$bname" = "contact" ] || [ "$bname" = "hotletter" ]; then
                remove_dir_if_empty "$w"
                # If still exists, check remaining files
                if [ -d "$w" ]; then
                    rem_count="$(ls -A "$w" 2>/dev/null | wc -l)"
                    if [ "$rem_count" -eq 0 ]; then
                        remove_path "$w" "empty web directory"
                    fi
                fi
            fi
        fi
    fi
done

# Check if /var/www/html/hotletter symlink is still around
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
        # Notice: /var/lib/tricurse itself is NEVER deleted!
        echo -e "  ${CYAN}Notice: Preserved parent directory /var/lib/tricurse (other packages may reside here).${RESET}"
    fi

    # 2. Clean ~/.local/share/tricurse contact databases
    USER_TRICURSE="${HOME}/.local/share/tricurse"
    if [ -d "$USER_TRICURSE" ]; then
        remove_path "${USER_TRICURSE}/contact.sqlite" "database file"
        remove_path "${USER_TRICURSE}/contact.sqlite-wal" "database WAL"
        remove_path "${USER_TRICURSE}/contact.sqlite-shm" "database SHM"
        remove_path "${USER_TRICURSE}/contact" "user contact directory"

        # Check domain subdirectories in ~/.local/share/tricurse
        for sub in "${USER_TRICURSE}"/*; do
            if [ -d "$sub" ]; then
                # If it contains contact.sqlite or contact.conf, remove those files
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
        # Safety check: Never delete root, /var, /var/lib, /var/lib/tricurse, /etc, etc.
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

# Package files inside /etc/tricurse
if [ -d "/etc/tricurse" ]; then
    remove_path "/etc/tricurse/contact.conf" "configuration file"
    remove_path "/etc/tricurse/installations.json" "installations registry"
    remove_path "/etc/tricurse/server.json" "server config"

    # Remove domain subdirectories that hold contact.conf
    for dconf in /etc/tricurse/*/contact.conf; do
        if [ -f "$dconf" ]; then
            ddir="$(dirname "$dconf")"
            remove_path "$dconf" "domain config"
            remove_dir_if_empty "$ddir"
        fi
    done
    remove_dir_if_empty "/etc/tricurse"
fi

# Package files inside ~/.local/share/tricurse
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
