# YOTC C1600 EPON monitoring

The C1600 integration reads the device's existing web management endpoints. It does not configure the OLT or ONUs. It was commissioned against a C1600 with two EPU8A boards and firmware `109_V300R003B021C24`; other board layouts need their own verified parser profile.

## Add a device

Choose **YOTC C1600 EPON**, Real mode, and a web transport. HTTPS is the default and validates the device certificate. HTTP must be selected explicitly, only accepts private/loopback addresses, and displays an unencrypted-connection notice. Enter the device's web username/password and management port, then use Test Connection and Save.

The existing database credential fields are reused with encrypted Laravel casts. Passwords and session cookies are excluded from dashboard data, errors, and audit messages. Cookies stay in memory and are discarded after logout; credentials are never included in a URL. The client does not follow redirects, use an environment proxy, disable certificate validation, or fall back from HTTPS to HTTP.

## Available data

- Product/firmware identity, uptime, CPU, memory, temperature, and installed boards.
- PON inventory and the complete registered ONU list, with MAC identity, device label, ONU model, and observed status.
- ONU receive power and OLT/PON receive power, in dBm, collected sequentially in bounded batches.
- Aggregate PON download/upload rates from the explicit `DS Rate(bps)` / `US Rate(bps)` fields in `show perf current SLOT/PON`, using separate Telnet credentials and an explicit port allowlist.

`online` becomes `working`. Device statuses such as `poweroff`, `offline`, and `restorefail` retain their device meaning; the integration does not infer LOS or dying-gasp. MAC addresses are normalized to uppercase colon notation. Interfaces use `epon-olt_SLOT/PON` and `epon-onu_SLOT/PON:ONU`.

## Polling and preservation

Automatic inventory, system, names, and optical tasks have a minimum interval of 300 seconds. Optics reads at most 12 working ONUs per task, oldest successful observation first, with a 250 ms gap between requests. A five-minute task interval is **not** a five-minute refresh guarantee for every ONU: a large device requires several batches. Each ONU retains its own observation timestamp. Unread or failed samples retain previous readings and freshness; an explicit unavailable optical reading is stored as null.

Malformed or incomplete inventory is rejected before replacing stored inventory. All eight PONs on each verified EPU8A board must be present. Optical and names-only tasks preserve port totals and cannot create a replacement ONU identity; inventory performs identity updates. Local customer names and tags are preserved.

Provisioning, ONU configuration, reboot, Web CLI, backup, and restore are blocked in both UI and backend for C1600. Existing ZTE functionality remains separate. Web polling supports only a fixed list of verified read endpoints; some GET endpoints on the original device can change configuration, so arbitrary GET requests are not permitted.

## PON traffic setup

Sync inventory first, then edit the OLT and open **Traffic PON via Telnet**. Supply separate Telnet login and optional enable passwords; an empty enable password on first setup uses the saved Telnet password. Blank password edits preserve existing encrypted values. Select one to four synced PON ports and enable traffic monitoring. These settings do not replace web credentials.

Device performance sampling must already be enabled for each selected PON. Saving NMS settings does not run `perf` commands. During commissioning, an authorized operator may enable a specific verified port with `perf 1/1 enable` in the device configuration context; `perf 1/1 disable` reverses that setting. The production poller only enters the session context and issues fixed read commands. It never enables, disables, clears, or saves device configuration. Persistence of performance sampling across an OLT reboot has not been verified.

Background traffic reads have a 60-second minimum interval. Opening a configured PON's Traffic chart permits live reads at a minimum of 15 seconds, shared with the background collector. A cycle uses one bounded Telnet session, at most four selected PONs, and a 250 ms gap. Web and Telnet sessions share a physical-device lock; busy background reads retry. Failed or disabled sampling retains prior readings and timestamps instead of recording zero. Actual zero rates are accepted.

Streaming HTTP responses are closed on success, logout, and error paths. Authenticated Telnet sessions send bounded session logout commands before closing. A rejected Telnet login pauses further attempts with the same saved credentials for ten minutes; blocked requests do not extend the pause. Updating the saved credentials permits a new attempt. Live charts pause on authentication rejection and keep stored history available. Historical samples remain limited to the selected chart range.

This firmware can redraw the initial `Password:` prompt after Telnet option negotiation. The client waits for the challenge to settle before sending a credential, and tolerates a delayed redraw while awaiting the hostname prompt. Each login and enable password is sent only once, with fixed authentication deadlines. A repeated prompt by itself is not an immediate password rejection.

The device also emits unsolicited timestamped `[module] [DEBUG]` lines during login and command output. Only that verified own-line format is filtered from protocol matching and parsed responses. Incomplete log records are retained across reads and commands; other output is preserved. Debug output does not extend the authentication settling period or cause duplicate pager acknowledgements.

The verified example is PON **1/1** on firmware `109_V300R003B021C24`. Downstream maps to download and upstream to upload. The device's rate averaging window is unverified. `DS/US Octets` and `Lapse Time` did not have verified cumulative-counter semantics, so byte counters remain null and volume is hidden. Traffic per ONU is not supported. Each additional PON must have its sampling enabled and be explicitly selected before collection.

Open **Port** on the OLT card, then **Traffic** on a configured PON. The ONU detail view also provides **Traffic PON SLOT/PON (gabungan)** when its parent PON is configured. This shortcut opens the same aggregate PON chart; it does not measure the individual ONU.

## Network security

HTTP does not encrypt credentials, cookies, or readings. Telnet also sends credentials and commands without encryption. Encrypted storage in NMS and read-only endpoint restrictions do not change that. Restrict device access to a dedicated management network and authorized hosts; do not expose management interfaces publicly. Prefer HTTPS when supported or a protected management path. A VPN only protects the portion inside its tunnel. A dedicated device account with read-only permissions is preferable if the firmware supports it; application-level restrictions do not reduce an admin account's device privileges.

Commissioning found HTTPS port 443 closed. SSH port 22 was open, but its advertised legacy host-key/cipher algorithms and nonstandard compression did not negotiate with the available clients. No algorithms were weakened, no host-key validation was bypassed, and no device setting was changed to work around this. Network isolation, VPN configuration, device accounts, and firmware security have not been audited or changed by this integration.

## Validation

`php artisan test --filter=Yotc` exercises transport restrictions, authentication and redaction, parser completeness, optical batching and persistence, feature gates, and registration. `tests/Browser/yotc-monitoring.spec.js` verifies the form, EPON positions, HTTP notices, and blocked controls using mocked device data.
