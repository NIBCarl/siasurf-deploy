# AGENTS.md — SiaSurf

## Core Working Rules

- **App vs. plans/notes:** If notes, plans or instructions disagree with the app, follow the app and point out the mismatch in your reply. The exception is a plan marked "in progress": that's where the app is going, so follow the plan. When you finish a plan, change its status to done.
- **Leave unrequested plans/TODOs alone:** Leave notes, TODOs and plans you weren't asked about alone. If you notice one that looks relevant, list it at the end of your reply instead of acting on it.
- **Check existing docs/plans before new features:** Before building a new feature, check existing project docs (`AGENTS.md`, `siasurf-app/PRODUCT.md`, `siasurf-app/FEEDBACK_STRIKE_APPEAL.md`) and any active plan files for overlap or conflict, and flag any mismatch before proceeding.
- **Commit after every build:** Each time a piece of work builds successfully, commit it right away with a clear message before moving on.

Behavioral guidelines derived from [Andrej Karpathy's observations](https://x.com/karpathy/status/2015883857489522876) on LLM coding pitfalls, sourced from `multica-ai/andrej-karpathy-skills`.
Merge with the project facts below.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

---

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

---

# SiaSurf — Project Facts

**SiaSurf: Smart Booking and Instructor Verification Platform with Real-time Safety Integration and Monitoring.** Surf-tourism platform for Siargao Island, Philippines, integrating with SISA (Siargao Island Surfing Association) / LGU.

**Roles:** `student` (tourist) · `instructor` (SISA-verified, Level 1–3) · `admin` (SISA/LGU).

**Stack (as actually installed):** Laravel `^12.0` (12.54) · PHP `^8.2` · Vue `3.4+` + Inertia `2` + TypeScript · Tailwind `3` · MySQL · Laravel Reverb · Leaflet `1.9.4` (OpenStreetMap) · Spatie Permission · DomPDF · `maatwebsite/excel` · `simple-qrcode` · Stormglass tide API · Open-Meteo weather/marine API · Payment services (`XenditPaymentService` → `PayMongoPaymentService` → `MockPaymentService` via `AppServiceProvider`).

**Storage/queue reality:** `FILESYSTEM_DISK=local`, `QUEUE_CONNECTION=database`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `BROADCAST_CONNECTION=reverb`. Redis/S3 are planned, **not wired**. Do not write code that assumes they exist.

**Scale:** 31 enums · 24 models · 43 controllers · 26 services · 8 policies · 2 observers · 13 events · 4 jobs · 19 notifications · 9 commands · 49 test files (438 test methods).

## Critical safety rules — NEVER override

Enforced in `app/Services/SafetyService.php`. There is **no admin bypass**. Violations are collected, logged to `safety_validation_logs`, then `SafetyViolationException` is thrown **before** the booking is created.

| # | Rule | Enforcement |
|---|---|---|
| 1 | Level 1 → max **1** student, beginners only | `validateLevel1Rules` |
| 2 | Level 2 → **1 beginner** OR up to **2 intermediate** students | `validateLevel2Rules` |
| 3 | Level 3 → **1–3 students** of the **same skill level** | `validateLevel3Rules` |
| 4 | Age `< 5` → reject; age `5–12` → Level 3 instructor required (`< 18` requires parental consent) | `validateAgeRules` |
| 5 | Spot difficulty must not exceed surfer's level (group capped by least-experienced participant) | `validateSurfSpotDifficultyRules` |
| 6 | Instructor certification must match lesson difficulty | `validateCertificationMatchRules` |

> **Canonical rule:** Code is canonical (`InstructorLevel.php`, `SafetyService.php`, `config/siasurf.php`): `L1 = 1 beginner`, `L2 = 1 beginner or 2 intermediate`, `L3 = 1–3 same-level`, `age 5–12 → Level 3`. No two-instructor team feature exists (do not "fix" code to match legacy `L3 = 5` / `L1 = 2` / 2-instructor tables in stale docs).

## Business rules

| Rule | Value |
|---|---|
| Service hourly rates (`config/siasurf.php`) | Teaching **₱600–₱1,000** · Guiding **₱800–₱1,500** · Coaching **₱1,500–₱2,000** (per authorized lesson type & instructor-selected surf zones) |
| Session length | **2 hours** standard (`1-hour` preset & flexible whole-hour end times supported) |
| Platform service fee (`BR-PRC-03`) | **7%** on top of lesson price (non-refundable) |
| Booking horizon | **60 days** |
| Checkout hold | **15 minutes** (`Reserved` during waiver/payment selection) |
| Instructor acceptance window | **2 hours** (clamped to **1 hour** before lesson start) |
| Cancellation & refunds (`BR-CXL-01`–`10`) | Long-term (`>30d` ahead): 100% within 30d of payment, then falls through to short-term window (`7+d` 100% · `3–6d` 50% · `<3d` 0%); refunds past 30d route to `ManualRequired` for SISA manual completion; instructor/weather/reschedule-decline = 100% lesson price |
| V3 3-Strike Ladder (`StrikeService` / `SuspensionService` / `InstructorFine`) | **1 active strike:** warning + **₱500 fine** (`InstructorFine`, `FineStatus::Due`, collected outside system) · **2 active strikes:** **7-day (1-week) suspension** (requires SISA reinstatement approval) · **3+ active strikes:** **30-day (1-month) suspension** + SISA ID confiscation outside system (`requires_sisa_id_confiscation`) |
| Strike weights & admin outcomes | `Minor = 1` · `Major = 2` · `Critical = 0` in-system (Critical incidents handled outside system by SISA/authorities). `IncidentOutcome` is canonical for admin decisions (`no_strike`, `1_strike`, `2_strikes`, `3_strikes`, `critical`) |
| Surf spots | **31 active `(Inner)`/`(Outer)` breaks** across **18 Siargao locations** with curated photo gallery (`config/surf_spot_images.php`, `public/images/surf-spots/`) |
| Cash payment marking | Within **24 hours** (supports `PaymentStatus::Underpaid` shortfall tracking) |
| Document retention | **7 years** (automated monthly waiver cleanup via `CleanupExpiredWaivers`) |
| Legacy `PricingService` constants | `ADD_ONS` (video ₱500 · photography ₱800) & `WEEKLY_DISCOUNT`/`MONTHLY_DISCOUNT` exist in `PricingService` but are unwired in the booking UI |

**Feedback → strike flow (BR-5.8–5.15 & V3 ladder):** public reviews never create strikes. A private concern is a `SafetyIncident` created `pending` with `strike_weight = 0`; only SISA confirmation reaches `StrikeService`, which writes an append-only `instructor_strikes` ledger row and applies the V3 ladder (`InstructorFine` at 1 strike, 7-day suspension at 2 strikes, 30-day suspension + SISA ID confiscation at 3+ strikes; Critical handled outside system). Revoking strikes voids unpaid `Due` fines and reconciles active suspensions. One audited appeal per suspension (`AppealService`). Automatic suspensions clear served strikes; manual ones do not.

## Architecture map

```
app/
├── Enums/          domain logic lives here (InstructorLevel::maxStudents, SkillLevel::canSurfDifficulty)
├── Services/       business logic — controllers stay thin
├── Policies/       per-resource authorization
├── Observers/      SafetyIncidentObserver delegates to StrikeService; BookingObserver opens chat
├── Http/Controllers/{Admin,Instructor,Student,Auth}/
├── Events/ Jobs/ Notifications/ Console/Commands/
resources/js/Pages/  {Admin,Instructor,Student,Booking,Public,Auth,Profile}/
```

**Key services:** `SafetyService` (rules) · `AvailabilityService` (slot capacity, single source of truth) · `PricingService` · `StrikeService` / `SuspensionService` / `AppealService` · `FeedbackService` · `WaiverService` / `ReceiptService` · `BookingRequestGuard` (abuse limits) · `BookingAcceptanceService` · `BookingCancellationService` · `BookingChangeService` · `RefundCalculatorService` · `SkillAssessmentService` · `StormglassService` · `QRCodeService` · `PayMongoPaymentService` / `XenditPaymentService` / `MockPaymentService`.

## Conventions

- `$fillable` whitelist only — never `$guarded = []`.
- Cast enums, dates, booleans on every model.
- Form Requests for validation; never `$request->all()` unvalidated.
- Explicit return types on relationships and service methods.
- Eager-load to avoid N+1.
- Tests: PHPUnit, `RefreshDatabase`, feature tests per endpoint + unit tests per service.

## Commands

```bash
composer dev                       # serve + queue + pail + vite (concurrently)
php artisan test                   # full suite
php artisan test --filter=X        # targeted
./vendor/bin/pint                  # format
php artisan migrate:fresh --seed   # reset dev DB
npm run build                      # vue-tsc + vite build
```

## Known open issues

Do not treat these as fixed without verifying:

1. `tests/TestCase.php` does not clear Spatie's `PermissionRegistrar` cache between tests, so full-suite test isolation remains order-sensitive.
2. Stale `"or a team of 2 instructors"` error copy remains in `app/Services/SafetyService.php:232` and a stale `"Level 3 -> 5"` docblock comment remains in `config/siasurf.php:141`.

## Reference docs (retained)

| File | Purpose |
|---|---|
| `words/Capstone2_Introduction_V3.docx` | Latest capstone manuscript (Chapters 1–4) |
| `siasurf-app/PRODUCT.md` | Product & feature overview |
| `siasurf-app/FEEDBACK_STRIKE_APPEAL.md` | Feedback, strike & appeal specification |
| `siasurf-app/SECURITY.md` | Security practices & headers |
| `siasurf-app/TESTING.md` | Testing guide |
| `docs/siargao-surf-spots/` | Siargao surf-spot GIS/map reference data |

Skill available at `siasurf-app/.opencode/skills/karpathy-guidelines/SKILL.md`.
