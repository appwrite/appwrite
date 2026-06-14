---
layout: post
title: "Understanding Appwrite permissions: a complete breakdown"
description: A complete breakdown of Appwrite's permission system, covering permission types, role types, common patterns, and pitfalls to avoid in your app.
date: 2026-03-23
cover: /images/blog/appwrite-permissions/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial, security
featured: false
unlisted: true
faqs:
  - question: "What permission types does Appwrite support?"
    answer: "Appwrite defines five permission types: read, create, update, delete, and write. Write is a convenience alias that combines create, update, and delete. Importantly, write does not include read, so a role with only write permissions can mutate resources but cannot list or fetch them."
  - question: "What role types can I use in Appwrite permissions?"
    answer: "Appwrite provides role types including any() for everyone, guests() for unauthenticated requests, users() for all authenticated users, user(USER_ID) for a specific user, team(TEAM_ID) and team(TEAM_ID, ROLE) for team-based access, member(MEMBERSHIP_ID), and label(LABEL_ID) for users tagged with a specific label."
  - question: "How do default permissions differ between Server SDK and Client SDK?"
    answer: "When a Server SDK with an API key creates a resource without explicit permissions, nobody (other than callers using the same API key) can access it. When a Client SDK creates a resource for a logged-in user without explicit permissions, Appwrite automatically grants read, update, and delete to that specific user."
  - question: "Does the Server SDK respect row-level permissions?"
    answer: "No. Server SDK requests made with a valid API key bypass row-level and file-level permissions entirely. The only constraint is the API key's scope, which controls which services and actions it can access. This is why API keys must never be exposed to clients."
  - question: "When should I use labels instead of teams?"
    answer: "Labels are useful when you need user-level flags without creating a dedicated team for each one. Examples include tagging users as beta testers or marking premium subscribers. You assign labels server-side, then reference them in permissions with Role.label(LABEL_ID), and removing the label revokes access immediately."
  - question: "What is the most common permissions pitfall in Appwrite?"
    answer: "Forgetting that write does not include read. If you grant Permission.write(Role.user(userId)) without also granting Permission.read(Role.user(userId)), the user can create and modify resources but cannot fetch them back. Always check both dimensions when configuring permissions."
---

Permissions are one of those things that feel obvious until something goes wrong. A row that should be private turns out to be readable by anyone. A file that should be editable by a team member throws a 401. An admin endpoint that works fine in development breaks in production because the API key scope was wrong.

[Appwrite's permission system](/docs/advanced/platform/permissions) is explicit and composable. Once you understand the building blocks, it becomes predictable. This post covers all of them.

# The two dimensions of permissions

Every permission in Appwrite has two dimensions: the **permission type** (what action is allowed) and the **role** (who is allowed to perform it). You combine them when setting permissions on any resource.

# Permission types

Appwrite defines five permission types:

- **read** grants the ability to fetch or list a resource. A user with read permission on a row can retrieve it; without read, the row is invisible to them.

- **create** grants the ability to create new resources within a container. On a table, create permission lets a user add new rows. On a bucket, it lets them upload files.

- **update** grants the ability to modify an existing resource. On a row, update permission lets a user change field values.

- **delete** grants the ability to remove a resource permanently.

- **write** is a convenience alias that combines create, update, and delete. Granting write permission is equivalent to granting all three mutation permissions at once. Use it when you want a role to have full control over a resource without read restrictions.

Note that **write does not include read**. A role with write permission but not read permission can create, update, and delete resources without being able to list or fetch them. This is rarely what you want, but it is a valid configuration for certain write-only patterns.

# Role types

Roles define who a permission applies to. Appwrite provides several role types that cover most access patterns.

- **`any()`** matches every request, authenticated or not. This is the role you use for public resources. A row with `Permission.read(Role.any())` is readable by anyone who can reach your Appwrite endpoint.

- **`guests()`** matches only unauthenticated requests. Use this when you want to allow access to a resource only before a user has logged in, such as a public landing page row that logged-in users should not see (rare, but valid).

- **`users()`** matches all authenticated users, regardless of which user or what account status they have. You can optionally filter by status: `Role.users("verified")` matches only users who have verified their email address.

- **`user([USER_ID])`** matches a single specific user by their ID. This is the most granular user-level permission. You can also filter by status: `Role.user("user123", "verified")`.

- **`team([TEAM_ID])`** matches any member of a specific team. All team members share this role regardless of what role they have within the team.

- **`team([TEAM_ID], [ROLE])`** matches only members of a team who have been assigned a specific role within that team. For example, `Role.team("teamABC", "admin")` only matches team members with the "admin" role. This is the basis for fine-grained team-based access control.

- **`member([MEMBERSHIP_ID])`** matches a specific team membership. This is more precise than matching by team and role, but also more fragile since membership IDs change if a user leaves and rejoins a team.

- **`label([LABEL_ID])`** matches any user who has been assigned a specific label. Labels are arbitrary strings you assign to users from your server-side code. They are a flexible way to grant permissions based on user columns without creating a new team for each column.

# Where permissions are set

Permissions can be set at multiple levels in Appwrite's data hierarchy.

- **Databases:** You can set permissions on a table (controlling who can create rows and who can list them) and on individual rows (controlling who can read, update, or delete that specific row).

- **Storage:** You can set permissions on a bucket (controlling who can upload files) and on individual files.

Table-level and bucket-level permissions act as gates. If a user does not have create permission on a table, they cannot create rows in it even if they somehow construct a valid request. Row-level and file-level permissions control access to specific resources after they exist.

# Default permission behavior

The defaults differ between Server SDK and Client SDK usage, and this is a common source of confusion.

- **Server SDK (API key):** When you create a resource using a Server SDK with an API key and do not set explicit permissions, nobody has access to that resource except via the same server-side API key. No client can read or modify it. This is intentional: server-created resources are locked down by default.

- **Client SDK (user session):** When a logged-in user creates a resource using a Client SDK and does not set explicit permissions, Appwrite automatically grants read, update, and delete to that specific user. The creator retains control over what they created.

**Server SDK bypasses permissions.** Any request made with a valid API key bypasses row-level and file-level permissions entirely. The API key scope (what services and actions it can access) is the only constraint. This means server-side code has full access to data, which is appropriate for backend operations but means you should never expose API keys to clients.

# Common patterns

## Private user data

To create a row that only the owner can access:

```js
import { TablesDB, Permission, Role } from "appwrite";

const tablesDB = new TablesDB(client);

await tablesDB.createRow({
    databaseId: "[DATABASE_ID]",
    tableId: "[TABLE_ID]",
    rowId: ID.unique(),
    data: { content: "private note" },
    permissions: [
        Permission.read(Role.user(userId)),
        Permission.update(Role.user(userId)),
        Permission.delete(Role.user(userId)),
    ]
});
```

Only the user with that ID can read, update, or delete this row.

## Team-shared resources

To create a row readable by all team members but only editable by team admins:

```js
[
    Permission.read(Role.team("teamABC")),
    Permission.update(Role.team("teamABC", "admin")),
    Permission.delete(Role.team("teamABC", "admin")),
]
```

Every team member can read. Only admins can modify or delete.

## Public read, restricted write

A common pattern for content-heavy apps: anyone can read, but only authenticated and verified users can create:

```js
// On the table
[
    Permission.read(Role.any()),
    Permission.create(Role.users("verified")),
]
```

Rows in this table are publicly readable. Only verified users can add new ones. Row-level update and delete can be further restricted to the creator or to an admin team.

## Label-based access

Labels work well when you need user-level flags without creating dedicated teams. For example, granting early access to beta features:

```js
// Assigned server-side when a user joins the beta
await users.updateLabels({ userId, labels: ["beta"] });

// Permission on a beta-only resource
[
    Permission.read(Role.label("beta")),
]
```

Any user with the "beta" label can read this resource. Removing the label from a user removes their access immediately.

# Pitfalls to avoid

- **Forgetting that write does not include read.** If you grant `Permission.write(Role.user(userId))` and forget `Permission.read(Role.user(userId))`, the user can create and modify resources but cannot fetch them. Always check both dimensions.

- **Using `any()` on mutable resources unintentionally.** `Role.any()` grants access to unauthenticated users. If you use it on a table with create permission, anonymous users can insert rows. This is sometimes intentional (public form submissions) but often is not.

- **Server SDK creating resources with no permissions.** If your backend creates rows that clients need to read, you must explicitly set permissions at creation time. The locked-down default means clients will see empty results or 401 errors until you add explicit permissions.

- **Over-relying on table-level permissions.** Table-level create permission controls who can add rows. But read, update, and delete on existing rows are controlled at the row level. Setting read on the table does not automatically make all rows in it readable.

- **Not using team roles for collaborative apps.** A common early mistake is giving all team members full write access via `Role.team("teamABC")`. When you eventually need to differentiate admins from regular members, you have to update permissions on every existing row. Model team roles from the start.

# Build with Appwrite permissions

Appwrite's permission system rewards explicit, intentional design. Decide who needs what access before you start creating resources, set it at creation time, and use teams and labels to express group-level access cleanly.

- [Appwrite Permissions docs](/docs/advanced/platform/permissions)
- [Appwrite Teams docs](/docs/products/auth/teams)
- [Appwrite Databases docs](/docs/products/databases)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)
