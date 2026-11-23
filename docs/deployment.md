# Deployment preparation

> **No deployment has been performed.** Deploying to any host needs the project owner's approval
> (Master Instructions; API V1.3.1 §18, Phase 14). This document describes how releases are
> built and how they are meant to be installed once approved.

Decision **H1** (approved): prefer Git/SSH deployment on Hostinger; if unavailable, use FTP with
checksums. Dependencies are built in CI and shipped with each release; Composer is **not**
required on the production server.

## 1. Build a release

CI builds a release on every push (job `build`, artifact `release-<commit>`). To build locally:

```bash
bin/build-release.sh
```

Set the version explicitly when preparing a numbered release:

```bash
RELEASE_VERSION=0.2.0 bin/build-release.sh
```

Output in `dist/`:

| File | Purpose |
|---|---|
| `ehf-v3-api-<version>.zip` | The release: one folder containing the application and its production `vendor/` |
| `ehf-v3-api-<version>.zip.sha256` | Checksum of the zip, to verify the download |

Inside the release folder:

| Path | Purpose |
|---|---|
| `public/` | The only directory the web server may serve (`index.php`, `.htaccess`) |
| `src/`, `config/`, `vendor/` | Application code and production dependencies |
| `storage/logs/` | Private, writable log directory (deny-all `.htaccess`) |
| `.htaccess` | Deny-all safety net for the release root |
| `.env.example` | The configuration variables; copy to `.env` on the server and fill in |
| `RELEASE` | Version, commit, build time, PHP version, `composer.lock` checksum |
| `CHECKSUMS.sha256` | SHA-256 of every file in the release |

The build script refuses to produce a package if a runtime dependency is missing, a development
tool, test, `.env` or VCS folder is present, or a PHP file has a syntax error. It then serves the
package itself with PHP's built-in server and checks `GET /api/v3/health`.

## 2. Server layout (to be confirmed by the hosting checks)

Preferred: the web root (document root) of the API host points at the release's `public/`:

```
/home/<account>/ehf-v3-api/            ← release folder (not web-served)
├── .env                               ← private configuration (chmod 600)
├── public/                            ← document root of the API host
├── src/  config/  vendor/
└── storage/logs/                      ← writable by PHP
```

If the document root cannot be changed (it must stay `public_html`), copy the contents of
`public/` into `public_html` and change the single `require`/`run` path in `index.php` to point
at the release folder outside `public_html`. Check which layout is possible first (hosting
checklist item 10).

## 3. Install a release (once deployment is approved)

**Git/SSH route (preferred):** upload the zip (or download the CI artifact on the server), check
its `.zip.sha256`, unzip next to the current release, copy `.env`, switch the web root or a
symbolic link to the new release, then run `GET /api/v3/health`.

**FTP route (fallback):**
1. Check the zip against its `.zip.sha256` on your computer.
2. Unzip locally and upload the release folder.
3. Verify the upload against `CHECKSUMS.sha256`. Over SSH: `sha256sum -c CHECKSUMS.sha256`.
   Without SSH, download the uploaded files back and compare, or use the host's file-manager
   checksum tool if it has one.
4. Place `.env` (never uploaded from Git; created on the server from `.env.example`).
5. Call `GET https://<api-host>/api/v3/health` and expect `{"data":{"status":"ok"},…}`.

## 4. Configuration on the server

Create `.env` next to `public/` (never inside it) from `.env.example`:

- `APP_ENV=production` (or `staging`). Production rules are enforced at start-up: HTTPS-only
  CORS origins and HSTS on; invalid configuration stops the app with a generic `500` instead of
  running unsafely.
- `CORS_ALLOWED_ORIGINS` — the exact frontend origin(s), depending on decision **A3**.
- `DB_*` — only once the **new** V3 database and its runtime user exist (Phase 3). Never reuse
  the old database or credentials.
- File permissions: `.env` readable only by the PHP user (for example `600`); `storage/logs`
  writable by PHP.

No real values are kept in this repository.

## 5. Rollback

Keep the previous release folder. To roll back, point the web root (or symbolic link) back at
it. Database rollback procedures arrive with migrations in Phase 3 (Database V1.4 §12–13; A13).
