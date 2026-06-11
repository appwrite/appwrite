## Create a Go backend application integrated with Appwrite, with a working todo database that can create, seed, and retrieve data

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

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

## Step 2: Create Go project

- If you already have a Go project open, stay in it and use it.
- Otherwise, run:

```sh
mkdir my-app
cd my-app
go mod init go-appwrite/main
```

## Step 3: Install Appwrite SDK

- Run: `go get github.com/appwrite/sdk-for-go`

## Step 4: Import Appwrite and initialize client (ask user for details; never assume)

- Ask the user for:
    - **Project ID** (from Console -> Settings)
    - **API Key** (from Console -> View API Keys)
- Create file: `app.go` with the following code. Replace `<PROJECT_KEY>` with their project ID and `<API_KEY>` with their API key:

```go
package main

import (
    "github.com/appwrite/sdk-for-go/appwrite"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tablesdb"
    "github.com/appwrite/sdk-for-go/models"
    "github.com/appwrite/sdk-for-go/query"
)

var (
    appwriteClient    client.Client
    todoDatabase      *models.Database
    todoTable    *models.Table
    tablesDB *tablesdb.TablesDB
)

func main() {
    appwriteClient = appwrite.NewClient(
        appwrite.WithProject("<PROJECT_KEY>"),
        appwrite.WithKey("<API_KEY>"),
    )
}
```

## Step 5: Initialize database

- Add import for id: `"github.com/appwrite/sdk-for-go/id"`
- Add the `prepareDatabase` function to create a todo database and table with columns:

```go
func prepareDatabase() {
    tablesDB = appwrite.NewTablesDB(appwriteClient)

    todoDatabase, _ = tablesDB.Create(
        id.Unique(),
        "TodosDB",
    )

    todoTable, _ = tablesDB.CreateTable(
        todoDatabase.Id,
        id.Unique(),
        "Todos",
    )

    tablesDB.CreateVarcharColumn(
        todoDatabase.Id,
        todoTable.Id,
        "title",
        255,
        true,
    )

    tablesDB.CreateTextColumn(
        todoDatabase.Id,
        todoTable.Id,
        "description",
        false,
    )

    tablesDB.CreateBooleanColumn(
        todoDatabase.Id,
        todoTable.Id,
        "isComplete",
        true,
    )
}
```

## Step 6: Add rows (seed database)

- Add the `seedDatabase` function to insert mock todo data:

```go
func seedDatabase() {
    testTodo1 := map[string]interface{}{
        "title":       "Buy apples",
        "description": "At least 2KGs",
        "isComplete":  true,
    }

    testTodo2 := map[string]interface{}{
        "title":      "Wash the apples",
        "isComplete": true,
    }

    testTodo3 := map[string]interface{}{
        "title":       "Cut the apples",
        "description": "Don't forget to pack them in a box",
        "isComplete":  false,
    }

    tablesDB.CreateRow(
        todoDatabase.Id,
        todoTable.Id,
        id.Unique(),
        testTodo1,
    )

    tablesDB.CreateRow(
        todoDatabase.Id,
        todoTable.Id,
        id.Unique(),
        testTodo2,
    )

    tablesDB.CreateRow(
        todoDatabase.Id,
        todoTable.Id,
        id.Unique(),
        testTodo3,
    )
}
```

## Step 7: Retrieve rows

- Add `Todo` and `TodoList` structs:

```go
type Todo struct {
    Title       string `json:"title"`
    Description string `json:"description"`
    IsComplete  bool   `json:"isComplete"`
}

type TodoList struct {
    *models.DocumentList
    Documents []Todo `json:"rows"`
}
```

- Add `getTodos` function to retrieve all todos:

```go
func getTodos() {
    todoResponse, _ := tablesDB.ListRows(
        todoDatabase.Id,
        todoTable.Id,
    )

    var todos TodoList
    todoResponse.Decode(&todos)

    fmt.Println("Todos:")
    for _, todo := range todos.Documents {
        fmt.Printf("Title: %s\nDescription: %s\nIs Todo Complete: %t\n\n", todo.Title, todo.Description, todo.IsComplete)
    }
}
```

- Add `getCompletedTodos` function with query filtering:

```go
func getCompletedTodos() {
    todoResponse, _ := tablesDB.ListRows(
        todoDatabase.Id,
        todoTable.Id,
        tablesDB.WithListRowsQueries([]string{
            query.Equal("isComplete", true),
            query.OrderDesc("$createdAt"),
            query.Limit(5),
        }),
    )

    var todos TodoList
    todoResponse.Decode(&todos)

    fmt.Println("Completed todos (limited to 5):")
    for _, todo := range todos.Documents {
        fmt.Printf("Title: %s\nDescription: %s\nIs Todo Complete: %t\n\n", todo.Title, todo.Description, todo.IsComplete)
    }
}
```

- Add `getIncompleteTodos` function:

```go
func getIncompleteTodos() {
    todoResponse, _ := tablesDB.ListRows(
        todoDatabase.Id,
        todoTable.Id,
        tablesDB.WithListRowsQueries([]string{
            query.Equal("isComplete", false),
            query.OrderAsc("title"),
        }),
    )

    var todos TodoList
    todoResponse.Decode(&todos)

    fmt.Println("Incomplete todos (ordered by title):")
    for _, todo := range todos.Documents {
        fmt.Printf("Title: %s\nDescription: %s\nIs Todo Complete: %t\n\n", todo.Title, todo.Description, todo.IsComplete)
    }
}
```

## Step 8: Update main function and run

- Update `main()` to call all functions:

```go
func main() {
    appwriteClient = appwrite.NewClient(
        appwrite.WithProject("<PROJECT_KEY>"),
        appwrite.WithKey("<API_KEY>"),
    )

    prepareDatabase()
    seedDatabase()
    getTodos()
    getCompletedTodos()
    getIncompleteTodos()
}
```

- Add `"fmt"` to the imports
- Run: `go run .`
- View the response in the console

## Deliverables

- A running Go application with Appwrite integration
- Files created/updated: `go.mod`, `go.sum` (deps), `app.go`
- Working todo database with tables and columns
- Functions to create, seed, and query todo data with filtering capabilities
