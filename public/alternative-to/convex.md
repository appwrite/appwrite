# Appwrite vs Convex: an open-source Convex alternative

> Compare Appwrite and Convex. Unlike Convex, Appwrite is fully open source, with first-party auth, realtime on every service, functions in 13+ runtimes, and built-in hosting.

- HTML: https://appwrite.io/alternative-to/convex
- Competitor: Convex (Reactive TypeScript backend)
- Facts verified: October 2026

## Platform

| Feature | Appwrite | Convex |
| --- | --- | --- |
| Open source | Yes | No |
| License | Open source (OSI approved) | FSL-1.1 (Source-available, not open source) |
| Self-hosting | Yes (Same APIs, SDKs, and Console as Cloud) | Partial (Community support only) |
| Frontend hosting | Yes | No |
| Email, SMS, and push messaging | Yes | No (Through third-party services) |
| MCP server for AI agents | Yes | Yes |

## Data and realtime

| Feature | Appwrite | Convex |
| --- | --- | --- |
| Database models | 5 (Tables, documents, vectors, PostgreSQL, MySQL) | 1 (Documents, optional schema) |
| Serverless or dedicated databases | Yes (Serverless TablesDB, or dedicated compute for any engine) | No (Serverless only) |
| Realtime coverage | Every service (Rows, files, executions, sessions, teams) | Query results (Reactive database queries) |
| Vector search | Yes (VectorsDB or pgvector) | Partial (From actions only, results are not reactive) |
| Managed PostgreSQL and MySQL | Yes | No |

## Auth and compute

| Feature | Appwrite | Convex |
| --- | --- | --- |
| First-party authentication | Yes (MFA, teams, 40+ OAuth providers) | Partial (Convex Auth is in beta, third-party providers recommended) |
| Server languages | 13+ runtimes (Node.js, Python, Go, Dart, PHP, and more) | TypeScript and JavaScript |
| Calling external APIs | From any function | From actions only |
| Scheduled jobs | Yes | Yes |

## Pricing

| Feature | Appwrite | Convex |
| --- | --- | --- |
| Paid plan | From $25/mo (Unlimited members) | $25 per developer per month |
| Realtime is billed as | Connections and messages (500 connections and 6M messages on Pro) | Function calls (Subscription updates count as calls) |

## When Convex might still fit

Convex has a thoughtful developer experience and a loyal community. It may suit you better if these ring true.

- Reactive UI drives your product and you prefer Convex's built-in reactivity over wiring Realtime yourself. (That path trades flexibility for speed: more abstraction, and less room to mix databases, auth, and hosting on your own terms.)
- You picked Convex before you evaluated Appwrite, and migrating this project is not worth the effort right now.
- Your team is mid-ship on Convex and would rather finish the current roadmap than replatform.

## Related reading

- [Appwrite vs Convex for AI apps and agent workflows](https://appwrite.io/blog/post/appwrite-vs-convex-ai-agents): Auth, data model, and function boundaries compared.
- [Appwrite Realtime](https://appwrite.io/products/realtime): Live events from every service over one socket.
- [Appwrite Functions](https://appwrite.io/products/functions): APIs, cron jobs, and event handlers in 13+ runtimes.
- [Appwrite Auth](https://appwrite.io/products/auth): Email, OAuth, SMS, MFA, teams, and sessions.
- [Function runtimes](https://appwrite.io/docs/products/functions/runtimes): Every supported language and version.

## FAQ

### Is Appwrite better than Convex?

For most teams, yes. Appwrite gives you realtime on every service, first-party Auth with MFA and teams, functions in 13+ runtimes, storage, messaging, and web hosting in one open-source platform. Convex focuses on reactive queries in TypeScript and leaves auth, hosting, and messaging to other services.

### Is Convex open source?

No. Convex is not open source. Its backend is source-available under the Functional Source License (FSL-1.1), which is not approved by the Open Source Initiative and restricts how the code can be used. Appwrite is fully open source, so you can read, run, and change all of it.

- [Appwrite on GitHub](https://github.com/appwrite/appwrite)

### What is the best open-source alternative to Convex?

Appwrite is the best open-source alternative to Convex. It is fully open source, self-hosts with a single Docker command, and includes realtime, databases, auth, functions, storage, messaging, and hosting in every install.

- [Self-hosting](https://appwrite.io/docs/advanced/self-hosting)

### Does Convex support more than one database model?

No. Convex stores all data as documents in its own serverless database. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute.

- [Appwrite Databases](https://appwrite.io/products/databases)

### Is Appwrite a good Convex alternative?

Yes, if you want reactivity without being limited to one language or bringing your own auth. Appwrite streams realtime events from every service, includes first-party Auth with MFA and teams, runs functions in 13+ runtimes, and hosts your frontend with Sites.

### Does Appwrite have reactive queries?

Yes. Subscribe to channels over a single WebSocket with queries filtered on the server, and receive updates for rows, files, function executions, sessions, teams, and presence. Every event is checked against permissions before it reaches the client.

- [Realtime](https://appwrite.io/products/realtime)

### Can I write backend logic in Python or Go?

Yes. Appwrite Functions support Node.js, Bun, Deno, Python, Go, Dart, PHP, Ruby, Rust, Java, Kotlin, Swift, .NET, C++, and more. Any function can call external APIs, run on a schedule, react to platform events, or serve HTTP.

- [Runtimes](https://appwrite.io/docs/products/functions/runtimes)

### How does pricing compare for a team?

Convex Professional is $25 per developer per month, so cost grows with headcount. Appwrite Pro starts at $25/mo for the whole organization with unlimited members.

- [Pricing](https://appwrite.io/pricing)

### Can I run Appwrite on my own servers?

Yes. Self-hosted Appwrite runs with Docker and uses the same APIs, SDKs, and Console as Appwrite Cloud, so you can move between them by changing the endpoint.

- [Self-hosting](https://appwrite.io/docs/advanced/self-hosting)

## Sources

- [Convex pricing](https://www.convex.dev/pricing)
- [Convex limits](https://docs.convex.dev/production/state/limits)
- [Convex self-hosting](https://docs.convex.dev/self-hosting)
- [Convex authentication](https://docs.convex.dev/auth/overview)
- [Convex hosting](https://docs.convex.dev/production/hosting)
- [Convex backend license](https://github.com/get-convex/convex-backend/blob/main/LICENSE.md)
- [Functional Source License](https://fsl.software)
