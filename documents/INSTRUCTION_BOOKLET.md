# SNS-NL-USA-1
## INSTRUCTION BOOKLET
### NEOLAMIA SERVER SUITE & HOTLETTER EXPANSION MODULE
---

```
________________________________________________________________________________
|                                                                              |
|   ========================================================================   |
|   |                       SUPER NINTENDO ENTERTAINMENT                   |   |
|   |                              SYSTEM COMPATIBLE                       |   |
|   ========================================================================   |
|                                                                              |
|                              N E O L A M I A                                 |
|                                     &                                        |
|                          H O T L E T T E R . B A N D                         |
|                                                                              |
|                   FEDERATED CORRESPONDENCE & DIRECTORY MODULE                |
|                                                                              |
|   [ OFFICIAL SEAL OF AUTONOMOUS COMPUTING ]                                  |
|   This seal is your assurance that Neolamia has reviewed this software       |
|   and verified that it meets our strict standards for nocturnal isolation,   |
|   cryptographic independence, and zero-daemon SQLite persistence.            |
|______________________________________________________________________________|
```

---

## ⚠️ WARNING: PLEASE CAREFULLY READ THE ENCLOSED PRECAUTIONS BEFORE OPERATING YOUR SERVER

A very small portion of server administrators may experience digital fatigue, corporate dread, or disorientation when confronted with neon-lit matrix interfaces and decentralized peer-to-peer protocols. If you experience shadows moving behind your server rack, unsolicited corporate acquisitions, or an overwhelming thirst for fresh crimson packets, step away from the terminal immediately and seek natural sunlight (unless you are a creature of the night, in which case retreat to the shadows).

---

## TABLE OF CONTENTS

1. **The Lore of Neolamia: An Immortal in the Silicon Age**
2. **System Hardware & Architecture: The Two-Cartridge Stack**
3. **Controller Layout & Command Mappings**
4. **Getting Started: The Genesis Installation**
5. **The HUD & The Three Letter Scrolls (Tabs)**
   - *Tab I: Client Letters (Private Correspondence)*
   - *Tab II: Revealed Letters (The Shared Revelation)*
   - *Tab III: Group Letters (Transmitters & Inner Circles)*
6. **The Inventory Rule: The 20-Letter Limit & Immutable Archives**
7. **The Status Gauges: Unread Counter & The Roll Up Spell**
8. **Reconnaissance: The Pre-Flight Availability Radar**
9. **The Alchemist’s Press: Exporting Letters to PNG**
10. **The Ritual of Cleansing: `wipe_database.php`**
11. **Troubleshooting & The Master Registry**
12. **90-Day Limited Warranty & Software License**

---

## 1. THE LORE OF NEOLAMIA: AN IMMORTAL IN THE SILICON AGE

You are a vampire surviving in the 21st century. 

Centuries ago, survival was simple: castles of stone, velvet shadows, and secluded crypts far from the prying eyes of mortal kingdoms. But the world shifted. Stone gave way to silicon; castles were replaced by monolithic glass skyscrapers; whispering courtiers were superseded by sprawling corporate conglomerates. Today, global data monopolies watch every fiber-optic pulse, demanding telemetry, telephone numbers, and corporate credentials for the simple privilege of sending a message.

To walk among mortals in this neo-age—a world reminiscent of a hyper-connected digital Matrix—an ancient entity cannot rely on centralized clouds or monitored exchanges. The prefix **Neo** speaks to this modern condition: an unyielding technological maze where corporations constantly seek to entangle private clients, turning autonomous sovereign beings into tracked commodities.

To preserve autonomy, the **Neolamia** base server was forged: a sleek, dark sanctuary operating directly on bare metal. And atop this sanctuary rests exactly **one** sacred extension cartridge: the **Hotletter** federated correspondence suite ([hotletter.band](https://hotletter.band)). 

Infused with the aesthetic of warm vacuum-tube machinery, cast-iron chassis plates, stamped brass dials, and incandescent pilot lamps, Hotletter bridges the gothic nocturnal past with the cybernetic future. It delivers letters website-to-website, peer-to-peer, bypassing the corporate matrix through direct HTTP POST streams (`stream_context_create()`). No mail daemons. No DNS reverse pointers (PTR). No corporate gatekeepers. Just pure, unadulterated correspondence between sovereign hosts.

---

## 2. SYSTEM HARDWARE & ARCHITECTURE: THE TWO-CARTRIDGE STACK

The Neolamia ecosystem is strictly modular and consists of **exactly two pieces**:

```
+-------------------------------------------------------------+
|               HOTLETTER EXPANSION MODULE                     |
|           https://github.com/addressbar-net/contact          |
|              Reachable at: https://hotletter.band            |
|  (Federated Mail, Three-Tab Sorting, Immutable 20-Scrolls)   |
+-------------------------------------------------------------+
                              ||
                              \/
+-------------------------------------------------------------+
|                 NEOLAMIA BASE SERVER PACKAGE                |
|           https://github.com/addressbar-net/server           |
|                Reachable at: https://neolamia.com            |
|       (Nginx, SSL/TLS SNI, Isolated Directories, Backups)    |
+-------------------------------------------------------------+
                              ||
                              \/
+-------------------------------------------------------------+
|                   BARE METAL HARDWARE / VM                   |
|                    (Linux or Windows Subsystem)              |
+-------------------------------------------------------------+
```

### The Base Cartridge: Neolamia Server (`addressbar-net/server`)
- Repository: [https://github.com/addressbar-net/server](https://github.com/addressbar-net/server)
- Portal: [https://neolamia.com](https://neolamia.com)
- Purpose: Establishes the web server environment, automated SSL certificates, `/etc/tricurse/` configurations, directory sandboxes, and the `html_backup` system.
- Windows Guide: For users setting up the base server from Windows, consult the sacred parchment at:
  [https://github.com/addressbar-net/server/blob/main/documents/install_from_the_windows_operating_system.pdf](https://github.com/addressbar-net/server/blob/main/documents/install_from_the_windows_operating_system.pdf)

### The Add-On Cartridge: The Contact Package (`addressbar-net/contact`)
- Repository: [https://github.com/addressbar-net/contact](https://github.com/addressbar-net/contact)
- Portal: [https://hotletter.band](https://hotletter.band)
- Purpose: Installs directly onto the existing Neolamia server. Provides the complete communication desk, multi-client routing, shared database isolation, and the Hotletter GUI.

---

## 3. CONTROLLER LAYOUT & COMMAND MAPPINGS

```
                 _________________________________________
                /   L   [TOP]                 [TOP]   R   \
               /                                           \
              /       +                              (X)    \
             /     +  |  +                    (Y)           \
            /         +                                  (A) \
           /     [ D-PAD ]          [SELECT]   [START]        \
          /                                              (B)   \
         /                                                      \
```

| Button / Control | Terminal & GUI Equivalent | Action Description |
|:---:|:---|:---|
| **D-PAD UP / DOWN** | Scroll Letter List | Navigate through letters in the active tab queue. |
| **D-PAD LEFT / RIGHT** | Switch Sub-Tabs | Switch between `Client`, `Revealed`, and `Group` tabs. |
| **L BUTTON** | Jump to Archive | Open the Library of Immutable Archive Pages (`tab=archive`). |
| **R BUTTON** | Jump to Outbox / Mail | Open the Composition Desk to draft a new letter (`tab=mail`). |
| **A BUTTON** | **[ ↩ Reply ]** | Reply to the sender (auto-checking destination availability). |
| **B BUTTON** | **[ 🔼 Roll Up ]** | Toggle the Roll Up spell to collapse/expand message body text. |
| **X BUTTON** | **[ 🔍 Check ]** | Query the target host radar for status and remaining quotas. |
| **Y BUTTON** | **[ 📷 PNG Capture ]** | Render and download a high-resolution stamped PNG artifact. |
| **SELECT** | **[ 👁 Reveal to Group ]** | Unveil an external private client letter to the entire server group. |
| **START** | **[ ✓ Mark All Read ]** | Reset the unread counter gauge back to 0. |

---

## 4. GETTING STARTED: THE GENESIS INSTALLATION

When deploying Hotletter upon your Neolamia base server, run the automated installation script. The installer detects whether `addressbar-net/server` is already present, automatically linking directories and triggering `html_backup` upon completion:

```bash
# 1. Clone the contact package onto your Neolamia installation
git clone https://github.com/addressbar-net/contact.git
cd contact

# 2. Run the interactive setup
sudo ./install.sh
```

During installation, the ritual prompts you for:
1. **Target Web Directory (`-d`)**: Where the client web interface will live (e.g., `/var/www/html/contact`).
2. **Persistent Database Directory (`-m`)**: Where the SQLite database safely resides outside the web root (e.g., `/var/lib/tricurse/contact`).
3. **Server Role**: Whether this installation acts as the **Main Contact Point (Central Transmitter)** or an **Individual Client** account.
4. **Master Passphrase (`-p`)**: Encrypted using irreversible bcrypt hashing into `contact.sqlite`.

---

## 5. THE HUD & THE THREE LETTER SCROLLS (TABS)

In the Neolamia suite, correspondence is organized into three strictly partitioned tabs:

```
+=============================================================================+
| [📥 Client Letters (3)] | [👁 Revealed Letters (1)] | [📢 Group Letters (5)] |
+=============================================================================+
```

### Tab I: Client Letters (`client`)
- **Nature**: Strictly private external correspondence involving this specific client.
- **Inclusions**:
  1. Incoming letters sent by an external outside website directly to this client’s domain (e.g., `outside.com` $\rightarrow$ `novy.addr.bar`).
  2. Outbox copies of letters sent by this client to an external website.
  3. **The Dual-Copy Rule**: If a letter was originally received privately by this client and subsequently revealed to the group, **a copy remains preserved in this Client tab** for the original recipient!
- **Exclusions**: Letters sent between server clients (inner-server mail), transmitter broadcasts, and letters received by other clients are **never** present here.
- **Ordering**: Arranged strictly by **Date of Receipt** (`received_at DESC`).

### Tab II: Revealed Letters (`revealed`)
- **Nature**: External letters originally received privately by an individual client that have been unveiled to all members of the server clan.
- **Revelation Triggers**:
  - The recipient clicks the `[ 👁 Reveal to Group ]` button.
  - Or the recipient replies to the external letter and adds another internal server member to the distribution list.
- **The Dual-Date Display**: Every revealed letter proudly displays two distinct temporal anchors:
  - 📅 **Original Received Date**: The exact moment the external mortal reached the client.
  - 👁 **Date of Revelation**: The moment the letter was unveiled to the clan.
- **Ordering**: Arranged strictly at the **Date of Revelation** (`COALESCE(revealed_at, received_at) DESC`).
- **Clan Visibility**: All clients on the server see revealed letters in this tab. However, clients who were *not* the original recipient will *only* see it here, never in their private Client tab!

### Tab III: Group Letters (`group`)
- **Nature**: Collective announcements, broadcast dispatches, and all inner-server communications.
- **The Golden Law**: *"Inner-server letters are all group letters."*
  - If Client A writes to Client B on the same server, that message is an inner-server letter. It is automatically visible to all recipients in the clan and belongs to the **Group** tab.
  - Messages addressed to the server's **Central Transmitter** (e.g., `tricurse.com/contact`) are distributed to all subscribed clients under the Group tab.
- **Ordering**: Arranged by **Date of Receipt** (`received_at DESC`).

---

## 6. THE INVENTORY RULE: THE 20-LETTER LIMIT & IMMUTABLE ARCHIVES

Your nocturnal desk cannot be allowed to clutter. To maintain absolute speed and focus, each tab enforces the **Rule of Twenty**:

```
 ACTIVE TAB QUEUE (Maximum 20 Letters)
 [Msg 1 (Newest)] ... [Msg 20 (Oldest)]
                   |
     (When Msg 21 Arrives)
                   |
                   v
 +-------------------------------------+
 |  THE IMMUTABLE ARCHIVE VAULT        |
 |  =================================  |
 |  Page 1: [20 Sealed Messages]       |
 |  Page 2: [20 Sealed Messages]       |
 |  Page 3: [In Creation... Needs 20]  |
 +-------------------------------------+
```

1. **Active Capacity**: Only the **20 most recent letters** remain in each active tab queue.
2. **Automated Archival**: The moment older letters are pushed past rank 20, they are permanently sealed in the **Library of Archives** (`tab=archive`).
3. **Immutable 20-Letter Pages**:
   - Archive pages are generated in fixed, unalterable blocks of **exactly 20 letters**.
   - An archive page is only finalized when a full block of 20 letters accumulates.
   - Once sealed, **an archive page will always remain the same**. Its contents never shift, re-paginate, or change.
4. **Tab-Specific Archives**:
   - `Client Archive`: Holds sealed blocks of the client’s private external correspondence.
   - `Revealed Archive`: Holds sealed blocks of revealed external letters (with dual dates preserved).
   - `Group Archive`: Holds sealed blocks of transmitter and inner-server correspondence.
5. **Loss of Read/Unread Status**: Once letters cross the threshold into the archive vault, they transcend temporal urgency—**all archived messages lose their Read/Unread status**.

---

## 7. THE STATUS GAUGES: UNREAD COUNTER & THE ROLL UP SPELL

### The Unread Gauge (HUD)
- Located on your command bar: `[ ● Unread Letters: N ]`.
- **The Maximum Cap**: The unread counter is strictly capped at a maximum value of **20**, matching your active inventory.
- **Clearing the Counter**: Clicking `[ ✓ Mark All Read ]` marks all active letters in your current inventory as read, depleting the counter back to **0**.

### The Roll Up Spell
- Long letters take up valuable vertical canvas.
- By pressing the `[ 🔼 Roll Up ]` button on any letter, the message body collapses into darkness, preserving screen space.
- **Header Retention**: Even when rolled up, the letter's Subject, Sender, Timestamps, and Status Badges remain clearly visible.
- **Per-Client Independence**: Your roll-up preferences are saved individually in the `message_rollups` database table. Rolling up a message on your terminal will *never* collapse it for another client viewing the same letter!
- **Global Spell**: Use `[ 🔼 Roll Up All ]` to collapse or expand the entire queue in a single click.

---

## 8. RECONNAISSANCE: THE PRE-FLIGHT AVAILABILITY RADAR

Before expending valuable energy sending a letter into the digital matrix, you can probe the target host:

```
 [ Target Address: https://novy.addr.bar ]  --->  [ 🔍 Check ]
                       ||
                       \/
 🟢 Available: Website is online and available to receive messages. (8 msgs remaining today)
```

1. **Dual Endpoint Discovery**: The radar probes both root endpoints (`https://domain.com/`) and subdirectory contact endpoints (`https://domain.com/contact/`).
2. **Quota Tracking**: If the destination enforces rate limiting, the radar detects and displays their remaining daily message allowance.
3. **Subdomain Rate Isolation**:
   - Client subdomains (e.g. `novy.addr.bar` and `karo.addr.bar`) share the server's rate budget.
   - When a message is dispatched, the remaining quota decreases synchronously across client endpoints on the server.
   - Central contact points (e.g. `tricurse.com/contact`) report availability without being throttled by client-tier quotas.
4. **Automated Reply Verification**: Clicking `[ ↩ Reply ]` on an external letter automatically initiates a pre-flight radar check, ensuring the recipient's website is online before you begin drafting your response.

---

## 9. THE ALCHEMIST’S PRESS: EXPORTING LETTERS TO PNG

Should you wish to preserve a letter as a physical artifact outside the database, Hotletter features a built-in rendering press:

1. Click **`[ 📷 Download As PNG ]`** on any letter card.
2. The letter is parsed by the graphics subsystem:
   - Fonts are rasterized to crisp, high-resolution monochrome typography.
   - The brass border, chassis stamps, and timestamps are rendered with pixel precision.
   - **Hyperlink Preservation**: Any hyperlinks contained in the message body retain their distinct underlined, highlighted style in the generated image.
3. The image is downloaded to your machine as an immutable `.png` artifact.

---

## 10. THE RITUAL OF CLEANSING: `wipe_database.php`

When an installation must be purged of all correspondence—leaving user accounts and server configurations intact—invoke the command-line cleansing ritual:

```bash
# Interactive Mode (Requires confirmation before wiping)
php /addr.bar/tricurse/contact/wipe_database.php

# Immediate Automated Wipe (Skips prompt)
php /addr.bar/tricurse/contact/wipe_database.php --yes
```

### What the Ritual Purges:
- 🗑 `incoming_messages`: Every received letter and group broadcast.
- 🗑 `sent_messages`: Every outgoing dispatch log.
- 🗑 `message_archives`: All sealed archive pages across all tabs.
- 🗑 `message_deletions`: All tombstone deletion markers.
- 🗑 `message_reads`: All read/unread tracking records.
- 🗑 `message_rollups`: All client roll-up states.
- 🔄 `sqlite_sequence`: Auto-increment IDs are reset back to 1.
- 🧹 `VACUUM`: The SQLite database engine is vacuumed, defragmented, and shrunk on disk.

*(Note: User login credentials, domain registrations, and site settings are safely preserved).*

---

## 11. TROUBLESHOOTING & THE MASTER REGISTRY

| Symptom | Cause | Solution |
|---|---|---|
| **Error: Could not connect to SQLite database** | Permissions or wrong path | Run `wipe_database.php` with direct path or check permissions on `/var/lib/tricurse/contact/contact.sqlite` (`chmod 666`). |
| **Letter visible in Group tab but not Client tab** | Inner-Server classification | This is working as intended. Inner-server letters are all group letters! |
| **Revealed letter not in other client's Client tab** | Client isolation | Working as intended. Client tabs ONLY hold correspondence originally visible to that specific client. Other clients view it in their Revealed tab. |
| **Archive page is not visible yet** | Under 20 letters | Archive pages are immutable and strictly require **20 completed letters** before sealing and appearing. |
| **Rate Limit 429 Delay Notice** | Destination daily limit reached | The message is saved in your queue with status `⏳ DELAYED` and will automatically dispatch once the timer expires. |

### Master Registry Commands (CLI)
- `contact_list`: Inspect all registered clients and domains on the server.
- `update_client_name`: Rename a client account.
- `update_password`: Update host password via terminal.
- `remove_client`: Safely deregister and clean up a client installation.

---

## 12. 90-DAY LIMITED WARRANTY & SOFTWARE LICENSE

### Neolamia Autonomous Software Warranty
Neolamia warrants to the original operator that this software module shall perform in substantial accordance with the specifications described in this booklet. This warranty does not apply if the software cartridge has been corrupted by corporate tracking scripts, unapproved proxy gateways, or daylight exposure.

```
                  (C) 2026 NEOLAMIA & HOTLETTER.BAND
                     ALL RIGHTS RESERVED. LICENSED BY
               THE AUTONOMOUS FEDERATION OF PEER WEBSITES.
```
