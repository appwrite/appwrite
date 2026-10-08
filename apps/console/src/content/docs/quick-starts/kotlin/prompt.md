## Create a Kotlin server-side application powered by Appwrite

Create a todo database, seed it with data, and retrieve the todos.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

_Note: This is for the Kotlin Server SDK, meant for server and backend applications. If the user wants to build a client-side Android app, direct them to the Android quickstart guide instead._

## Step 1: Create project in Appwrite Console

- Guide the user to the [Appwrite Console](https://cloud.appwrite.io/console)
- If this is their first time, help them create an account and their first project
- Under **Integrate with your server**, add an **API Key** with the following scopes:

| Category | Required scopes   | Purpose                                                |
| -------- | ----------------- | ------------------------------------------------------ |
| Database | `databases.write` | Allows API key to create, update, and delete databases |
|          | `tables.write`    | Allows API key to create, update, and delete tables    |
|          | `columns.write`   | Allows API key to create, update, and delete columns   |
|          | `rows.read`       | Allows API key to read rows                            |
|          | `rows.write`      | Allows API key to create, update, and delete rows      |

- Other scopes are optional

## Step 2: Create Kotlin project

- Open **IntelliJ IDEA** > **New Project** and create a Kotlin application
- Use **Gradle** as the build system with **Kotlin DSL** (Maven or IntelliJ build system also work)
- Follow the wizard and open the new project

## Step 3: Install Appwrite SDK

- Open `build.gradle.kts` and add the Appwrite dependency:

```kotlin
dependencies {
    // ... other dependencies
    implementation("io.appwrite:sdk-for-kotlin:9.0.0")
}
```

## Step 4: Import Appwrite and initialize the client

Ask the user for:

- **Cloud Region** (e.g. `fra`, `nyc`)
- **Project ID** (from Console > Settings)
- **API Key** (from Console > View API Keys)

_Never assume these values._

Open `Main.kt` and initialize the Appwrite Client:

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

- Replace placeholders with actual values if provided, otherwise ask user to provide them

## Step 5: Initialize database

- Create a function to configure a todo table:

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

## Step 6: Add rows (seed the database)

- Create a function to add mock data:

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

## Step 7: Retrieve rows

- Create a function to retrieve and display the todo data:

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

## Step 8: Run and test

- Run the project with **IntelliJ**
- View the response in the console
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to the client configuration

## Deliverables

- A running Kotlin application with Appwrite integration
- Files created/updated: `build.gradle.kts` (deps), `Main.kt` (client setup, database functions, main entry point)
- A **TodosDB** database with a **Todos** table containing `title`, `description`, and `isComplete` columns
- Three seeded todo rows displayed in the console output
