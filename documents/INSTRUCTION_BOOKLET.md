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
6. **The Inventory Rule: Batch-Flush Archiving & Fixed-Page Artifacts**
7. **Archive Printouts, PNG Export & The Selective Redaction Spell**
8. **The Status Gauges: Unread Counter & The Roll Up Spell**
9. **Reconnaissance: The Pre-Flight Availability Radar**
10. **The Alchemist’s Press: Exporting Letters to PNG**
11. **The Ritual of Cleansing: `wipe_database.php`**
12. **Troubleshooting & The Master Registry**
13. **90-Day Limited Warranty & Software License**

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

### Identity Verification & The Rented Domain Safeguard
Domain names are rented properties on the internet; another person could lease a domain name that was used by someone else before. To safeguard against identity confusion and address spoofing:
- **Client Name Transmission**: Every letter sent to an external destination automatically transmits the administrator-configured **Client Name** in the protocol payload alongside the sender's domain.
- **Recipient Verification Display**: In the recipient's inbox and archive, the card displays both the author's verified name and the originating host (e.g. `From: Neolamia (hotletter.neolamia.com)`).
- **Rented Domain Protection**: The recipient can immediately distinguish the true personal identity of the sender, even if the underlying domain is re-rented or operated by a new party in the future.

---

## 6. THE INVENTORY RULE: BATCH-FLUSH ARCHIVING & FIXED-PAGE ARTIFACTS

Your nocturnal desk cannot be allowed to clutter. To maintain absolute speed and focus, each tab enforces the **Batch-Flush Model**:

```
 ACTIVE TAB QUEUE (Up to 20 Letters in Memory)
 [Letter 1] ... [Letter 20]
              |
   (When Letter 21 Arrives)
              |
              +---> [Letter 21] Remains in Active Inbox (Rank 1)
              |
              v
 +-------------------------------------------------------+
 |  THE IMMUTABLE ARCHIVE VAULT (Fixed 20-Letter Pages)  |
 |  ===================================================  |
 |  Page 1: [Letters 1 - 20 Sealed as a Unit]            |
 |  Page 2: [Letters 21 - 40 Sealed Upon Letter 41]      |
 |  Page 3: [Letters 41 - 60 Sealed Upon Letter 61]      |
 +-------------------------------------------------------+
```

1. **The Batch-Flush Workflow**:
   - Incoming letters accumulate in your active inbox queue from 1 up to 20 letters.
   - When the **21st letter arrives**, the active queue flushes: the previous 20 letters are sealed together as a completed, permanent **Archive Page** (Page 1), and **exactly 1 letter** (the 21st) remains in the active inbox.
   - New letters then accumulate from 1 up to 20. When the 41st letter arrives, the next 20 are sealed into Page 2, again leaving 1 letter in the active inbox.
2. **Fixed-Page Database Optimization**:
   - Unlike legacy systems that partition archives by calendar year and month, the archive vault is structured around **fixed sequential pages** of exactly 20 messages.
   - The database is optimized with composite indexes on `(domain, tab_type, archive_page)` and `(message_id, domain)`.
   - Point queries retrieve entire archive pages instantaneously without full-table scans or CPU-heavy `strftime` calendar calculations.
3. **Immutable 20-Letter Pages**:
   - Every archive page contains **exactly 20 letters**.
   - Once sealed, **an archive page will always remain the same**. Its contents never shift, re-paginate, or alter order.
4. **Tab-Specific Vaults**:
   - `Client Archive`: Holds sealed blocks of the client’s private external correspondence.
   - `Revealed Archive`: Holds sealed blocks of revealed external letters (with dual dates preserved).
   - `Group Archive`: Holds sealed blocks of transmitter and inner-server correspondence.
5. **Loss of Read/Unread Status**: Once letters cross the threshold into the archive vault, they transcend temporal urgency—**all archived messages lose their Read/Unread status**.

---

## 7. ARCHIVE PRINTOUTS, HORIZONTAL PNG EXPORT & PROGRAMMABLE OFFSETS

Clients can obtain formal paper printouts or horizontally-aligned PNG image artifacts for any archive page:

### Horizontally-Aligned Page Printouts (`[ 🖨 Print Page (Horizontal) ]`)
- Pressing **`[ 🖨 Print Page (Horizontal) ]`** triggers the dedicated landscape `@media print` engine (`size: landscape`).
- Letters are aligned horizontally across the sheet in side-by-side columns, utilizing the full horizontal breadth of the printout.
- Strips away all digital chrome (navigation bars, sidebars, buttons, rollup toggles, and form controls), producing a clean, high-contrast, black-and-white printout or PDF document.
- Message cards include `page-break-inside: avoid` to prevent mid-card splits.

### Standard 1350px Horizontal PNG Strip (`[ 📷 Export Page As PNG (1350px Horizontal) ]`)
- Renders the entire archive page into a continuous horizontal image strip (`Archive-Page-[N]-[tab]-1350px-horizontal.png`).
- **Standard Letter Width (1350 Pixels)**: Every letter occupies a standard horizontal slot of **exactly 1350 pixels**. Total image width is `N × 1350 pixels`.
- **Programmable Offset Alignment**: Because each letter is allocated an exact 1350px horizontal slot, external applications and custom scripts can reliably locate and crop any letter on the page using a simple mathematical offset:
  ```
  Letter X Offset = Letter Index × 1350 pixels
  Slot 0: 0px  |  Slot 1: 1350px  |  Slot 2: 2700px  |  ...  |  Slot 19: 25650px
  ```
- **Auto-Decided Height (Up to 32,000 Pixels)**: In this modern computing era, vertical constraints are eliminated. The graphics engine automatically calculates the maximum height needed across all included letters and scales the canvas height accordingly, supporting massive documents up to **32,000 pixels** tall without downsampling or degradation.

### Selective Message Omission
- Every letter card in the archive features an **`[x] Include`** checkbox.
- The client can uncheck specific messages to omit them from both the physical printout and the exported PNG artifact.
- **`[ Select All ]` / `[ Deselect All ]`** shortcuts allow rapid bulk customization.

### Automatic Body Redaction for Long Unknown Letters
- When toggling **`☑ Hide Long External Letters (Subject Only)`**:
  - The system analyzes messages originating from external/unknown sources.
  - If a letter contains an excessively long body, the body content is cleanly omitted from both the printout and the PNG artifact.
  - The letter's **Subject**, **Sender Name**, **Origin Domain**, **Received Date**, and **Badges** remain fully visible, replaced by a clean notification:
    `[ ℹ️ Message body omitted — Long external letter from unknown source (Subject preserved) ]`
  - This prevents unwieldy multi-page printouts and bloated PNG artifacts caused by voluminous unsolicited correspondence.

---

## 8. THE STATUS GAUGES: UNREAD COUNTER & THE ROLL UP SPELL

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

## 9. RECONNAISSANCE: THE PRE-FLIGHT AVAILABILITY RADAR

Before expending valuable energy sending a letter into the digital matrix, you can probe the target host:

```
 [ Target Address: https://novy.addr.bar ]  --->  [ 🔍 Check ]
                       ||
                       \/
 🟢 Available: Client: Jason (novy.addr.bar) — Website is online and available to receive messages. (8 msgs remaining today)
```

1. **Dual Endpoint Discovery**: The radar probes both root endpoints (`https://domain.com/`) and subdirectory contact endpoints (`https://domain.com/contact/`).
2. **Client Name & Identity Verification**: Clicking `[ 🔍 Check ]` in the Send Mail form queries the target destination and retrieves the configured **Client Name** (e.g. `Client: Jason (novy.addr.bar)`). Senders can verify the actual person or account at that address *before* sending mail, guarding against rented or reassigned domain confusion.
3. **Quota Tracking**: If the destination enforces rate limiting, the radar detects and displays their remaining daily message allowance.
4. **Subdomain Rate Isolation**:
   - Client subdomains (e.g. `novy.addr.bar` and `karo.addr.bar`) share the server's rate budget.
   - When a message is dispatched, the remaining quota decreases synchronously across client endpoints on the server.
   - Central contact points (e.g. `tricurse.com/contact`) report availability without being throttled by client-tier quotas.
5. **Automated Reply Verification**: Clicking `[ ↩ Reply ]` on an external letter automatically initiates a pre-flight radar check, ensuring the recipient's website is online before you begin drafting your response.

---

## 10. THE ALCHEMIST’S PRESS: EXPORTING LETTERS TO PNG

Should you wish to preserve a letter as a physical artifact outside the database, Hotletter features a built-in rendering press:

1. Click **`[ 📷 Download As PNG ]`** on any letter card.
2. The letter is parsed by the graphics subsystem:
   - **Standard 1350px Width**: Every letter is rendered at the standardized width of **1350 pixels**, matching the archive offset specification.
   - **Auto-Decide Height**: Vertical height is calculated dynamically based on message length, scaling up to **32,000 pixels**.
   - Fonts are rasterized to crisp, high-resolution monochrome typography.
   - The brass border, chassis stamps, and timestamps are rendered with pixel precision.
   - **Hyperlink Preservation**: Any hyperlinks contained in the message body retain their distinct underlined, highlighted style in the generated image.
3. The image is downloaded to your machine as an immutable `.png` artifact.

---

## 11. THE RITUAL OF CLEANSING: `wipe_database.php`

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

## 12. TROUBLESHOOTING & THE MASTER REGISTRY

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

## 13. 90-DAY LIMITED WARRANTY & SOFTWARE LICENSE

### Neolamia Autonomous Software Warranty
Neolamia warrants to the original operator that this software module shall perform in substantial accordance with the specifications described in this booklet. This warranty does not apply if the software cartridge has been corrupted by corporate tracking scripts, unapproved proxy gateways, or daylight exposure.

```
                  (C) 2026 NEOLAMIA & HOTLETTER.BAND
                     ALL RIGHTS RESERVED. LICENSED BY
               THE AUTONOMOUS FEDERATION OF PEER WEBSITES.
```
