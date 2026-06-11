---
layout: article
title: Permissions
description: Enhance data security and access control with Appwrite platform permissions. Learn how to set fine-grained permissions to protect user data and resources.
---

Appwrite's permission mechanism offers a simple, yet flexible way to manage which users, teams, or roles can access a specific resource in your project, such as rows and files.

Using permissions, you can decide that only **user A** and **user B** will have read and update access to a specific database row, while **user C** and **team X** will be the only ones with delete access.

As the name suggests, read permission allows a user to read a resource, create allows users to create new resources, update allows a user to make changes to a resource, and delete allows the user to remove the resource.

All permissions can be granted to individuals or groups of users, entire teams, or only to team members with a specific role. Permission can also be granted based on authentication status, such as to all users, only authenticated users, or only guest users.

A project user can only grant permissions to a resource that they have. For example, if a user is trying to share a row with a team that they are not a member of, they will encounter a 401 not authorized error. If your app needs users to grant access to teams they're not a member of, you can create Appwrite Functions with a [Server SDK](/docs/sdks#server) to achieve this functionality.

# Appwrite resource {% #appwrite-resource %}

An Appwrite resource can be a database, table, row, bucket, or file. Each resource has its own set of permissions to define who can interact with it.

Using the Appwrite permissions mechanism, you can grant resource access to users, teams, and members with different roles.

# Default values {% #default-values %}

If you create a resource using a Server SDK or the Appwrite Console without explicit permissions, no one can access it by default because the permissions will be empty. If you create a resource using a Client SDK without explicit permissions, the creator will be granted read, update, and delete permissions on that resource by default.

# Server integration {% #server-integration %}

Server integrations can be used for increased flexibility. When using a Server SDK in combination with the proper [API key scopes](/docs/advanced/platform/api-keys#scopes), you can have any type of access to any of your project resources regardless of their permissions.

Using the server integration flexibility, you can change resource permissions, share resources between different users and teams, or edit and delete them without any limitations.

# Permission types {% #permission-types %}

In Client and Server SDKs, you will find a **Permission** class with helper methods for each role described below:

| Type | Description |
| ---- | ----------- |
| `Permission.read()` | Access to read a resource. |
| `Permission.create()` | Access to create new resources. Does not apply to files or rows. Applying this type of access to files or rows results in an error. |
| `Permission.update()` | Access to change a resource, but not remove or create new resources. Does not apply to functions. |
| `Permission.delete()` | Access to remove a resource. Does not apply to functions. |
| `Permission.write()` | Alias to grant create, update, and delete access for tables and buckets and update and delete access for rows and files. |

# Permission roles {% #permission-roles %}

In Client and Server SDKs, you will find a **Role** class with helper methods for each role described below:

| Type | Description |
| ---- | ----------- |
| `Role.any()` | Grants access to anyone. |
| `Role.guests()` | Grants access to any guest user without a session. Authenticated users don't have access to this role. |
| `Role.users([STATUS])` | Grants access to any authenticated or anonymous user. You can optionally pass the **verified** or **unverified** string to target specific types of users. |
| `Role.user([USER_ID], [STATUS])` | Grants access to a specific user by user ID. You can optionally pass the **verified** or **unverified** string to target specific types of users. |
| `Role.team([TEAM_ID])` | Grants access to any member of the specific team. To gain access to this permission, the user must be the team creator (owner), or receive and accept an invitation to join this team. |
| `Role.team([TEAM_ID], [ROLE])` | Grants access to any member who possesses a specific role in a team. To gain access to this permission, the user must be a member of the specific team and have the given role assigned to them. Team roles can be assigned when inviting a user to become a team member. |
| `Role.member([MEMBERSHIP_ID])` | Grants access to a specific member of a team. When the member is removed from the team, they will no longer have access. |
| `Role.label([LABEL_ID])` | Grants access to all accounts with a specific label ID. Once the label is removed from the user, they will no longer have access. [Learn more about labels](/docs/products/auth/labels). |

# Examples {% #examples %}

The examples below will show you how you can use the different Appwrite permissions to manage access control to your project resources.

The following examples are using the [Appwrite Web SDK](https://github.com/appwrite/sdk-for-web) but can be applied similarly to any of the other [Appwrite SDKs](/docs/sdks).

## Example 1 - Basic usage {% #example-1-basic-usage %}

In the following example, we are creating a row that can be read by anyone, edited by writers or admins, and deleted by administrators or a user with the user ID `user:5c1f88b42259e`.

```client-web
import { Client, TablesDB, Permission, Role } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

let promise = tablesDB.createRow(
    '<DATABASE_ID>',
    '<TABLE_ID>',
    {'actorName': 'Chris Evans', 'height': 183},
    [
        Permission.read(Role.any()),                  // Anyone can view this row
        Permission.update(Role.team("writers")),      // Writers can update this row
        Permission.update(Role.team("admin")),        // Admins can update this row
        Permission.delete(Role.user("5c1f88b42259e")), // User 5c1f88b42259e can delete this row
        Permission.delete(Role.team("admin"))          // Admins can delete this row
    ]
);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

## Example 2 - Team roles  {% #example-2-team-roles %}

In the following example, we are creating a row that can be read by members of the team with ID `5c1f88b87435e` and can only be edited or deleted by members of the same team that possess the team role `owner`.

```client-web
import { Client, TablesDB, Permission, Role } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

let promise = tablesDB.createRow(
    '<DATABASE_ID>',
    '<TABLE_ID>',
    {'actorName': 'Chris Evans', 'height': 183},
    [
        Permission.read(Role.team("5c1f88b87435e")),             // Only users of team 5c1f88b87435e can read the row
        Permission.update(Role.team("5c1f88b87435e", "owner")), // Only users of team 5c1f88b87435e with the role owner can update the row
        Permission.delete(Role.team("5c1f88b87435e", "owner"))  // Only users of team 5c1f88b87435e with the role owner can delete the row
    ]
);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

## Example 3 - Private rows {% #example-3-private-rows %}

A common use case is to allow users to create rows that are only accessible to them. Here's how this can be achieved:

### Configure the table
First, configure your table to:
1. Enable **Row Security** in Table **Settings**
2. Grant only **CREATE** permission to **all users** at the table level

{% info title="Why this setup?" %}
- **Row Security** enables per-row permissions
- Table-level **CREATE** permission allows users to create rows
- Omitting **READ/UPDATE/DELETE** at table level prevents users from accessing all rows
{% /info %}

### Create a row for a user
When creating rows in your application, set row-level permissions to restrict access to only the creator:

{% multicode %}
```client-web
import { Client, TablesDB, Permission, Role } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

let promise = tablesDB.createRow(
    '<DATABASE_ID>',
    '<TABLE_ID>',
    { 'title': 'My Private Row' },
    [
        Permission.read(Role.user('<USER_ID>')),    // Only this user can read
        Permission.update(Role.user('<USER_ID>')),  // Only this user can update
        Permission.delete(Role.user('<USER_ID>'))   // Only this user can delete
    ]
);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void main() async {
    final client = Client()
        .setEndpoint('https://cloud.appwrite.io/v1')
        .setProject('<PROJECT_ID>');

    final tablesDB = TablesDB(client);

    try {
        final row = await tablesDB.createRow(
            databaseId: '<DATABASE_ID>',
            tableId: '<TABLE_ID>',
            data: { 'title': 'My Private Row' },
            permissions: [
                Permission.read(Role.user('<USER_ID>')),    // Only this user can read
                Permission.update(Role.user('<USER_ID>')),  // Only this user can update
                Permission.delete(Role.user('<USER_ID>'))   // Only this user can delete
            ]
        );
    } on AppwriteException catch(e) {
        print(e);
    }
}
```
```client-apple
import Appwrite

func main() async throws {
    let client = Client()
        .setEndpoint("https://cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>");

    let tablesDB = TablesDB(client);

    do {
        let row = try await tablesDB.createRow(
            databaseId: "<DATABASE_ID>",
            tableId: "<TABLE_ID>",
            data: ["title": "My Private Row"],
            permissions: [
                Permission.read(Role.user("<USER_ID>")),    // Only this user can read
                Permission.update(Role.user("<USER_ID>")),  // Only this user can update
                Permission.delete(Role.user("<USER_ID>"))   // Only this user can delete
            ]
        );
    } catch {
        print(error.localizedDescription);
    }
}
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.Permission
import io.appwrite.Role
import io.appwrite.services.TablesDB
import io.appwrite.exceptions.AppwriteException

suspend fun main() {
    val client = Client(applicationContext)
        .setEndpoint("https://cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>");

    val tablesDB = TablesDB(client);

    try {
        val row = tablesDB.createRow(
            databaseId = "<DATABASE_ID>",
            tableId = "<TABLE_ID>",
            data = mapOf("title" to "My Private Row"),
            permissions = listOf(
                Permission.read(Role.user("<USER_ID>")),    // Only this user can read
                Permission.update(Role.user("<USER_ID>")),  // Only this user can update
                Permission.delete(Role.user("<USER_ID>"))   // Only this user can delete
            )
        );
    } catch (e: AppwriteException) {
        Log.e("Appwrite", e.message);
    }
}
```
{% /multicode %}

{% info title="Understanding the flow" %}
1. Table-level **CREATE** permission allows users to create new rows
2. When a row is created, we set permissions for only the creator
3. These row-level permissions ensure only the creator can read, update, or delete their rows
4. Other users can create their own rows but cannot access rows they didn't create
{% /info %}