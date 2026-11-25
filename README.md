# SiaSurf — Production Deployment Repository

**Smart Booking and Instructor Verification Platform with Real-Time Safety Integration and Monitoring** for Siargao Island, Philippines (SISA / LGU).

This repository contains the clean, production-ready SiaSurf application source without local `vendor/` binaries, scratch scripts, or IDE/agent metadata.

## Tech Stack
- **Backend:** PHP 8.4 (`ext-bcmath`, `ext-gd`, `ext-pdo_mysql`, `ext-zip`), Laravel 12, Laravel Reverb, Spatie Permission, DomPDF, Simple QrCode
- **Frontend:** Vue 3, Inertia 2, TypeScript, Tailwind CSS 3, Vite 7, Leaflet
- **Database / Cache / Sessions:** MySQL 8.x (`database` driver — no Redis required)
- **Email:** Resend HTTPS API (`resend/resend-php`) for platforms that block SMTP (such as Railway Hobby), or standard SMTP on VPS hosts

## Production Deployment
See **[deploy/README.md](deploy/README.md)** for the complete deployment guide covering:
- **Railway (Hobby Plan):** MySQL, persistent Volume at `/app/storage/app`, `.env.production.example` variables, one-time `db:seed --force`, Resend domain verification (`siasurf.online`), and optional Worker/Reverb services
- **Self-Hosted Ubuntu VPS + CloudPanel:** `deploy/deploy.sh`, `deploy/nginx-reverb.conf`, `deploy/supervisor-worker.conf`, and `deploy/supervisor-reverb.conf`
