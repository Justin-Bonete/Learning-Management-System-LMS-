# Ilaw LMS: backend and database (phase 1)

PHP 8.3 API (no framework dependencies) + PostgreSQL 16 + Docker, following the research document's architecture:
REST under `/api/v1`, session cookies with CSRF tokens, role AND object-level authorization, append-only audit log.

## Run it
```
cp .env.example .env            # then change every password
docker compose up -d --build
docker compose exec api php bin/seed.php --demo
sh tests/smoke.sh http://localhost:8080
```
The schema loads from `db/migrations/` on the first start only. To reset: `docker compose down -v`.
Status: written but not yet executed. PHP was not available where this was authored, so run the smoke test first.

## What exists
| Area | Endpoints |
|---|---|
| Auth | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` (5 failed logins per 15 min lock the email) |
| Users (admin) | `GET/POST /users`, `PATCH /users/{id}` (deactivate, never delete), `GET /audit-logs` |
| Academics | `GET/POST /terms`, `GET/POST /courses`, `GET /courses/{id}`, `POST /courses/{id}/publish`, `POST /courses/{id}/sections`, `POST /sections/{id}/enrollments` |
| Content | `GET/POST /courses/{id}/modules`, `POST /modules/{id}/lessons`, `GET /lessons/{id}`, `PUT /lessons/{id}/progress`, `GET /courses/{id}/progress`, announcements |
| Assessment | `GET/POST /courses/{id}/assignments`, `POST /assignments/{id}/submissions` (JSON or multipart file), `GET /assignments/{id}/submissions`, `POST /submissions/{id}/grade`, `GET /submissions/{id}/file` |
| Learner | `GET /me/grades`, `GET /me/notifications`, `POST /notifications/{id}/read` |

State-changing calls need the `X-CSRF-Token` returned by login. Business rules enforced on the server: submission time and lateness
use the database clock, late work can be disallowed per assignment, one submission per learner per assignment, grades cannot
exceed max points, grade changes log old and new values, uploads are limited to PDF/DOCX/ZIP/PNG/JPG up to 5 MB with content-type
checking and are stored outside the web root.

## Next phases
2. React + TypeScript front end (reuse the prototype screens) served behind the same origin, plus resource uploads on lessons.
3. Quizzes (tables exist), gradebook export, course catalog, password reset by email.
4. Hardening: HTTPS reverse proxy, backups with a restore test, rate limiting at the proxy, automated tests in CI, malware scanning for uploads.
