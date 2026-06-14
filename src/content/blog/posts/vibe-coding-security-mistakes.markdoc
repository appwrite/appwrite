---
layout: post
title: "Vibe coding security mistakes developers ignore"
description: "The vibe coding security mistakes developers ignore: leaked keys, open permissions, prompt-history leaks, weak auth defaults, and the fixes that actually work."
date: 2026-05-15
cover: /images/blog/vibe-coding-security-mistakes/cover.avif
timeToRead: 10
author: aditya-oberai
category: security
unlisted: true
lastUpdated: 2026-05-20
faqs:
  - question: "What is the most ignored vibe coding security mistake?"
    answer: "Trusting that table and bucket permissions are correct because the demo worked. Generated code routinely defaults to read and write for every authenticated user, with no narrowing to the row or file that the user owns. The fix is to sign in as user A and try to read user B's data. You should fail."
  - question: "Why are API keys leaking in vibe-coded apps?"
    answer: "AI builders have no strong prior against pasting a real key into a component file, an example payload, or a config that ships with the bundle. The fix is to grep the repo for known key shapes before launch, rotate any key that ever sat in a cloud AI chat, and move every secret to a server-side environment variable on a Function or a hosted service like [Appwrite Sites](/docs/products/sites)."
  - question: "Are prompts and chat history a security risk?"
    answer: "Yes. Anything pasted into a cloud-hosted AI tool, including database URLs, OAuth secrets, sample payloads, and customer data, can be cached or logged on the vendor side. Treat every secret that ever passed through chat as compromised, rotate it, and avoid pasting customer data into prompts. Use scoped API keys so the agent only has the surface area it needs."
  - question: "What auth defaults should I never accept from an AI builder?"
    answer: "Email verification off, password minimum unset, no MFA, no rate limit on signup or login, OAuth callbacks pointing at preview domains, and test accounts still active. Each is a one-line fix in [Appwrite Auth](/docs/products/auth) and one of the most common vibe coding security mistakes."
  - question: "Where can I find a complete vibe coding security checklist?"
    answer: "Two posts cover the full surface: [20 security best practices for vibe coding](/blog/post/vibe-coding-security-best-practices) covers the practices a vibe coder should run every project. The [backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps) is the pre-launch review. This post focuses on the security mistakes developers make repeatedly even after reading those guides."
---

The first wave of vibe coding security advice focused on the obvious failures: SQL injection, missing validation, weak password hashing. Those failures still happen, and the 20 security best practices for vibe coding cover them. The mistakes worth a separate post are the ones that survive the first review. The ones developers know about, intend to fix, and still ship anyway.

This is a working list of the vibe coding security mistakes developers ignore, why each one keeps happening, and the version of the fix that actually closes it.

# 1. Trusting that permissions are correct because the demo worked

The single most common failure. Generated code creates a table, sets it readable by every signed-in user, and ships. The demo works because there is one user. The bug ships because there are now two.

The fix is mechanical and almost no one runs it. Sign in as user A, try to read and mutate user B's rows. You should fail every operation that should not work. If anything succeeds, narrow the row permissions before launch. [Appwrite Databases](/docs/products/databases) expose row-level permissions per row, and [Appwrite Storage](/docs/products/storage) expose them per file. Default-deny on every resource, then add the minimum.

# 2. Letting API keys live wherever the agent put them

The agent does not have a strong prior against pasting a real API key into a component file, an example payload, or a config that ends up in the bundle. Most developers know this, search for the obvious ones, and miss the rest.

The fix is to grep the whole repo for the key prefixes you use, not just `.env`. Database URLs, OAuth client secrets, webhook signing keys, third-party provider keys, internal admin keys. Anything that ever sat inside a generated file. Then rotate every key that passed through a cloud-hosted AI chat at any point. Use separate keys per environment, scope each one to the minimum permissions the calling code needs, and never ship a master key to the client.

The [Appwrite API MCP server](/docs/tooling/ai/mcp-servers/api) authenticates with an API key, so the scopes you grant that key decide what the agent can touch. Grant only what the agent's current task needs.

# 3. Treating chat history as ephemeral

Prompts and pasted payloads sit on the vendor side longer than developers expect. A database URL pasted once to fix a bug is now in chat history. A sample customer record pasted to ask the agent to generate a query is now in chat history. Developers should assume that prompts and pasted data may be retained or logged by cloud AI vendors.

The fix is to treat any secret that ever appeared in a cloud-hosted chat as compromised and rotate it. Avoid pasting customer data into prompts. If you need to share data with the agent for debugging, use anonymized samples and dispose of them after the session. For long-running workflows, an [Appwrite Functions](/docs/products/functions) deployment is the right place to debug with real data, not a chat window.

# 4. Skipping the server-side review for "small" features

Vibe coding makes new features cheap, and cheap features bypass review. The default failure mode is "this is too small to bother reviewing, ship it." A week later, the small feature is an unvalidated input endpoint that takes user input straight to the database, or an admin-only action that the agent forgot to gate.

The fix is to never ship server-side logic without reading it. Every [Appwrite Function](/docs/products/functions) that goes to production gets the same review as a PR from a teammate: input validation, permission checks, error handling, log lines, and a sanity pass on what the function will do if called from an unauthenticated client.

# 5. Leaving every auth method on by default

Generated auth setups tend to enable everything: email and password, OAuth, magic URL, phone OTP, anonymous. Every method that is enabled is a surface to defend. Most products only need one or two.

The fix is to decide which identity methods you actually support in [Appwrite Auth](/docs/products/auth) and disable the rest. Verify the methods you keep: enforce email verification, require a minimum password length, configure session length and revocation, rate limit signup and login, restrict who can sign up with [email policies](/docs/products/auth/email-policies) that block free, aliased, or disposable email providers, and remove any test accounts the agent created during prototyping.

# 6. Missing MFA on the surfaces that need it

A vibe-coded app rarely ships with MFA. The agent did not enable it, the developer did not ask, and it works fine in the demo. Then a customer with a real spend gets phished and the support burden is enormous.

The fix is to offer MFA on accounts that touch billing or admin functionality. [Appwrite Auth supports TOTP-based MFA](/docs/products/auth) on the account. The cost is one configuration toggle and a paragraph in the onboarding flow. The benefit is the difference between "we are sorry, we will reset your account" and "the attacker hit MFA and gave up."

# 7. Treating preview deploys like production

Preview deploys do not enforce the same controls as production. They often run with relaxed CORS, weaker rate limits, and admin keys that should not be on the internet. Generated code is happy to keep using the preview URL after launch, because the agent does not know the difference.

The fix is to attach a real domain before users sign up. [Appwrite Sites](/docs/products/sites/domains) supports custom domains with automatic SSL. Update OAuth callback URLs to the production domain. Redirect the preview domain to the production domain so old links survive. And revoke any admin key that was ever used during preview.

# 8. Skipping logs on functions because the demo worked

Logs are the cheapest investment in a production app and the most often skipped. Generated functions ship without explicit log lines, retention windows are left at the default, and the first time anyone looks at logs is during an incident.

The fix is to enable [execution logs](/docs/products/functions) on every production Function, set a retention window that matches your compliance needs, and add a log line per meaningful branch. Confirm auth audit events are reachable so you can investigate suspicious sign-ins later. A weekly email alert on error spikes is enough to catch the first incident before a customer reports it.

# 9. Trusting the agent on input validation

The agent generates input handlers that work for the happy path. It rarely validates length, type, content, or rate. A vibe-coded endpoint will gladly accept a 50 MB string in a field that expects a 32-character handle, and the failure shows up later as a database row that breaks the dashboard.

The fix is to validate every input on the server, regardless of what the client sends. Reject anything outside the expected shape. Cap lengths. Whitelist enums. Sanitize anything that ever ends up in a query, a file path, or a shell command. The [20 security best practices for vibe coding](/blog/post/vibe-coding-security-best-practices) covers this in depth.

# 10. Forgetting that the client is hostile

The hardest mental shift in vibe coding is to treat your own UI as an untrusted client. Generated frontends tend to assume that anything the UI sends is fine, because the UI was just generated and looks reasonable. The agent does not consider that a user can call your backend directly with curl.

The fix is to never trust data because it came from your own UI. Every server-side function validates inputs as if they came from an attacker. Every permission check happens on the server, even when the client also checks. Every admin action requires server-side verification of role, not just the absence of a hidden button in the UI.

# 11. Skipping rate limits on auth endpoints

Generated code ships login, signup, password reset, and OAuth endpoints without rate limits. A scripted attacker can brute-force a weak password or spam the password-reset endpoint with thousands of email addresses before anyone notices.

The fix is to rate-limit every auth endpoint, plus any endpoint that performs work proportional to user input. [Appwrite Auth](/docs/products/auth) supports session and login rate limits at the platform level. Anything generated for custom flows should be reviewed for the same controls.

# 12. Letting file uploads accept anything

Generated file upload code tends to default to "any size, any type, any user." The first user with a 500 MB file ruins the bucket. The first user who uploads an executable provides the attack vector for the next incident.

The fix is to limit allowed file extensions per [Appwrite Storage bucket](/docs/products/storage), set a sensible maximum file size, turn on antivirus scanning for buckets that accept user uploads, and enforce encryption on buckets that hold sensitive documents. Audit signed URLs and keep their lifetime as short as the UX allows.

# 13. Believing the AI generated audit log is real

Some agents generate audit log code that looks complete. The function logs the action, the user, and the timestamp to a console.log. The function does not write to a real append-only store. The function is the audit log only in a narrative sense.

The fix is to confirm that audit logs are actually durable, queryable, and append-only. If the agent's implementation logs to stdout and stops there, replace it with writes to a [Function](/docs/products/functions) with structured logging or to a dedicated audit table with insert-only permissions.

# 14. Skipping a real backup test

Backups exist as a configuration option in almost every backend. Backup restores are tested almost nowhere. The first time you need a restore is during an incident, and discovering at that moment that the restore does not work is the worst possible time.

The fix is to test a restore into a staging project at least once before launch. Confirm the backup cadence matches your data sensitivity. For irreplaceable data, add a soft-delete pattern so a misclick is recoverable without a restore.

# 15. Pretending compliance is a future problem

GDPR, CCPA, HIPAA, and SOC 2 do not check whether your app was generated. They check whether the data is handled correctly. Vibe coding does not change that. The agent will happily ship an app that fails any of these without ever flagging the issue.

The fix is to decide which compliance regimes apply before you ship anything that handles personal data, and to verify the controls each one requires. For HIPAA, that includes data residency and audit logs. For GDPR, data export and deletion endpoints, plus a privacy review. For SOC 2, scoped access and observability. [Appwrite is GDPR, CCPA, and HIPAA compliant](https://cloud.appwrite.io) on the Cloud and supports self-hosting where data residency is the deciding factor.

# 16. Trusting the agent's "this should be safe" comment

A small but real failure mode is the agent's own assurances. A generated comment like "this should be safe" or "no validation needed here, the UI already checks" is not a guarantee. It is a hint that the agent did not think the case through.

The fix is to delete every "this is safe" comment as a class. If the code is safe, prove it with a test. If you cannot prove it, write the missing check. Comments that explain why are useful. Comments that justify a missing check are a flag, not a feature.

# Where to go for the full security pass

The mistakes above are the ones that survive the obvious review. They are also the ones that show up after launch, when the cost is highest. Run them as a second pass on top of the foundational checks.

For the full surface, two posts cover what should be in every team's launch ritual:

- [20 security best practices for vibe coding](/blog/post/vibe-coding-security-best-practices): the foundational list every vibe coder should run every project.
- [The backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps): the pre-launch review in order.

Both pair with a backend designed to make secure defaults cheap. [Appwrite Cloud](https://cloud.appwrite.io) covers [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), and [Sites](/docs/products/sites) under one model, with per-row permissions, per-bucket controls, scoped API keys, and MCP support so an agent can run safely against your production project.

# Closing the security gaps in vibe-coded apps

The mistakes above are not exotic. They are predictable, repeat-offender failures that every vibe-coded launch has at least one of. The fix is not better tools. It is a habit of running the same review every time, treating the agent's first draft as a first draft, and choosing a backend where the secure default is the easiest default.

[Sign up for Appwrite Cloud](https://cloud.appwrite.io) and start the next launch on a backend built for this workflow.

- [20 security best practices for vibe coding](/blog/post/vibe-coding-security-best-practices)
- [Backend checklist for vibe-coded apps before launch](/blog/post/backend-checklist-vibe-coded-apps)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)
- [Appwrite AI tooling](/docs/tooling/ai)
