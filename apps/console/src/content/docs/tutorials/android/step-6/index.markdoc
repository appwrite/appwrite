---
layout: tutorial
title: Add database
description: Add databases and queries to store user data in you Android application.
step: 6
---

# Create table {% #create-table %}

In Appwrite, data is stored as a table of rows. Create a table in the [Appwrite Console](https://cloud.appwrite.io/) to store our ideas.

{% only_dark %}
![Create project screen](/images/docs/tutorials/dark/idea-tracker-table.avif)
{% /only_dark %}

{% only_light %}
![Create project screen](/images/docs/tutorials/idea-tracker-table.avif)
{% /only_light %}

Create a new table with the following columns:

| Column   | Type   | Required | Size     |
|-------------|--------|----------|----------|
| userId      | Varchar | Yes      | 200      |
| title       | Varchar | Yes      | 200      |
| description | Text    | No       | -        |

{% only_dark %}
![Table permissions screen](/images/docs/tutorials/dark/idea-tracker-permissions.avif)
{% /only_dark %}
{% only_light %}
![Table permissions screen](/images/docs/tutorials/idea-tracker-permissions.avif)
{% /only_light %}

Navigate to the **Settings** tab of your table, add the role **Any** and check the **Read** box.
Next, add a **Users** role and give them access to **Create**, **Update** and **Delete** by checking those boxes.

## Add and remove methods {% #add-add-remove-methods %}

Now that you have a table to hold ideas, we can read and write to it from our app.
Create a new file `services/IdeasService.kt` and add the following code to it. Replace the values for `ideaDatabaseId` and `ideaTableId` with the IDs of the database and table you created in the previous step.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>.services

import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.Query
import io.appwrite.models.Row
import io.appwrite.services.TablesDB

class IdeaService(client: Client) {
    companion object {
        private const val ideaDatabaseId = "<YOUR_IDEA_DATABASE_ID_HERE>"
        private const val ideaTableId = "<YOUR_IDEA_TABLE_ID_HERE>"
    }

    private val tablesDB = TablesDB(client)

    suspend fun fetch(): List<Row<Map<String, Any>>> {
        return tablesDB.listRows(
            ideaDatabaseId,
            ideaTableId,
            listOf(Query.orderDesc("\$createdAt"), Query.limit(10))
        ).rows
    }

    suspend fun add(
        userId: String,
        title: String,
        description: String
    ): Row<Map<String, Any>> {
        return tablesDB.createRow(
            ideaDatabaseId,
            ideaTableId,
            ID.unique(),
            mapOf(
                "userId" to userId,
                "title" to title,
                "description" to description
            )
        )
    }

    suspend fun remove(id: String) {
        tablesDB.deleteRow(
            ideaDatabaseId,
            ideaTableId,
            id
        )
    }
}
```

Update the `services/Appwrite.kt` file to add a new property for the `IdeaService` class.

Look for `// Add this line 👇` to find where the changes made here.

```kotlin
package io.appwrite.tutorialforandroid.services

import android.content.Context
import io.appwrite.Client

object Appwrite {
    private const val ENDPOINT = "https://<REGION>.cloud.appwrite.io/v1"
    private const val PROJECT_ID = "<PROJECT_ID>"

    private lateinit var client: Client

    // Add this line 👇
    internal lateinit var ideas: IdeaService
    internal lateinit var account: AccountService

    fun init(context: Context) {
        client = Client(context)
            .setEndpoint(ENDPOINT)
            .setProject(PROJECT_ID)

        // Add this line 👇
        ideas = IdeaService(client)
        account = AccountService(client)
    }
}
```
