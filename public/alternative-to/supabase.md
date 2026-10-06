# Appwrite vs Supabase: an open-source Supabase alternative

> Compare Appwrite and Supabase. Both are open source. Appwrite adds web hosting, messaging, simple permissions, and functions in 13+ runtimes on one platform.

- HTML: https://appwrite.io/alternative-to/supabase
- Competitor: Supabase (Postgres development platform)
- Facts verified: October 2026

## Platform

| Feature | Appwrite | Supabase |
| --- | --- | --- |
| Open source | Yes | Yes |
| Many projects in one self-hosted install | Yes | No (One project per instance) |
| Self-hosting | One Docker install (Same APIs, SDKs, and Console as Cloud) | Docker Compose (Community supported, one project per instance) |
| Web hosting (static and SSR) | Yes (Sites, with Git deploys and previews) | No (Pair it with a separate frontend host) |
| Email, SMS, and push messaging | Yes (12 providers, topics, and scheduling) | No |
| MCP server for AI agents | Yes | Yes |

## Data

| Feature | Appwrite | Supabase |
| --- | --- | --- |
| Database models | 5 (Tables, documents, vectors, PostgreSQL, MySQL) | 1 (PostgreSQL) |
| Serverless or dedicated databases | Yes (Serverless TablesDB, or dedicated compute for any engine) | No (One dedicated instance per project) |
| Managed PostgreSQL | Yes (PostgreSQL 18 on dedicated compute) | Yes |
| Databases with SDKs | TablesDB, DocumentsDB, VectorsDB | Postgres with auto-generated APIs |
| Access control | Table and row permissions (Readable role strings, no SQL) | Row Level Security (Policies written in SQL) |
| Point-in-time recovery | +20% of the compute tier (Restore window of 1 to 35 days) | $100/mo per 7 days (Requires Small compute or larger) |
| Dedicated compute | From $10/mo | From $10/mo |

## Compute and realtime

| Feature | Appwrite | Supabase |
| --- | --- | --- |
| Function runtimes | 13+ (Node.js, Python, Go, Dart, PHP, and more) | TypeScript (Deno-compatible runtime) |
| Cron and event triggers | Yes | Yes |
| Realtime coverage | Every service (Rows, files, executions, sessions, teams) | Database and channels (Postgres changes, broadcast, presence) |
| Teams for multi-tenancy | Yes (Memberships and roles built in) | Partial (Model it with tables and RLS) |

## Pricing

| Feature | Appwrite | Supabase |
| --- | --- | --- |
| Free plan | 75K MAU, 2 projects | 50K MAU, 2 projects |
| Pro plan | From $25/mo | From $25/mo |
| Monthly active users on Pro | 200K, then $3 per 1,000 | 100K, then $3.25 per 1,000 |
| Bandwidth on Pro | 2TB | 250GB |
| Spend control | Budget cap | Spend cap |

## When Supabase might still fit

Supabase is a solid Postgres platform with a great team behind it. It may suit you if these describe your project.

- Your team relies mostly on Postgres and does not need much else from the platform.
- You rely on PostgREST for an auto-generated REST API over Postgres.
- You offer SAML single sign-on to your own end users. (SAML SSO for your end users is coming soon to Appwrite.)
- Your AI app builder has a one-click Supabase integration your workflow depends on.

## Related reading

- [Appwrite vs Supabase for AI app builders](https://appwrite.io/blog/post/appwrite-vs-supabase-ai-apps): Permissions an agent can read, runtimes beyond Deno, and hosting in one place.
- [Appwrite vs Supabase: a comparison of Backend-as-a-Service platforms](https://appwrite.io/blog/post/appwrite-compared-to-supabase): A product-by-product walkthrough of both platforms.
- [Migrate from Supabase](https://appwrite.io/docs/advanced/migrations/supabase): Import users, databases, and files from a Supabase project.
- [Managed PostgreSQL](https://appwrite.io/products/postgres): Raw Postgres with pooling, replicas, PITR, and branches.
- [Appwrite Sites](https://appwrite.io/products/sites): Deploy static and SSR apps next to your backend.
- [Appwrite Messaging](https://appwrite.io/products/messaging): Email, SMS, and push from one API.

## FAQ

### Is Appwrite better than Supabase?

For teams that want their whole stack in one place, yes. Appwrite includes everything Supabase covers, auth, databases, managed PostgreSQL, storage, functions, and realtime, plus web hosting, messaging, domains, and a project firewall. Permissions are readable role strings instead of SQL policies, and functions run in 13+ languages instead of TypeScript on Deno only.

### What is the best open-source alternative to Supabase?

Appwrite is the best open-source alternative to Supabase. It self-hosts with a single Docker command, runs the same APIs, SDKs, and Console as Appwrite Cloud, and includes more products out of the box, from web hosting to messaging. Independent benchmarks show that self-hosted Appwrite has significantly superior performance over Supabase and similar platforms.

- [Self-hosting](https://appwrite.io/docs/advanced/self-hosting)
- [Cloud vs self-hosted performance](https://appwrite.io/blog/post/appwrite-compared-to-supabase)

### Does Supabase support more than one database model?

No. Supabase is built on PostgreSQL, with one dedicated instance per project. Appwrite gives you five database models in one project: TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute.

- [Appwrite Databases](https://appwrite.io/products/databases)

### Is Appwrite a good Supabase alternative?

Yes, if you want more of your stack in one place. Both platforms are open source and start at $25/mo on Pro. Appwrite adds web hosting with Sites, email, SMS, and push with Messaging, project firewall rules, and functions in 13+ runtimes, so your frontend and backend deploy from one Console.

### Does Appwrite support PostgreSQL?

Yes. Appwrite runs managed PostgreSQL 18 (or 17) on dedicated compute with connection pooling, up to five HA replicas, point-in-time recovery, and up to 50 extensions including pgvector and PostGIS. Connect with psql or any driver. If you prefer SDKs with built-in permissions, use TablesDB, DocumentsDB, or VectorsDB.

- [Managed PostgreSQL](https://appwrite.io/products/postgres)
- [PostgreSQL docs](https://appwrite.io/docs/products/databases/postgresql)

### How do Appwrite permissions compare to Row Level Security?

Supabase protects rows with RLS policies written in SQL. Appwrite attaches permissions to tables and rows as readable role strings, such as read for one user or update for a team, and enforces them across the REST API, Realtime, and every SDK. They are easier to audit, especially when an AI agent writes the code. With Appwrite managed PostgreSQL, you can use Row Level Security in SQL the same way Supabase does when that is the model you want.

- [Permissions](https://appwrite.io/docs/products/databases/permissions)
- [Managed PostgreSQL](https://appwrite.io/products/postgres)

### Can I migrate from Supabase to Appwrite?

Yes. The Migrations tool in the Console imports users, databases, and files from a Supabase project, and usage during the migration does not count toward your Appwrite Cloud bill. Edge Functions are moved by hand to any Appwrite runtime.

- [Migrate from Supabase](https://appwrite.io/docs/advanced/migrations/supabase)

### Can I self-host Appwrite?

Yes. Appwrite runs anywhere Docker runs with a single install command, and the self-hosted version uses the same APIs, SDKs, and Console as Appwrite Cloud. Moving between them only changes the endpoint.

- [Self-hosting](https://appwrite.io/docs/advanced/self-hosting)

### Which one costs less?

Both Pro plans start at $25/mo, and dedicated database compute starts at $10/mo on both. Appwrite Pro includes twice the monthly active users (200K vs 100K), eight times the bandwidth (2TB vs 250GB), and unlimited Sites, so you can drop a separate frontend host.

- [Pricing](https://appwrite.io/pricing)

## Sources

- [Supabase pricing](https://supabase.com/pricing)
- [Supabase Edge Functions](https://supabase.com/docs/guides/functions)
- [Supabase self-hosting](https://supabase.com/docs/guides/self-hosting)
