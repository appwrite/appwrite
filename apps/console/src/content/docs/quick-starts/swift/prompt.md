## Create a Swift Server-Side CLI Application with Appwrite

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

_Note: This is for the **Swift Server SDK**, meant for server and backend applications. This is NOT for client-side iOS, macOS, watchOS, or tvOS apps._

## Step 1: Create project in Appwrite Console

- Head to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is the user's first time using Appwrite, guide them to create an account and create their first project.
- Under **Integrate with your server**, add an **API Key** with the following scopes:

| Category | Required scopes   | Purpose                                                 |
| -------- | ----------------- | ------------------------------------------------------- |
| Database | `databases.write` | Allows API key to create, update, and delete databases. |
|          | `tables.write`    | Allows API key to create, update, and delete tables.    |
|          | `columns.write`   | Allows API key to create, update, and delete columns.   |
|          | `rows.read`       | Allows API key to read rows.                            |
|          | `rows.write`      | Allows API key to create, update, and delete rows.      |

- Other scopes are optional.

## Step 2: Create Swift project

- Create a Swift CLI application by opening **XCode** > **Create a new XCode project** > **macOS** > **Command Line Tool**.
- Follow the wizard and open the new project.

## Step 3: Install Appwrite SDK

- Install the Swift Appwrite SDK by going to **File** > **Add Packages...** and search for the repo url `https://github.com/appwrite/sdk-for-swift` and select `sdk-for-swift`.
- Specify version as `10.0.0` with rule **Up to Next Major Version**.

## Step 4: Import Appwrite and initialize client

Ask the user for the following values; never assume them:

- **Appwrite Cloud Region** (e.g., `fra`, `nyc`)
- **Project ID** (from **Console** > **Settings**)
- **API Key** (from **Console** > **View API Keys**)

Open the file `main.swift` and initialize the Appwrite Client. Replace placeholders with user-provided values:

```swift
import Foundation
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")
```

## Step 5: Initialize database

Create a function to configure a todo database and table:

```swift
let tablesDB = TablesDB(client)

func prepareDatabase() async -> (Database?, Table?) {
    let todoDatabase = try? await tablesDB.create(
        databaseId: ID.unique(),
        name: "TodosDB"
    )
    let todoTable = try? await tablesDB.createTable(
        databaseId: todoDatabase!.id,
        tableId: ID.unique(),
        name: "Todos"
    )
    try? await tablesDB.createVarcharColumn(
        databaseId: todoDatabase!.id,
        tableId: todoTable!.id,
        key: "title",
        size: 255,
        required: true
    )
    try? await tablesDB.createTextColumn(
        databaseId: todoDatabase!.id as! String,
        tableId: todoTable!.id as! String,
        key: "description",
        required: false,
        default: "This is a test description."
    )
    try? await tablesDB.createBooleanColumn(
        databaseId: todoDatabase!.id as! String,
        tableId: todoTable!.id as! String,
        key: "isComplete",
        required: true
    )

    return (todoDatabase, todoTable)
}
```

## Step 6: Add rows

Create a function to add mock data into the table:

```swift
func seedDatabase(todoDatabase: Database?, todoTable: Table?) async {
    let testTodo1: [String: Any] = [
        "title": "Buy apples",
        "description": "At least 2KGs",
        "isComplete": true
    ]

    let testTodo2: [String: Any] = [
        "title": "Wash the apples",
        "isComplete": true
    ]

    let testTodo3: [String: Any] = [
        "title": "Cut the apples",
        "description": "Don't forget to pack them in a box",
        "isComplete": false
    ]

    try? await tablesDB.createRow(
        databaseId: todoDatabase!.id,
        tableId: todoTable!.id,
        rowId: ID.unique(),
        data: testTodo1
    )
    try? await tablesDB.createRow(
        databaseId: todoDatabase!.id,
        tableId: todoTable!.id,
        rowId: ID.unique(),
        data: testTodo2
    )
    try? await tablesDB.createRow(
        databaseId: todoDatabase!.id,
        tableId: todoTable!.id,
        rowId: ID.unique(),
        data: testTodo3
    )
}
```

## Step 7: Retrieve rows

Create a function to retrieve and display the todo data:

```swift
func getTodos(todoDatabase: Database?, todoTable: Table?) async {
    let todos = try? await tablesDB.listRows(
        databaseId: todoDatabase!.id as! String,
        tableId: todoTable!.id as! String
    )
    for row in todos?.rows ?? [] {
        if let todo = row.data as? [String: Any] {
            print("Title: \(todo["title"] ?? "")\n"
                + "Description: \(todo["description"] ?? "")\n"
                + "Is Todo Complete: \(todo["isComplete"] ?? "")\n\n"
            )
        }
    }
}

let (todoDatabase, todoTable) = await prepareDatabase()
await seedDatabase(todoDatabase: todoDatabase, todoTable: todoTable)
await getTodos(todoDatabase: todoDatabase, todoTable: todoTable)
```

## Step 8: Run and test

- Run the project with **XCode** and see the results in the console.
- Verify that the todo items are printed correctly with their title, description, and completion status.
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to the client configuration.

## Deliverables

- A running Swift CLI application connected to Appwrite
- A **TodosDB** database with a **Todos** table containing three columns (`title`, `description`, `isComplete`)
- Three seeded todo rows in the database
- Console output displaying the retrieved todo items
- Files created/updated: `main.swift` (with all Appwrite client initialization, database setup, seeding, and retrieval logic)
