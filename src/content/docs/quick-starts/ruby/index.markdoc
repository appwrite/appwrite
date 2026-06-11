---
layout: article
title: Start with Ruby
description: Dive into our step-by-step guide on integrating Appwrite with your Ruby server backend application. Get your backend up and running quickly with this tutorial.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first Ruby project powered by Appwrite.
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
{% section #step-2 step=2 title="Create Ruby project" %}
Create a Ruby CLI application.

```sh
mkdir my-app
cd my-app
bundle init
```

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the Ruby Appwrite SDK. Make sure to lock your SDK to version `10.0.0` to avoid breaking changes.

```sh
bundle add appwrite 
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

Create a new file `app.rb` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

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

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

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

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.

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

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data and a function to execute the requests in order.
Run the functions to by calling `run_all_tasks()`.  

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

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with `ruby app.rb` and view the response in your console.  

{% /section %}