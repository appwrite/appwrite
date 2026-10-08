## Create a Ruby CLI application powered by Appwrite that creates a database, adds todo data, and retrieves it

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

## Step 1: Create project in Appwrite Console

- Guide the user to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is their first time, they need to create an account and create their first project.
- Under **Integrate with your server**, add an **API Key** with the following scopes:
    - Database: `databases.write`, `tables.write`, `columns.write`, `rows.read`, `rows.write`
- Ask the user for:
    - **Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from View API Keys button)
- If the user doesn't know these values, guide them to the Appwrite Console to find them.

## Step 2: Create Ruby project

- If you already have a Ruby project open, stay in it and use it.
- Otherwise, create a new Ruby CLI application:

```sh
mkdir my-app
cd my-app
bundle init
```

## Step 3: Install Appwrite SDK

- Run: `bundle add appwrite`

## Step 4: Create Appwrite client file

- Create file: `app.rb` with the following code (replace placeholders with user-provided values):

```ruby
# Initialize the Appwrite client
require 'appwrite'

include Appwrite

client = Client.new()

client
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your Appwrite Endpoint
    .set_project('<PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key
```

## Step 5: Initialize database

- Add a function to configure a todo table:

```ruby
tablesDB = TablesDB.new(client)

todo_database = nil
todo_table = nil

def prepare_database(databases)
    todo_database = tablesDB.create(
        database_id: ID.unique(),
        name: 'TodosDB'
    )

    todo_table = tablesDB.create_table(
        database_id: todo_database.id,
        table_id: ID.unique(),
        name: 'Todos'
    )

    tablesDB.create_varchar_column(
        database_id: todo_database.id,
        table_id: todo_table.id,
        key: 'title',
        size: 255,
        required: true
    )

    tablesDB.create_text_column(
        database_id: todo_database.id,
        table_id: todo_table.id,
        key: 'description',
        required: false
    )

    tablesDB.create_boolean_column(
        database_id: todo_database.id,
        table_id: todo_table.id,
        key: 'isComplete',
        required: false,
        default: false
    )
    return todo_database, todo_table
end
```

## Step 6: Add rows

- Add a function to seed mock data into the table:

```ruby
def seed_database(databases, todo_database, todo_table)
    test_todo1 = {
        title: 'Buy apples',
        description: 'At least 2KGs',
        isComplete: true
    }

    test_todo2 = {
        title: 'Wash the apples',
        isComplete: true
    }

    test_todo3 = {
        title: 'Cut the apples',
        description: 'Don\'t forget to pack them in a box',
        isComplete: false
    }

    tablesDB.create_row(
        database_id: todo_database.id,
        table_id: todo_table.id,
        row_id: ID.unique(),
        data: test_todo1
    )

    tablesDB.create_row(
        database_id: todo_database.id,
        table_id: todo_table.id,
        row_id: ID.unique(),
        data: test_todo2
    )

    tablesDB.create_row(
        database_id: todo_database.id,
        table_id: todo_table.id,
        row_id: ID.unique(),
        data: test_todo3
    )
end
```

## Step 7: Retrieve rows

- Add functions to retrieve the mock todo data and execute all tasks:

```ruby
def get_todos(databases, todo_database, todo_table)
    todos = tablesDB.list_rows(
        database_id: todo_database.id,
        table_id: todo_table.id
    )

    todos.rows.each do |todo|
        puts "Title: #{todo.data['title']}\nDescription: #{todo.data['description']}\nIs Todo Complete: #{todo.data['isComplete']}\n\n"
    end
end

def run_all_tasks(databases)
    todo_database, todo_table = prepare_database(databases)
    seed_database(databases, todo_database, todo_table)
    get_todos(databases, todo_database, todo_table)
end

run_all_tasks(databases)
```

## Step 8: Run and test

- Run: `ruby app.rb`
- View the response in the console.
- Verify that the todo data is displayed correctly with titles, descriptions, and completion status.
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to `app.rb` and Console settings.

## Deliverables

- A running Ruby CLI app with working Appwrite database integration (create database, add rows, retrieve rows)
- Files created/updated: `Gemfile` (deps), `app.rb`
