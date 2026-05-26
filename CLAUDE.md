# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

TSSSaver is a PHP web frontend for [tsschecker](https://github.com/1Conan/tsschecker) that saves iOS SHSH2 blobs for Apple devices. Users register their device ECID; the system calls `tsschecker` via `shell_exec` to save blobs to `shsh/<ECID>/<version>/`.

## Setup

1. Copy `inc/config.sample.php` → `inc/config.php` and fill in DB credentials, server URL, and optionally reCaptcha keys.
2. Copy `.env.example` → `.env` and fill in DB credentials and webserver user.
3. Run `install-tssaver.sh` to: download `tsschecker` + `img4tool` binaries to `/usr/local/bin/`, import `devices.sql` schema, and install the daily cron job.

The install script expects `jq`, `curl`, `unzip`, and `mysql` CLI to be available.

## Architecture

**Request flow (index.php)**
- POST `submit`: validate ECID (hex/dec) + device model → look up device identifier in `json/deviceModels.json` → verify against `json/devices.json` → insert into DB if new → call `saveBlobs()`.
- POST `delete`: validate ECID → remove from DB.
- JS on page load: fetches `json/<deviceType>.json` to populate the model dropdown dynamically; ECID hex→dec conversion uses `conv.php`.

**`saveBlobs()` (`inc/functions.php`)**  
Fetches currently-signed firmwares from `$signedVersionsURL` (ipsw.me API), then for each signed firmware runs `./bin/tsschecker` twice per version: once with no apnonce (`randomapnonce/`) and once per apnonce entry in the `$apnonce` array (each in `apnonce-<hash>/`). Uses `escapeshellarg` on all user-supplied values passed to shell.

**`cron.php`**  
Runs daily (via cron, installed by `install-tssaver.sh`). Iterates all ECIDs from DB and re-runs the same blob-saving logic to catch newly signed firmwares. Note: `tsschecker` binary path differs — `cron.php` uses `./tsschecker` (project root) while `functions.php` uses `./bin/tsschecker`.

**`check.php`**  
Standalone blob verifier: accepts a `.shsh2` file upload and runs `./bin/img4tool_linux -a -s` on it to check for the `rosi` tag.

**`shsh/` directory**  
Served via nginx with `fancyindex`. Structure: `shsh/<ECID>/<version>/<apnonce-hash>/` or `shsh/<ECID>/<version>/randomapnonce/`.

## Config Keys (`inc/config.php`)

| Key | Purpose |
|-----|---------|
| `$serverURL` | Base URL, used to construct blob download links |
| `$savedSHSHURL` | `$serverURL . "shsh/"` |
| `$db[*]` | MySQL connection (server, name, user, password, table) |
| `$reCaptcha[*]` | Toggle + public/private keys for Google reCaptcha |
| `$signedVersionsURL` | ipsw.me API endpoint for signed firmwares |
| `$apnonce` | Array of apnonce hashes to save blobs for |
| `$suPassword` | Plaintext password for `admin.php`; empty string disables access |

## Admin Panel (`admin.php`)

Session-based SU panel. Requires `$suPassword` set in `inc/config.php`. Features:
- List all tracked ECIDs with links to their blobs
- Delete any ECID from the tracking list (DB only, files kept)
- Add a new ECID + trigger immediate blob save (same flow as `index.php` minus captcha)

CSRF tokens via `$_SESSION['csrf_token']`. Password compared with `hash_equals()`.

## Device List Updater (`update_devices.php`)

Fetches `https://api.ipsw.me/v4/devices` and rebuilds all JSON device files.  
Supported types: `iPhone`, `iPad`, `iPod`, `AppleTV`. Identifiers sorted numerically (e.g. `iPhone16,3` sorts after `iPhone10,1`).

Files updated: `json/devices.json`, `json/deviceModels.json`, `json/iPhone.json`, `json/iPad.json`, `json/iPod.json`, `json/AppleTV.json`.

Cron (weekly Sunday 3am):
```
0 3 * * 0 php /path/to/tsssaver/update_devices.php >> /var/log/tsssaver_update.log 2>&1
```

## DB Schema

Single table `devices` (defined in `devices.sql`):  
`deviceIdentifier` (text), `deviceType` (text), `deviceID` (text), `deviceECID` (bigint, PK).

## nginx Config for SHSH Directory Listing

```nginx
location /shsh {
    index index.php;
    fancyindex on;
    fancyindex_exact_size off;
    fancyindex_header "/index/header.html";
    fancyindex_footer "/index/footer.html";
    fancyindex_localtime on;
}
```
