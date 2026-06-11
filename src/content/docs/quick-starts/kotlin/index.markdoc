---
layout: article
title: Start with Kotlin
description: Learn to get started with server integrations with Appwrite Kotlin SDK.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first Kotlin project powered by Appwrite.
{% section #step-1 step=1 title="Create project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console).

{% info title="Server SDK" %}
This tutorial is for the Kotlin Server SDK, meant for server and backend applications.
If you're trying to build a client-side app, like an Android app,
follow the [Start with Android guide](https://appwrite.io/docs/quick-starts/android).
{% /info %}

If this is your first time using Appwrite, create an account and create your first project.

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

Then, under **Integrate with your server**, add an **API Key** with the following scopes.

{% only_dark %}
![Server integrations](/images/docs/quick-starts/dark/integrate-server.avif)
{% /only_dark %}
{% only_light %}
![Server integrations](/images/docs/quick-starts/integrate-server.avif)
{% /only_light %}
| Category  {% width=120 %} | Required scopes       | Purpose |
|-----------|-----------------------|---------|
| Database  | `databases.write`     | Allows API key to create, update, and delete [databases](/docs/products/databases/databases). |
|           | `tables.write`   | Allows API key to create, update, and delete [tables](/docs/products/databases/tables). |
|           | `columns.write`    | Allows API key to create, update, and delete [columns](/docs/products/databases/tables#columns). |
|           | `rows.read`      | Allows API key to read [rows](/docs/products/databases/rows). |
|           | `rows.write`     | Allows API key to create, update, and delete [rows](/docs/products/databases/rows). |

Other scopes are optional.

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

{% /section %}
{% section #step-2 step=2 title="Create Kotlin project" %}
Create a Kotlin application by opening **IntelliJ IDEA** > **New Project** and create a **Kotlin** application.
This quick start will use **Gradle** as the build system, with the Kotlin DSL. You can follow with Maven or IntelliJ if you're more comfortable.

Follow the wizard and open your new project. 

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}
Open your `build.gradle.kts` file and implement the following dependency.

```groovy
dependencies {
    ... other dependencies
    implementation("io.appwrite:sdk-for-kotlin:9.0.0")
}
```

{% /section %}
{% section #step-4 step=4 title="Import Appwrite" %}

Find your project ID in the **Settings** page. Also, click on the **View API Keys** button to find the API key that was created earlier. 

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Open the file `Main.kt` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

```kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.services.TablesDB
import io.appwrite.models.Database
import io.appwrite.models.Table
import kotlinx.coroutines.coroutineScope

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");
```

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

```kotlin
val tablesDB = TablesDB(client)

var todoDatabase: Database? = null
var todoTable: Table? = null

suspend fun prepareDatabase() {
    todoDatabase = tablesDB.create(ID.unique(), "TodosDB")
    todoTable = tablesDB.createTable(todoDatabase?.id!!, ID.unique(), "Todos")

    tablesDB.createVarcharColumn(
        databaseId = todoDatabase?.id!!,
        tableId = todoTable?.id!!,
        key = "title",
        size = 255,
        required = true
    )

    tablesDB.createTextColumn(
        databaseId = todoDatabase?.id!!,
        tableId = todoTable?.id!!,
        key = "description",
        required = false,
        default = "This is a test description."
    )

    tablesDB.createBooleanColumn(
        databaseId = todoDatabase?.id!!,
        tableId = todoTable?.id!!,
        key = "isComplete",
        required = true
    )
}
```

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.
```kotlin
suspend fun seedDatabase() {
    val testTodo1 = mapOf(
        "title" to "Buy apples",
        "description" to "At least 2KGs",
        "isComplete" to true
    )

    val testTodo2 = mapOf(
        "title" to "Wash the apples",
        "isComplete" to true
    )

    val testTodo3 = mapOf(
        "title" to "Cut the apples",
        "description" to "Don't forget to pack them in a box",
        "isComplete" to false
    )

    tablesDB.createRow(
        databaseId = todoDatabase?.id!!,
        tableId = todoTable?.id!!,
        rowId = ID.unique(),
        data = testTodo1
    )

    tablesDB.createRow(
        databaseId = todoDatabase?.id!!,
        tableId = todoTable?.id!!,
        rowId = ID.unique(),
        data = testTodo2
    )

    tablesDB.createRow(
        databaseId = todoDatabase?.id!!,
        tableId = todoTable?.id!!,
        rowId = ID.unique(),
        data = testTodo3
    )
}
```

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data.

```kotlin
suspend fun getTodos() {
    val todos = tablesDB.listRows(todoDatabase?.id!!, todoTable?.id!!)
    for (todo in todos.rows) {
        println(
            """
            Title: ${todo.data["title"]}
            Description: ${todo.data["description"]}
            Is Todo Complete: ${todo.data["isComplete"]}
            """.trimIndent()
        )
    }
}

suspend fun main() = coroutineScope {
    prepareDatabase()
    seedDatabase()
    getTodos()
}
```

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with IntelliJ and view the response in your console.

{% /section %}