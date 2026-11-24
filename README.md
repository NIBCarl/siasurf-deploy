# SiaSurf — Production Deployment Repository

**Smart Booking and Instructor Verification Platform with Real-Time Safety Integration and Monitoring** for Siargao Island, Philippines (SISA / LGU).

This repository contains the clean, production-ready SiaSurf application source without local `vendor/` binaries, scratch scripts, or IDE/agent metadata.

## Tech Stack
- **Backend:** PHP 8.2+, Laravel 12, Laravel Reverb (WebSockets), Spatie Permission, DomPDF, Simple QrCode
- **Frontend:** Vue 3, Inertia 2, TypeScript, Tailwind CSS 3, Vite 7, Leaflet
- **Database / Queue / Cache:** MySQL 8.x (`database` driver for sessions, cache, and queues by default — no Redis required)

## Production Deployment (Azure VM + CloudPanel)
See **[deploy/README.md](deploy/README.md)** for the full step-by-step deployment checklist, including:
- `.env.production.example` setup (`APP_FORCE_HTTPS=true`, `SIASURF_PUBLIC_URL`, MySQL, Reverb WSS)
- `deploy/deploy.sh` zero-fuss update script
- `deploy/nginx-reverb.conf` WebSocket proxy snippet for CloudPanel
- `deploy/supervisor-worker.conf` & `deploy/supervisor-reverb.conf` daemon templates
