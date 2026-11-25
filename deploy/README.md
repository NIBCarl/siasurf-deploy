# SiaSurf Production Deployment Guide

SiaSurf deploys cleanly to **Railway (Hobby)** from the lightweight `siasurf-deploy` repository (without `vendor/`, `node_modules/`, or local dev/agent files), or alternatively to a self-hosted Ubuntu VPS with CloudPanel.

---

## Option 1: Railway (Hobby Plan — Active Setup)

### Stack Overview
- **Source Repo:** `NIBCarl/siasurf-deploy` (`main` branch)
- **Builder & Runtime:** Railpack **PHP 8.4** (FrankenPHP) + Node.js (`composer.json` requires `php ^8.4` and `ext-bcmath`, `ext-gd`, `ext-pdo_mysql`, `ext-zip`)
- **Database:** Railway **MySQL** (Southeast Asia / Singapore region)
- **Persistent Storage:** Railway **Volume** mounted to **`/app/storage/app`**
- **Email Transport:** **Resend** (`MAIL_MAILER=resend` over HTTPS port 443, verified via `siasurf.online`)
- **Health Check:** `/up`

### 1. Provision Services on Railway
1. Create a Railway project from `NIBCarl/siasurf-deploy` and set **Settings → Deploy → Region** to **Southeast Asia (Singapore)**.
2. Add a **MySQL** service (`+ Create → Database → MySQL`) in the same region.
3. Attach a persistent **Volume** to `siasurf-deploy` (press `Ctrl+K` → **Add Volume**):
   - **Mount Path:** `/app/storage/app`
   - > **Warning:** Mount `/app/storage/app` — **NOT** `/app/storage` — so `storage/framework/views`, `cache`, `sessions`, and `logs` remain writable by the container.
4. Under `siasurf-deploy` → **Settings → Networking**, click **Generate Domain** (e.g., `siasurf-deploy-production.up.railway.app`) or attach a custom domain (`www.siasurf.online` CNAME).
5. Leave **Custom Start Command** **blank** so Railpack starts FrankenPHP automatically. (Do **not** put `artisan migrate` or `db:seed` in Custom Start Command.)

### 2. Configure Variables
Open `siasurf-deploy` → **Variables → Raw Editor** and start from `.env.production.example`:
- Generate `APP_KEY` locally (`php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`).
- Keep `APP_FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, `LOG_CHANNEL=stderr`, and `RAILPACK_PHP_EXTENSIONS=gd,zip,pdo_mysql,bcmath,intl,pcntl`.
- Set `APP_URL` and `SIASURF_PUBLIC_URL` to your exact HTTPS domain so scannable instructor QR codes resolve properly.
- Set `QUEUE_CONNECTION=sync` when running a single web container so verification emails, booking notifications, and PDF receipts send immediately.
- **Email (`MAIL_MAILER=resend`):** Railway Hobby blocks outbound SMTP ports (`25`, `465`, `587`, `2525`). Verify your sending domain in Resend (`TXT resend._domainkey`, `CNAME rsend`, `CNAME send`, single `TXT _dmarc`), create a Sending API key, and set `RESEND_API_KEY` + `MAIL_FROM_ADDRESS="noreply@siasurf.online"`.
- Leave `PAYMONGO_*` and `XENDIT_*` blank to use `MockPaymentService` for demos, or add test/live keys as needed.

### 3. One-Time Database Seed & Storage Link
Once `siasurf-deploy` builds and is **Online**, open the **Console** tab inside the `siasurf-deploy` service and run once:
```bash
php artisan storage:link
php artisan migrate --force
php artisan db:seed --force
php artisan permission:cache-reset
```
> **Important:** `AdminSeeder` creates `siasurfadmin@gmail.com` using `User::create()`. Do **not** leave `db:seed --force` in Railway's **Pre-deploy Command** for future deploys, or subsequent deploys will fail on duplicate admin email. Keep Pre-deploy Command empty or set only to `php artisan storage:link || true && php artisan migrate --force`.
>
> Optional demo instructors for presentations: `php artisan db:seed --class=InstructorSeeder --force`.

### 4. Optional Add-On Services (Queue Worker & Live WebSockets)
If you want asynchronous queues and live Reverb WebSockets on Railway:
- **Worker Service:** Deploy a second service from `NIBCarl/siasurf-deploy` with the same variables, `QUEUE_CONNECTION=database`, and **Custom Start Command**:
  ```bash
  php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
  ```
- **Reverb Service:** Deploy a third service from `NIBCarl/siasurf-deploy`, generate a public domain for it, and set **Custom Start Command**:
  ```bash
  php artisan reverb:start --host=0.0.0.0 --port=$PORT
  ```
  Set `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, and matching `VITE_REVERB_*` variables on the web service, then redeploy the web service so Vite bakes `VITE_REVERB_*` into the bundle.

### 5. Post-Deploy Verification Checklist
- `GET /up` returns HTTP `200` (`Application up`).
- `GET /surf-spots` lists all **31** Siargao surf breaks on the Leaflet map.
- `POST /register` succeeds without `500`, sends a real verification email via Resend, and redirects to the verification screen.
- Instructor QR codes resolve to `https://<your-domain>/qr/{id}` (not `localhost`).
- Check Railway **Deploy Logs** (or `tail -n 60 storage/logs/laravel.log` in Console) for zero uncaught errors.

---

## Option 2: Self-Hosted Ubuntu 24.04 VPS + CloudPanel

If deploying to your own VPS (Azure, DigitalOcean, Lightsail, Hostinger) instead of Railway:
1. Provision Ubuntu 24.04 LTS with PHP 8.4, MySQL 8.x, Node.js 22 LTS, Supervisor, and CloudPanel (free).
2. Clone `siasurf-deploy`, copy `.env.production.example` to `.env`, set local MySQL credentials, `php artisan key:generate`, and run:
   ```bash
   chmod +x deploy/deploy.sh
   ./deploy/deploy.sh --no-pull
   php artisan db:seed --force
   ```
3. Paste `deploy/nginx-reverb.conf` into CloudPanel's site **Vhost** above `location / {`.
4. Install `deploy/supervisor-worker.conf` and `deploy/supervisor-reverb.conf` under `/etc/supervisor/conf.d/` (replacing `__APP_DIR__`), then run `supervisorctl reread && supervisorctl update`.
5. Add the Laravel scheduler cron (`crontab -u siasurf -e`):
   ```cron
   * * * * * cd /home/siasurf/htdocs/your-domain.com && php artisan schedule:run >> /dev/null 2>&1
   ```
6. For future updates, run `./deploy/deploy.sh`.
