---
layout: post
title: "The backend checklist for vibe-coded apps before launch"
description: "The vibe coding backend checklist: auth, permissions, tables, files, secrets, logs, domains, backups, and AI-generated code review before launch."
date: 2026-04-25
cover: /images/blog/backend-checklist-vibe-coded-apps/cover.avif
timeToRead: 9
author: atharva
unlisted: true
category: best-practices
lastUpdated: 2026-05-20
faqs:
  - question: "What is vibe coding and why does it need a backend checklist?"
    answer: "Vibe coding is the workflow of describing a product to an AI builder like Lovable, Bolt, v0, Cursor, Claude Code, Replit, or Windsurf and letting it scaffold the UI, data layer, and integrations. The output is demo-ready, not launch-ready. A pre-launch backend review catches the predictable failure modes: open table permissions, secrets in client bundles, missing indexes, no logs, and preview URLs that were never replaced with a custom domain."
  - question: "What is the most common backend failure in AI-generated apps?"
    answer: "Permissions. Generated code often writes to a table from the client with whatever role the signed-in user has, without narrowing to the rows that user actually owns. The fix is to default-deny on every table and bucket, add row-level permissions for per-user data, route admin actions through server-side functions, and spot-check by signing in as user A and trying to read user B's rows. You should fail."
  - question: "What should I do about secrets that an AI builder may have committed?"
    answer: "Search the repo for hard-coded API keys, database URLs, and webhook signing keys, and move all of them to server-side environment variables. Rotate any key ever pasted into a cloud-hosted AI tool. Use separate keys for development, staging, and production, and scope backend keys to the minimum permissions the calling code needs. If .env was ever committed to git, treat every key in it as compromised."
  - question: "What auth checks should I run before launching a vibe-coded app?"
    answer: "Decide which identity methods you actually support and remove the rest, turn on email verification, enforce a minimum password length, configure session length and revocation, offer MFA for admin and billing surfaces, test password recovery on a real inbox (not the builder's preview mailer), register production OAuth callback URLs, rate limit signup and login endpoints, and remove any test accounts the AI tool created during prototyping."
  - question: "What is the right pre-launch order if I only have an hour?"
    answer: "Get secrets out of the client. Close down table, row, and file permissions to owner-only where applicable. Attach a custom domain with SSL. Test auth flows on a working email inbox. Enable execution logs on every production function. Confirm the backup cadence and run one restore in staging. Confirm the rollback path. Read every AI-generated function end to end before shipping it."
  - question: "How does this checklist map to Appwrite specifically?"
    answer: "It maps cleanly. Auth covers OAuth, sessions, MFA, and Teams. Databases provide tables, rows, relationships, indexes, and per-row permissions. Storage handles per-bucket size and MIME limits, encryption, and antivirus. Functions hold server-side logic with environment variables and execution logs. Sites provides custom domains, env vars, rollbacks, and deploy logs. The MCP servers let coding agents operate the project safely with scoped keys."
---

Vibe coding gets you from idea to working prototype faster than any previous workflow. You describe a product, an AI builder assembles the UI, calls an API, and renders something usable on the first preview. The shipping question comes later: what does the backend for a vibe-coded app need before it meets users?

This is the vibe coding backend checklist. It is a pre-launch review you can run before your app handles a login, a payment, or a file upload. It assumes your frontend works and focuses on the backend primitives that decide whether your launch holds up.

If you use Appwrite behind a vibe-coded app, the primitives here map to Auth, Databases with tables and rows, Storage, Functions, Sites, and Appwrite Network. The checklist applies regardless of which tool generated your code: Lovable, Bolt, v0, Cursor, Claude Code, Replit, or Windsurf. If you are still choosing between those stacks, [Best vibe coding tools in 2026: comparison and tradeoffs](/blog/post/comparing-vibe-coding-tools) walks through practical differences before you commit.

# Why vibe-coded apps need a production checklist

AI builders are optimized to produce something demo-ready. They generate UI, stub data layers, and reach for the default backend integration the tool ships with. Lovable leans on Lovable Cloud and Supabase. Bolt promotes Bolt Cloud Database with Supabase as another option. Replit assembles apps inside the Replit runtime. v0 outputs React components wired to whatever you ask it to call.

None of these tools run the production review for you. If you skip it, the cracks show up in predictable places: open table permissions, secrets in the client bundle, missing indexes on hot queries, no logs to debug the first incident, and a custom domain still pointing at a preview URL.

The vibe coded app production checklist that follows is structured around the primitives that matter at launch.

# Auth

Auth is where most vibe-coded apps leak first. The UI looks finished and the signup flow works on the demo account, so the assumption is that the rest will follow.

- Decide which identity methods you support (email and password, magic URL, email OTP, OAuth, phone OTP, anonymous) and remove every flow you do not.
- Turn on email verification if your app stores anything tied to identity.
- Require a minimum password length and block common passwords at signup.
- Configure session length and make sure you can revoke a session if a user asks.
- Offer multi-factor authentication for accounts that reach admin or billing functionality. Appwrite supports TOTP-based MFA on the account.
- Test the password recovery flow end to end on a working inbox, not the AI builder's preview mailer.
- If you use OAuth, register production callback URLs and remove any dev URLs from the provider config.
- Rate limit signup, login, and password reset endpoints so nobody can brute force them.
- Block sign-ups from free, aliased, or disposable email providers if your product depends on real business addresses. Appwrite supports this through [email policies](/docs/products/auth/email-policies).
- Remove any test accounts your AI tool created during prototyping.

# Permissions

Permissions are the single biggest failure mode in AI-generated backends. Generated code often writes to a table from the client with whatever role the signed-in user has, without narrowing to the row the user owns.

- Review every table and confirm read and write permissions are scoped to the right roles. Default deny, then add the minimum.
- Check row-level permissions for any table that stores per-user data (profiles, orders, messages, uploads).
- Confirm that file buckets do not allow public uploads unless the product requires them.
- For collaboration features, use Appwrite Teams or roles rather than broadening table access.
- If an AI builder inserted a shared admin key into the client code, remove it. Admin calls belong in a server-side Function.
- Run a spot check: sign in as user A, try to read and mutate user B's rows. You should fail.

# Tables, rows, and columns

Schema quality decides how painful the next six months will be. Vibe-coded apps tend to over-index on speed.

- Review every table your app uses and delete the ones your code no longer touches.
- Confirm column types match what the app writes. Use integer, float, boolean, datetime, enum, email, URL, IP, or relationship types where they apply, not a freeform text column for everything.
- Add required flags to columns the app needs.
- Add defaults for columns that should never be null.
- Create indexes on the columns you filter, sort, or paginate on. One missing index can turn a snappy list view into a timeout once traffic hits.
- Enforce uniqueness where the product demands it (handle, slug, invoice number).
- Review relationships between tables and confirm the on-delete behavior you want, not the default the generator chose.
- If the AI tool introduced soft-delete or status columns, document what each value means so future you and future agents can reason about them.

# Files

File handling is the second most common source of production surprises. Uploads look fine until a user sends a 50 MB image from a phone, or a script pushes an executable.

- Limit allowed file extensions per bucket to the types your product needs.
- Set a sensible maximum file size per bucket. The default is usually too permissive.
- Turn on antivirus scanning on buckets that accept user uploads. Appwrite buckets expose this as a per-bucket flag.
- Enforce encryption for buckets holding sensitive documents. Appwrite buckets expose this as a per-bucket flag.
- Decide who can preview, download, and delete. File read permissions are separate from table read permissions.
- If your app shows user avatars or covers, confirm the preview transformations you rely on work with the formats you accept.
- Audit any signed URLs your code issues and keep their lifetime as short as your UX allows.

# Secrets

AI code generation is risky with secrets because the model has no strong prior against pasting a key into a component file.

- Search your repo for hard-coded API keys, project secrets, database URLs, and webhook signing keys. Move all of them to server-side environment variables.
- Confirm that provider keys for your LLM, payments, email, and SMS vendors live on a server-side Function, not in the browser bundle.
- Rotate any key that was ever pasted into a chat with a cloud-hosted AI tool.
- Use separate keys for development, staging, and production.
- Scope your backend API keys to the minimum set of permissions the calling code needs.
- If your AI builder committed `.env` to git at any point, assume every key in it is compromised.

# Server-side logic

Anything that touches money, third-party APIs, or sensitive data belongs on the server. Vibe-coded apps often inline this logic into the client because the generator did not push back.

- Move payment initiation, LLM calls, webhook handling, and admin actions into server-side Functions.
- Validate every input a Function receives. Never trust data because it came from your own UI.
- Set execution timeouts that match the work the Function does. A webhook handler does not need ten minutes.
- Configure the runtime version explicitly. Do not rely on the default drifting under you.
- Review every event trigger and confirm you want the Function to fire on it.
- Handle errors and return useful status codes. Silent failures in generated code are common.

# Logs and observability

You cannot debug what you cannot see, and the first time you need logs is always worse than any later one.

- Enable execution logs for every Function that runs in production.
- Decide on a log retention window that matches your compliance needs.
- Add at least one log line per meaningful branch: success, validation error, downstream error.
- Confirm auth audit events are accessible so you can investigate suspicious sign-ins.
- If you run on Appwrite Sites, check that deployment logs and request logs are reachable from your console.
- Set up a basic alert for error spikes. A weekly email is better than no alert at all.

# Custom domains and hosting

Vibe-coded apps often launch on the generator's preview domain. That is fine for sharing, not for production.

- Register the production domain you want users to remember.
- Attach the custom domain to your site and confirm SSL is issued.
- Redirect the preview domain to the custom domain so old links keep working.
- Configure environment variables per environment (production, staging, preview). Do not reuse production keys in preview.
- Confirm rollbacks are one click away. The first production incident is not the time to learn your rollback story. Appwrite Sites lets you activate a previous deployment as the current one.
- If you use Appwrite Sites, verify your site is reaching users through Appwrite Network so latency is acceptable globally.

# Backups and data recovery

Backups are where vibe-coded apps feel the biggest gap between prototype and product. The moment your UI lets a user delete something, you need a story for getting it back.

- Understand your backend's backup cadence and retention period. Write it down where your team can see it.
- Test restoring from a backup into a staging project at least once before launch.
- For tables holding irreplaceable data (orders, messages, contracts), add a soft-delete pattern so a misclick does not require a restore.
- Export a snapshot of critical tables before any schema migration generated by an AI tool.
- Keep a runbook for the three most likely data incidents: accidental mass delete, bad migration, compromised admin key.

# AI-generated code review

Code an AI builder generates is a first draft that needs the same review you would give a teammate.

- Read every server-side Function the generator produced. If you would not merge it from a teammate, do not ship it.
- Look for inputs that go straight from request body to database without validation.
- Look for authentication checks that are missing, commented out, or stubbed with a TODO.
- Look for SQL-like queries built with string concatenation where your backend expects structured query objects.
- Look for secrets that ended up in client code, config files, or example payloads.
- Look for unused endpoints and delete them. Dead code the agent generated is an attack surface.
- If you used a docs MCP server during generation, re-run the agent with the updated checklist above and ask it to flag anything it missed.

# The pre-launch review, in order

If you only have one hour before launch, run the list in this order:

1. Secrets out of the client.
2. Table, row, and file permissions closed down to owner-only where applicable.
3. Custom domain attached with SSL.
4. Auth flows tested on a working email inbox.
5. Logs enabled on every production Function.
6. Backup cadence confirmed and one restore tested in staging.
7. Rollback path confirmed.
8. AI-generated Functions read end to end.

Vibe coding is the fastest way from prompt to product, and the backend is where the leverage to keep that speed lives. Appwrite covers the primitives this checklist cares about: Auth with OAuth, sessions, MFA, and Teams; Databases with tables, rows, relationships, and indexes; Storage with per-bucket limits, encryption, and antivirus; Functions for server-side logic; Sites with custom domains, env vars, rollbacks, and logs; and MCP so coding agents can operate your project safely.

Run this checklist before your next launch. The one after is the one you can sleep through.
