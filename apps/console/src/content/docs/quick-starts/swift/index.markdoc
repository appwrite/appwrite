---
layout: article
title: Start with Swift
description: Learn to get started with server integrations with Appwrite Swift SDK.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first Swift project powered by Appwrite.

{% info title="Server SDK" %}
This tutorial is for the Swift Server SDK, meant for server and backend applications.
If you're trying to build a client-side app, like an iOS, macOS, watchOS or tvOS app, 
follow the [Start with Apple guide](https://appwrite.io/docs/quick-starts/apple).
{% /info %}

{% section #step-1 step=1 title="Create project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console).

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
{% section #step-2 step=2 title="Create Swift project" %}
Create a Swift CLI application by opening **XCode** > **Create a new XCode project**
> **macOS** > **Command Line Tool**.

Follow the wizard and open your new project.

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the Swift Appwrite SDK by going to **File** > **Add Packages...** and search for the repo url 
`https://github.com/appwrite/sdk-for-swift` and select `sdk-for-swift`.
Specify version as `10.0.0` with rule **Up to Next Major Version**.

{% /section %}
{% section #step-4 step=4 title="Import Appwrite" %}

Find your project ID in the **Settings** page. Also, click on the **View API Keys** button to find the API key that was created earlier. 

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Open the file `main.swift` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

```swift
import Foundation
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")
```

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

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

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.
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

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data.

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

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with XCode and see the results in the console.

{% /section %}