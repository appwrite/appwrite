---
layout: post
title: Common Appwrite mistakes (and how to avoid them)
description: Common mistakes developers make when building with Appwrite and how to fix them before they cause security, performance, or scaling issues.
date: 2026-03-16
lastUpdated: 2026-06-29
cover: /images/blog/common-appwrite-mistakes-and-how-to-avoid-them/cover.avif
timeToRead: 5
author: aishwari
category: product
featured: false
faqs:
  - question: "What is the most common mistake developers make with Appwrite permissions?"
    answer: "Treating permissions as something to clean up after the app is built. Appwrite's permission model is explicit by design, so nothing is exposed unless you say so. Design the permission model before connecting the frontend, use role-based permissions, and always test with at least two separate accounts before launch."
  - question: "Why should I never put Appwrite API keys in the frontend?"
    answer: "Server API keys grant elevated privileges and bypass resource permissions. If they appear in client-side code, anyone who opens DevTools has full access to your backend. If something only works with a server key, move that logic into an [Appwrite Function](/docs/products/functions) and call the function from the client instead."
  - question: "When should I add indexes in Appwrite Databases?"
    answer: "Before launch, not after queries slow down. Add an index for every field you filter or sort by, and use compound indexes when a query combines multiple conditions, for example filtering by status and ordering by `$createdAt`. Treat indexes as part of your schema in [Appwrite Databases](/docs/products/databases), not as an optimization to defer."
  - question: "Should business logic live in the frontend or in Appwrite Functions?"
    answer: "Anything sensitive, like payments, role assignments, or external API calls with secrets, belongs in [Appwrite Functions](/docs/products/functions). The frontend should only handle UI and lightly validate input. A determined user can change anything that runs in their browser, so trust the server, not the client."
  - question: "What happens if I do not handle 409 conflict errors on Appwrite writes?"
    answer: "Users will see generic error pages instead of useful messages. The most common cause of a 409 is a unique index rejecting a duplicate write, for example two signups with the same email. Catch the error in the frontend and show a clear message, like \"already on the list,\" rather than letting the failure bubble up unhandled."
  - question: "How can I avoid running into Appwrite rate limits?"
    answer: "Batch requests where possible, paginate large reads with the Query API, and cache stable data on the client. For functions, make sure you are not invoking them in tight loops without backoff. Most rate limit issues come from accidental N+1 patterns, not from the platform being restrictive."
---

Appwrite is built to help you move fast. Auth, databases, storage, functions, realtime, all in one place, no glue code required.

But speed has a trade-off. Small misconfigurations that are invisible during development have a way of surfacing the moment you have real users and real traffic.

Here are the ten most common mistakes developers make when building with Appwrite and how to fix them before they become your problem.

# 1. Treating permissions as an afterthought

It usually goes like this: you build the app, test it with your own account, everything works, and you ship. Then a second user signs up,  and either they can't see their own data, or they can see someone else's.

Appwrite's permission system is explicit by design. Nothing is exposed unless you say so. That's a feature. But it means you have to think about permissions before you write your first query, not after.

- Design your permission model before you connect the frontend.
- Use role-based permissions, users, teams, members,  based on what your app actually needs.
- Never grant global read access to data that isn't genuinely public.
- Test with at least two separate accounts before you launch.

A useful rule of thumb: permissions decide *who* can touch a resource. Application logic decides *what happens* when they do. Keep those two things separate.

Read more about how Appwrite's permission model works in the [permissions docs](https://appwrite.io/docs/advanced/platform/permissions).

# 2. Putting API keys in the frontend

It starts as a debugging shortcut. Permissions are blocking something, the API key makes it work, and you tell yourself you'll fix it later.

Later never comes. And now anyone who opens DevTools has full access to your backend.

A server API key in client-side code is not a configuration issue, it's a critical security vulnerability.

- Never use server keys in frontend applications. Not even temporarily.
- If an operation needs elevated privileges, it belongs in an Appwrite Function, not the browser.
- Use client-side SDKs on the frontend. Use server-side SDKs only in secure environments.

If something only works with a server key, that's the system telling you it shouldn't be running on the client. Learn more about [API keys and scopes](https://appwrite.io/docs/advanced/platform/api-keys).

# 3. Skipping indexes until queries slow down

Small datasets are forgiving. Everything feels fast when you have a few hundred records. Then your data grows, and suddenly filtering takes seconds, sorting breaks, and pagination times out.

This is almost never a platform problem. It's an indexing problem.

- Add indexes for every field you filter or sort by.
- Use compound indexes when your queries combine multiple conditions, for example, filtering by status and ordering by $createdAt.
- Think about your query patterns before you launch, not after things slow down.

Indexes are part of your schema. Treat them that way. See how to [create and manage indexes](https://appwrite.io/docs/products/databases/tables#indexes) in Appwrite.

# 4. Running business logic in the frontend

Modern frontend frameworks make it easy to validate data, transform responses, and chain database calls directly from the client. And it works, until you have multiple platforms, multiple app versions, and no single source of truth for how your data behaves.

Logic scattered across clients means inconsistent behavior. It means bugs that only happen on one platform. It means duplicated code that drifts over time.

- Move validation and business logic into Appwrite Functions.
- Let the frontend render state. Let the server define rules.
- One place to update means one place to test and one consistent result everywhere.

Get started with [Appwrite Functions](https://appwrite.io/docs/products/functions) to move your server-side logic where it belongs.

# 5. Overusing real-time subscriptions

Real-time is one of the most powerful things Appwrite offers. It's also one of the easiest things to misuse.

Subscribing to an entire table when you only need one row. Forgetting to unsubscribe when a component unmounts. Triggering re-renders on events that have nothing to do with the current view.

Real-time should be intentional.

- Use realtime queries to subscribe only to the data you actually need.
- Subscribe to the narrowest scope that covers what you need.
- Clean up subscriptions when components are destroyed.
- Don't subscribe to tables, subscribe to rows, or specific event types.

When it's configured well, real-time feels seamless. When it's not, it creates noise. Read the [Appwrite Realtime docs](https://appwrite.io/docs/apis/realtime) to get the most out of it.

# 6. Using one project for everything

Development and production running on the same Appwrite project is an incident waiting to happen. All it takes is one test that runs against the wrong environment.

- Create separate projects for development, staging, and production.
- Use environment variables for endpoints and project IDs, never hardcode them.
- Keep destructive testing, schema experiments, and anything potentially breaking out of production entirely.

Separate projects help isolate environments and reduce the risk of production incidents. A production data incident costs far more than maintaining proper environment separation. 

See how projects work in the [Appwrite Console](https://cloud.appwrite.io/).

# 7. Rushing the schema

Appwrite makes it fast to spin up tables and add rows. That speed can work against you if you start building before you've thought through your data model. Refactoring a schema with real data in it is a different problem than designing it well from the start.

- Sketch your schema before you build it. Even a rough diagram helps.
- Use consistent naming conventions across all tables.
- Favor clean relational structures over deeply nested data.
- Think about how you'll query and report on data before you commit to a shape.

Databases reward structure. The time you spend planning pays back every time you run a query. Explore the [Appwrite Databases docs](https://appwrite.io/docs/products/databases) to understand tables, rows and relationships.

# 8. Using Functions for long-running tasks

Appwrite Functions are great for handling events, processing webhooks, running scheduled jobs, and executing short server-side logic. They are not the right tool for heavy, long-running work.

Functions have execution time limits. If you're trying to run bulk data migrations, process large files, or handle extended background jobs inside a Function, you will hit those limits.

- Scope Functions to short, discrete tasks.
- For long-running operations, break work into smaller pieces or trigger external pipelines.
- One Function, one job.

When Functions are used correctly, they're fast and reliable. When they're overloaded, they time out. Check the [Appwrite Functions docs](https://appwrite.io/docs/products/functions) for execution limits and best practices.

# 9. Building custom role logic instead of using Teams

When building multi-tenant apps, organizations, workspaces, groups, developers often reach for a custom role field in the database to manage access. This leads to complex, fragile permission logic that's hard to maintain.

Appwrite already has the right primitive for this: Teams.

- Use Teams to represent organizations or groups in your app.
- Assign team-based permissions to tables and rows.
- Manage roles inside a team rather than building your own role system from scratch.

Teams plug directly into Appwrite's permission model. There's no custom logic to maintain, it just works. Read more about [Appwrite Teams](https://appwrite.io/docs/products/auth/teams) and how to use them.

# 10. Shipping with default settings

Appwrite's defaults are optimized for getting started quickly. 

As your app scales, defaults that were invisible in development start to matter, rate limits, permission gaps, unreviewed security settings.

- Audit every permission rule before launch.
- Confirm your indexing covers your actual query patterns.
- Review rate limits and security configurations intentionally.
- Stress test your most critical user flows before real traffic hits them.

Defaults are a foundation. Production still requires a deliberate review on top of them. If you're self-hosting Appwrite, the production checklist is a good place to start. The [Appwrite production checklist](https://appwrite.io/docs/advanced/self-hosting/production) is a good place to start.

# Pre-launch checklist

Before you ship, check these off:

- Permissions audited and tested with multiple user roles
- Indexes in place for all filtered and sorted fields
- No server keys in any client-side code
- Business logic and validation running in Functions
- Separate projects for development, staging, and production
- Functions scoped to short, discrete tasks
- Teams used for any multi-tenant access requirements
- Rate limits and security settings reviewed
- Schema reviewed for consistency and query patterns
- Critical flows stress tested

# The pattern underneath all of this

Most of these mistakes aren't Appwrite-specific. They're general backend principles that apply to any system you build:

- Access control has to be deliberate.
- Logic belongs on the server.
- Schema design happens before implementation, not after.
- Indexes are part of your data model.
- Environments need to be isolated.

What Appwrite does is make these systems explicit. Nothing is hidden or abstracted away. That transparency rewards developers who engage with it intentionally and surfaces mistakes quickly for those who don't.

# Start building

If you're already using Appwrite, run through that checklist before your next launch. If you're just getting started, these are the right things to keep in mind from day one.

# Resources

- [Appwrite docs](https://appwrite.io/docs)
- [Appwrite Discord](https://appwrite.io/discord)
- [Appwrite Cloud](https://cloud.appwrite.io/)
