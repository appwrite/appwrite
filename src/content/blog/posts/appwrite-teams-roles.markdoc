---
layout: post
title: "Appwrite Teams and Roles: managing multi-tenant access"
description: Learn how Appwrite Teams work, how to create and manage teams with roles, and how to use team-based permissions for multi-tenant SaaS apps.
date: 2026-03-24
cover: /images/blog/appwrite-teams-roles/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial
featured: false
unlisted: true
faqs:
  - question: "What is an Appwrite Team?"
    answer: "An Appwrite Team is a group of users with named roles. The user who creates a team automatically becomes a member with the owner role, and only owners can invite or remove other members. Roles are arbitrary strings you define, such as owner, admin, editor, or viewer, depending on your app's needs."
  - question: "How do I use teams for multi-tenant apps?"
    answer: "The cleanest pattern is one team per tenant. Each organization or workspace maps to a single Appwrite team, and all data belonging to that tenant uses team-based permissions like Permission.read(Role.team(teamId)). Appwrite then enforces tenant isolation automatically, without requiring tenant ID columns on every table."
  - question: "How do I invite a user to a team?"
    answer: "Call teams.createMembership with the team ID, the roles to assign, the user's email, and a redirect URL. Appwrite sends an invitation email and the invited user must accept before the membership is confirmed and they gain the permissions associated with their assigned roles."
  - question: "Can I update an existing member's role in place?"
    answer: "No. There is no in-place role update endpoint in Appwrite Teams. To change a member's roles you delete the existing membership and create a new one with the updated roles, so your app should treat role changes as a remove-and-reinvite flow."
  - question: "Can non-owner team members invite or remove other members?"
    answer: "Not through the client SDK. Appwrite enforces that only owner role members can invite, change roles, or remove others. If you need an admin role with similar capabilities, implement the action via an Appwrite Function that uses a server-side API key to call teams.createMembership on behalf of the admin."
  - question: "Can I hide member contact details from other team members?"
    answer: "Yes. In the Appwrite Console under your project's Auth settings, you can configure whether userName, userEmail, and MFA status are visible to non-owner members. This is useful for platforms where users should not be able to enumerate other members' contact information."
---

Access control in multi-tenant apps is one of the first things that gets messy. You need users to belong to organizations, those organizations to have different members with different roles, and data to be strictly isolated between tenants. Rolling this yourself means custom tables, middleware, and a lot of edge cases.

Appwrite Teams provides a first-class model for this. A team is a group of users with named roles, and you can use team membership to gate access to any Appwrite resource: databases, storage, functions. This post covers how teams work, how to create and manage them, and the patterns that work best for multi-tenant apps.

# How teams work

A team is a table of users with roles. When you create a team, the creating user automatically becomes a member with the `owner` role. Only members with the `owner` role can invite or remove other members.

Teams have two parts:

- **Membership**: who belongs to the team
- **Roles**: what role each member has within the team

Roles are arbitrary strings you define. Common examples: `owner`, `admin`, `editor`, `viewer`. You can define whatever roles make sense for your app's permission model.

When you assign permissions to a resource, you reference the team ID and optionally a specific role:

- `Role.team(TEAM_ID)` grants access to all team members
- `Role.team(TEAM_ID, 'editor')` grants access only to members with the `editor` role

This lets you build fine-grained access control without managing any custom tables.

# Creating a team

Use the Teams API from the Server SDK (with an API key) or the Client SDK (from a logged-in user's session):

```js
import { Client, Teams, ID } from 'appwrite';

const client = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>');

const teams = new Teams(client);

// Create a team, defining the available roles
const team = await teams.create({
  teamId: ID.unique(),
  name: 'Acme Corp',
  roles: ['owner', 'admin', 'editor', 'viewer']
});

console.log(team.$id); // save this as your tenant ID
```

The `roles` array defines which roles are valid for this team. A user can only be assigned a role that exists in this list.

# Inviting members

The owner invites users by email. Appwrite sends an invitation email, and the invited user must accept before they become an active member:

```js
const membership = await teams.createMembership({
  teamId: team.$id,
  roles: ['editor'],
  email: 'jane@example.com',
  url: 'https://yourapp.com/accept-invite'
});
```

Once accepted, `membership.confirm` becomes `true` and the user gains the permissions associated with their roles.

To list current members:

```js
const memberships = await teams.listMemberships({ teamId: team.$id });
memberships.memberships.forEach(m => {
  console.log(m.userName, m.roles, m.confirm);
});
```

To remove a member:

```js
await teams.deleteMembership({ teamId: team.$id, membershipId });
```

# Using teams for permissions

Once you have a team, you use it in [permission](/docs/advanced/platform/permissions) strings when creating or updating any Appwrite resource.

Here's how to create a database row that only team members can read, but only editors and admins can update:

```js
import { TablesDB, ID, Permission, Role } from 'appwrite';

const tablesDB = new TablesDB(client);

await tablesDB.createRow({
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  rowId: ID.unique(),
  data: { title: 'Project Plan', content: '...' },
  permissions: [
    Permission.read(Role.team(team.$id)),           // all members can read
    Permission.update(Role.team(team.$id, 'editor')),   // editors can update
    Permission.update(Role.team(team.$id, 'admin')),    // admins can update
    Permission.delete(Role.team(team.$id, 'admin')),    // only admins can delete
  ]
});
```

The same pattern applies to storage files. Create a bucket with `fileSecurity: true`, then assign team-based permissions when uploading files.

# Multi-tenancy patterns

The cleanest way to model multi-tenancy with Appwrite Teams is one team per tenant. Each organization, workspace, or account in your app maps to a single Appwrite team. All data belonging to that tenant gets team-based permissions.

The flow looks like this:

1. User signs up and creates an organization. Your backend creates an Appwrite team with `teams.create`, stores the team ID alongside the organization record.
2. The creating user is automatically the team `owner`.
3. When the owner invites colleagues, call `teams.createMembership` with appropriate roles.
4. All database rows and storage files created for that organization use `Permission.read(Role.team(teamId))` and role-specific write permissions.
5. Appwrite enforces isolation automatically. A user from team A cannot read rows that only grant access to team B.

This eliminates the need for tenant ID columns in every table or row-level security policies. The access control layer is in Appwrite, not in your SQL schema.

# Membership privacy

By default, team members can see the names and emails of other members when listing memberships. If your app has privacy requirements, you can configure which fields are hidden from non-owner members.

In the Appwrite Console under your project's Auth settings, you can restrict whether `userName`, `userEmail`, and `mfa` status are visible to non-owner team members. This is useful for platforms where users shouldn't be able to enumerate other members' contact details.

# Role escalation and management

Only `owner` role members can invite others, change roles, or remove members. This is enforced by Appwrite, not something you need to implement yourself.

To update an existing member's roles, delete the membership and re-create it with the new roles. There is no in-place role update endpoint, so your app should handle this as a remove-and-reinvite flow when a role change is needed.

If your app needs an `admin` role that can also invite members, that capability isn't built into the Teams API directly. In that case, use Appwrite Functions on the server side: when an admin triggers an invite, call `teams.createMembership` using a server-side API key (which has full access), rather than relying on the client SDK.

# Real-world use cases

Teams maps cleanly to several common app patterns:

- **SaaS workspaces**: Each company account is a team. Members have `owner`, `admin`, or `member` roles. Data is isolated per team with team-based permissions.

- **Educational platforms**: Each class or cohort is a team. Instructors get `instructor` role, students get `student` role. Course materials use `Permission.read(Role.team(teamId))` so only enrolled users can access them.

- **Collaborative tools**: Each project is a team. The creator is `owner`. Collaborators are `editor` or `viewer`. Rows in the project use role-based update and delete permissions.

- **Agency client portals**: Each client is a team. Agency staff and client contacts are both members with different roles, sharing access to relevant project files and rows.

# Build multi-tenant apps with Appwrite Teams

Appwrite Teams gives you a complete multi-tenancy primitive without any custom access control logic. Create a team per tenant, assign roles to members, and use team-based permissions on your data.

- [Appwrite Teams docs](/docs/products/auth/teams)
- [Multi-tenancy guide](/docs/products/auth/multi-tenancy)
- [Permissions reference](/docs/advanced/platform/permissions)
- [Start building on Appwrite Cloud](https://cloud.appwrite.io)
