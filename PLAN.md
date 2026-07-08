# EmployeeHub — Roadmap

The shared identity service for this portfolio — an OAuth2/OIDC provider,
not a shared code library. Built as a portfolio/staff-level engineering
project, not a funded startup — see the portfolio-wide ADRs in
`portfolio-plan/docs/adr/` (this project has no `docs/adr/` of its own yet)
for the architectural decisions this implies, especially
`2026-07-08-employeehub-identity-extraction.md`, which is why this repo
exists at all: a third application (Performance Reviews) was about to
build its own `Company`/`Person`/tenancy model — the third occurrence of
an identical shape across LeaveFlow, Asset Management, and Performance
Reviews — and this portfolio's own discipline is to stop duplicating and
extract the shared pattern at that point, not before.

## Guiding constraints (read before adding scope)

- **Definition of done matters more than ambition.** A small, complete,
  tested, deployed system demonstrates more than a large, half-built one.
  Every slice below ends with something a stranger could clone, run, and use.
- **EmployeeHub is a real running service, not a composer package.** A
  shared PHP library would copy the `Company`/`Person` *code* into every
  app but leave three separate databases with three separate user
  records — not "one login, one org model, many apps," just duplication
  with extra indirection. Consuming apps register as OAuth2 clients and
  redirect here for login; that's what actually delivers SSO.
- **DDD folder structure from the first commit** — see the portfolio-wide
  `2026-07-06-ddd-structure-standard.md` ADR. This project never has a
  flat-layout phase to retrofit.
- **This is the canonical identity instance now, not another bespoke
  copy.** Slice 1 below is a close port of Asset Management's own Slice 1
  (itself the second occurrence of the pattern LeaveFlow originated) —
  same shape, same rigor, ported rather than redesigned, because the
  mechanism is proven twice already.
- **Migrating LeaveFlow or Asset Management onto EmployeeHub is NOT part
  of this roadmap.** That's a materially different, riskier operation
  (retrofitting a live app's authentication) than building a new app
  against a provider from day one — an explicit, deferred, future decision
  per the ADR, not a commitment made here.
- **Performance Reviews is EmployeeHub's first planned OAuth2 client** —
  scoped once Slice 2 (the authorization server) exists, not part of this
  roadmap either. It never gets its own local identity model; it
  authenticates entirely against EmployeeHub.

## Phase 1 — identity foundation + OAuth2 provider (complete, 3/3 slices merged)

Built as ordered, MR-sized slices — each one lands, gets a strict review
pass, and merges before the next starts, same discipline as LeaveFlow's,
Asset Management's, and the PersonalFinance project's workflow.

1. **Tenant + identity foundation**: `Company`, `Person` entities; the
   row-level tenant-scoping Doctrine filter (the same mechanism proven in
   both LeaveFlow and Asset Management, ported here without redesign);
   Symfony Security login folded onto `Person` with two roles
   (`ROLE_ADMIN`, `ROLE_EMPLOYEE` — no manager hierarchy, no teams; this is
   the identity foundation, not any particular app's business logic). No
   self-serve company signup — a console command seeds a company + its
   first admin; the admin adds people from the app.
2. **OAuth2 authorization server** — done. `league/oauth2-server-bundle`
   (confirmed current/maintained, PHP core-team-coordinated successor to
   the discontinued `trikoder/oauth2-bundle`). Authorization Code + PKCE
   flow (PKCE mandatory for every client, not just public ones), `/token`,
   and a `GET /api/userinfo` resource endpoint returning the token's
   Person's id/name/email/company/role — chosen over embedding custom JWT
   claims as the lower-risk, more standard extension point. No consent
   screen (first-party clients, console-registered only —
   `app:register-oauth-client`). Client secrets are hashed
   (`password_hash`/`password_verify`), overriding the bundle's own
   default plaintext comparison; the bundle's native
   `create-client`/`update-client` commands (which write plaintext
   secrets, incompatible with that hashing) are disabled via a compiler
   pass. RSA keypair for token signing lives at `config/jwt/*.pem`
   (gitignored); `OAUTH_PASSPHRASE`/`OAUTH_ENCRYPTION_KEY` are real
   secrets and must never be committed — set them in `.env.local` **and**
   `.env.test.local` (Symfony does not load `.env.local` when
   `APP_ENV=test`), then run
   `bin/console league:oauth2-server:generate-keypair --overwrite` so the
   key is encrypted with the same passphrase. `.env`'s own
   `OAUTH_PASSPHRASE=`/`OAUTH_ENCRYPTION_KEY=` lines stay blank, same
   treatment as `APP_SECRET`.
3. **Minimal admin UI** — done. Register/list/deactivate an OAuth2 client
   through a real form, gated on a new `ROLE_PLATFORM_ADMIN` tier rather
   than the existing tenant-scoped `ROLE_ADMIN` — client registration is a
   platform-wide capability (a registered client can pull `/api/userinfo`
   for any person from any company who authorizes it), so gating it on
   "admin of my own company" would have let any company's admin harvest
   identity data across every other tenant. `platformAdmin` lives as an
   independent boolean on `Person`, orthogonal to the tenant-scoped `role`
   column, grantable only via `app:grant-platform-admin <email>` —
   console-only, never exposed through `PersonType`'s form. Console
   (`app:register-oauth-client`) and UI registration share one
   `RegisterOAuthClientService`, so both paths are identical by
   construction.

Each slice needs: unit tests on the real domain logic (tenant isolation,
token/claim shape once Slice 2 lands), functional tests on its critical
path, PHPStan max + PHPCS clean, full test suite green.

Explicitly **out of Phase 1**: migrating LeaveFlow or Asset Management onto
EmployeeHub's identity (a separate, deferred decision — see the ADR);
scoping Performance Reviews' own domain (review cycles, ratings) beyond
"it will be EmployeeHub's first client" — that's sketched, not designed,
until Slice 2 exists; any of the original EmployeeHub ADR's "cheap to
extract whenever" capabilities (document management, audit logs,
notifications, dashboard framework, configuration, public API/webhooks).

## Phase 2 — only after Phase 1 is genuinely done

Not designed yet — deliberately deferred per this portfolio's discipline of
"wait for a second/third real consumer before generalizing":

- Migrating LeaveFlow and/or Asset Management onto EmployeeHub's identity,
  once EmployeeHub's authorization server has a working real client
  (Performance Reviews) to prove it against.
- Performance Reviews' own domain (review cycles, self/manager reviews,
  ratings) — a separate app/repo, scoped in detail once it can actually
  get a token from EmployeeHub.
- Any of the original ADR's other "cheap to extract whenever" platform
  capabilities, unaffected by this decision and still deferred exactly as
  that ADR already said.

## Architecture notes

- Symfony 8.0, PHP 8.4+, PostgreSQL 16, Docker Compose (mirrors LeaveFlow
  and Asset Management's stack for consistency across this portfolio).
- `docker compose up -d` — app on `http://localhost:8083`, Mailpit on
  `http://localhost:8028`, Postgres on `localhost:5436`. Chosen
  deliberately to not collide with LeaveFlow's ports (8081/8026/5434) or
  Asset Management's (8082/8027/5435), so all three apps can run
  simultaneously.
