# Hosting compatibility checklist (read-only)

Source: Backend API Architecture V1.3.1 §15.3 ("Hosting: no assumptions; read-only checks only").
Related decisions: A2 (database engine), H1 (deployment method), H2 (staging), A3 (domains and
SameSite), A11 (email), A13 (backups).

## Status

**Not yet checked.** Phase 2 had no access to Hostinger or InfinityFree (no control panel,
SSH, FTP or credentials in the development environment), so none of the checks below have
been run. Each must be done **read-only**: look, do not change settings.

Record the results in this file (date, who, plan name, result), then review them before Phase 3.

## What the code already handles
| Topic | Prepared in Phase 2 |
|---|---|
| Database engine | Code and tests run on MySQL 8.0 / 8.4 and MariaDB 10.6 / 11.4 (CI matrix; also run locally against throwaway containers). Connections use utf8mb4, UTC and strict SQL mode on both. |
| Composer on the server | Not needed: releases ship `vendor/` (H1). |
| Web root | Only `public/` must be served. The release root carries a deny-all `.htaccess` as a safety net; `public/.htaccess` routes requests to `index.php`. |
| Secrets | Read from a private `.env` next to `public/` (not inside it) or from real environment variables. |
| PHP version | `composer.json` requires PHP ^8.3; dependencies are resolved for PHP 8.3. |

## Checks to perform

| # | Check | Hostinger (production) | InfinityFree (possible staging) | How to check (read-only) | Result |
|---|---|---|---|---|---|
| 1 | PHP version 8.3 (or 8.4) available for the site | Verify | Verify | Control panel PHP settings | — |
| 2 | PHP extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`, `zip`, `intl` (later phases: `gd`) | Verify | Verify | Control panel extension list, or a temporary `phpinfo()` page **only if allowed** — remove it immediately | — |
| 3 | Argon2id password hashing (`PASSWORD_ARGON2ID` defined) | Verify | Verify | `phpinfo()` "password_hash" section | — |
| 4 | Database engine and exact version (MySQL ≥ 8.0.16 or MariaDB ≥ 10.6) | Verify | Verify parity with Hostinger | Control panel / phpMyAdmin "Server version" | — |
| 5 | Separate database users with different privileges (runtime vs migration) | Verify | Verify | Control panel database user screen | — |
| 6 | Column-level privileges and CHECK constraints honoured | Verify | Verify | Plan documentation; confirm in Phase 3 on a staging database | — |
| 7 | Triggers allowed (not required by the design) | Verify | Verify | Plan documentation | — |
| 8 | SSH access, Git deployment (H1 preferred route) | Verify (may depend on plan) | Verify (FTP-only is likely) | Control panel "Advanced → SSH / Git" | — |
| 9 | Cron jobs (later housekeeping: expiry, exports, outbox) | Verify | Verify whether it exists | Control panel cron screen | — |
| 10 | Web root can point at `public/` (or the app can sit outside `public_html` with `public/` contents in `public_html`) | Verify | Verify | Control panel domain / document-root settings | — |
| 11 | `.htaccess` honoured (Apache or LiteSpeed): rewrite to `index.php`, deny rules | Verify | Verify | Server software in control panel; confirm after a staging deploy | — |
| 12 | Private storage outside the web root, writable by PHP (`storage/logs`) | Verify | Verify | File manager permissions | — |
| 13 | Symbolic links (optional for release switching) | Verify | Verify | Plan documentation | — |
| 14 | SSL certificate on the API host | Verify | Verify | Control panel SSL screen | — |
| 15 | Outbound SMTP and sending limits (A11) | Verify | Verify whether outbound mail is allowed | Plan documentation | — |
| 16 | Execution time, memory, upload size, request size and file-count limits | Verify | Verify | Control panel PHP limits | — |
| 17 | Bot protection or browser checks that could block cross-origin JSON API calls | Verify | **Verify specifically** (known to inject browser checks) | Plan documentation; test from a staging frontend later | — |
| 18 | Cookies and sessions between frontend and API domains | Depends on A3 | A free-subdomain staging API with a separately hosted frontend may need `SameSite=None`, which browsers may block (A3, H2) | Decide A3 first | — |
| 19 | Backups and restore (A13) | Verify | Not relied on | Control panel backup screen; plan documentation | — |
| 20 | Outbound HTTPS from PHP (optional leaked-password check, A6) | Verify | Verify | Plan documentation | — |

If InfinityFree cannot run the API faithfully, the alternatives are a separate Hostinger staging
subdomain and database, or local Docker staging (H2). Nothing is changed without approval.
