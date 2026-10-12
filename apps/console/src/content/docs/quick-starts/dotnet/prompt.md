## Create a .NET console application with Appwrite server integration that sets up a todo database with tables and rows

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

## Step 1: Create project in Appwrite Console

- Head to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is the user's first time, guide them to create an account and create their first project.
- Under **Integrate with your server**, add an **API Key** with the following scopes:

| Category | Required scopes   | Purpose                                                 |
| -------- | ----------------- | ------------------------------------------------------- |
| Database | `databases.write` | Allows API key to create, update, and delete databases. |
|          | `tables.write`    | Allows API key to create, update, and delete tables.    |
|          | `columns.write`   | Allows API key to create, update, and delete columns.   |
|          | `rows.read`       | Allows API key to read rows.                            |
|          | `rows.write`      | Allows API key to create, update, and delete rows.      |

- Other scopes are optional.

## Step 2: Create .NET project

- Run: `dotnet new console -o MyApp`
- Change dir: `cd MyApp`

## Step 3: Install Appwrite SDK

- Run: `dotnet add package Appwrite --version 0.13.0`

## Step 4: Import Appwrite and initialize client (ask user for details; never assume)

- Ask the user for:
    - Appwrite **Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from Console -> View API Keys)
- Open `Program.cs` and initialize the Appwrite Client.
- Create/update file: `Program.cs` with key snippet:

```csharp
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

var client = new Client();

client
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");
```

## Step 5: Initialize database

- Add code to create a todo database and table with columns:

```csharp
var tablesDB = new TablesDB(client);

Database todoDatabase;
Table todoTable;

todoDatabase = await tablesDB.Create(
    databaseId: ID.Unique(),
    name: "TodosDB"
);

todoTable = await tablesDB.CreateTable(
    databaseId: todoDatabase.Id,
    tableId: ID.Unique(),
    name: "Todos"
);

await tablesDB.CreateVarcharColumn(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    key: "title",
    size: 255,
    required: true
);

await tablesDB.CreateTextColumn(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    key: "description",
    required: false,
    xdefault: "This is a test description"
);

await tablesDB.CreateBooleanColumn(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    key: "isComplete",
    required: true
);
```

## Step 6: Add rows

- Add code to insert mock todo data:

```csharp
var testTodo1 = new Dictionary<string, object>()
{
    {"title", "Buy apples"},
    {"description", "At least 2KGs"},
    {"isComplete", true}
};

var testTodo2 = new Dictionary<string, object>()
{
    {"title", "Wash the apples"},
    {"isComplete", true}
};

var testTodo3 = new Dictionary<string, object>()
{
    {"title", "Cut the apples"},
    {"description", "Don't forget to pack them in a box"},
    {"isComplete", false}
};

await tablesDB.CreateRow(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    rowId: ID.Unique(),
    data: testTodo1
);

await tablesDB.CreateRow(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    rowId: ID.Unique(),
    data: testTodo2
);

await tablesDB.CreateRow(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    rowId: ID.Unique(),
    data: testTodo3
);
```

## Step 7: Retrieve rows

- Add code to retrieve and display the todo data:

```csharp
var todos = await tablesDB.ListRows(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id
);

foreach (var todo in todos.Rows)
{
    Console.WriteLine($"Title: {todo.Data["title"]}\nDescription: {todo.Data["description"]}\nIs Todo Complete: {todo.Data["isComplete"]}\n\n");
}
```

## Step 8: Run and test

- Run: `dotnet run`
- View the response in the console.
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to `Program.cs` and Console settings.

## Deliverables

- A running .NET console application with working Appwrite server integration
- Files created/updated: `MyApp.csproj` (deps), `Program.cs`
- A **TodosDB** database with a **Todos** table containing three sample todo items
