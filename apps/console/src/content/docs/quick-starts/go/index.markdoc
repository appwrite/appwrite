---
layout: article
title: Start with Go
description: Integrating Appwrite with your Go backend application is a quick and simple process. Get your backend up and running with our step-by-step guide.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to set up your first Go project powered by Appwrite.
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
![Create project screen](/images/docs/quick-starts/dark/integrate-server.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/integrate-server.avif)
{% /only_light %}

| Category  {% width=120 %} | Required scopes       | Purpose |
|-----------|-----------------------|---------|
| Database  | `databases.write`     | Allows API key to create, update, and delete [databases](/docs/products/databases/databases). |
|           | `tables.write`   | Allows API key to create, update, and delete [tables](/docs/products/databases/tables). |
|           | `columns.write`    | Allows API key to create, update, and delete [columns](/docs/products/databases/tables#columns). |
|           | `rows.read`      | Allows API key to read [rows](/docs/products/databases/rows). |
|           | `rows.write`     | Allows API key to create, update, and delete [rows](/docs/products/databases/rows). |

Other scopes are optional.

{% /section %}
{% section #step-2 step=2 title="Create Go project" %}

Create a go application.

```sh
mkdir my-app
cd my-app
go mod init go-appwrite/main
```

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the Go Appwrite SDK.

```sh
go get github.com/appwrite/sdk-for-go
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

Create a new file called `app.go`, initialize a function, and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key. Import the Appwrite dependencies for appwrite, client, databases, and models.

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

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table. Import the id Appwrite dependency by adding `"github.com/appwrite/sdk-for-go/id"` to the imported dependencies list.

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

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data to your new table.

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

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data.

```go
type Todo struct {
	Title       string `json:"title"`
	Description string `json:"description"`
	IsComplete  bool   `json:"isComplete"`
}

type TodoList struct {
	*models.RowList
	Rows []Todo `json:"rows"`
}

func getTodos() {
	// Retrieve rows (default limit is 25)
	todoResponse, _ := tablesDB.ListRows(
		todoDatabase.Id,
		todoTable.Id,
	)

	var todos TodoList
	todoResponse.Decode(&todos)

	fmt.Println("Todos:")
	for _, todo := range todos.Rows {
		fmt.Printf("Title: %s\nDescription: %s\nIs Todo Complete: %t\n\n", todo.Title, todo.Description, todo.IsComplete)
	}
}

func getCompletedTodos() {
	// Use queries to filter completed todos with pagination
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
	for _, todo := range todos.Rows {
		fmt.Printf("Title: %s\nDescription: %s\nIs Todo Complete: %t\n\n", todo.Title, todo.Description, todo.IsComplete)
	}
}

func getIncompleteTodos() {
	// Query for incomplete todos
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
	for _, todo := range todos.Rows {
		fmt.Printf("Title: %s\nDescription: %s\nIs Todo Complete: %t\n\n", todo.Title, todo.Description, todo.IsComplete)
	}
}
```

Make sure to update `main()` with the functions you created. Your `main()` function should look something like this:

```go
package main

import (
	"fmt"

	"github.com/appwrite/sdk-for-go/appwrite"
	"github.com/appwrite/sdk-for-go/client"
	"github.com/appwrite/sdk-for-go/tablesdb"
	"github.com/appwrite/sdk-for-go/id"
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

	prepareDatabase()
	seedDatabase()
	getTodos()
	getCompletedTodos()
	getIncompleteTodos()
}
```

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with `go run .` and view the response in your console.

{% /section %}
