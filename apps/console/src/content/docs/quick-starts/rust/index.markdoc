---
layout: article
title: Start with Rust
description: Learn to get started with server integrations with Appwrite Rust SDK.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first Rust project powered by Appwrite.
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
{% section #step-2 step=2 title="Create Rust project" %}

Create a new Rust application.

```sh
cargo new my_app
cd my_app
```

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the Rust Appwrite SDK and its dependencies.

```sh
cargo add appwrite
cargo add tokio -F full
cargo add serde_json
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

Open `src/main.rs` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    Ok(())
}
```

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

```rust
async fn prepare_database(
    tables_db: &TablesDB,
) -> Result<(String, String), Box<dyn std::error::Error>> {
    let todo_database = tables_db.create(
        ID::unique(),
        "TodosDB",
        None,
    ).await?;

    let todo_table = tables_db.create_table(
        &todo_database.id,
        ID::unique(),
        "Todos",
        None, None, None, None, None,
    ).await?;

    tables_db.create_varchar_column(
        &todo_database.id,
        &todo_table.id,
        "title",
        255,
        true,
        None, None, None,
    ).await?;

    tables_db.create_text_column(
        &todo_database.id,
        &todo_table.id,
        "description",
        false,
        Some("This is a test description."),
        None, None,
    ).await?;

    tables_db.create_boolean_column(
        &todo_database.id,
        &todo_table.id,
        "isComplete",
        true,
        None, None,
    ).await?;

    Ok((todo_database.id, todo_table.id))
}
```

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.

```rust
async fn seed_database(
    tables_db: &TablesDB,
    database_id: &str,
    table_id: &str,
) -> Result<(), Box<dyn std::error::Error>> {
    tables_db.create_row(
        database_id,
        table_id,
        ID::unique(),
        json!({
            "title": "Buy apples",
            "description": "At least 2KGs",
            "isComplete": true
        }),
        None, None,
    ).await?;

    tables_db.create_row(
        database_id,
        table_id,
        ID::unique(),
        json!({
            "title": "Wash the apples",
            "isComplete": true
        }),
        None, None,
    ).await?;

    tables_db.create_row(
        database_id,
        table_id,
        ID::unique(),
        json!({
            "title": "Cut the apples",
            "description": "Don't forget to pack them in a box",
            "isComplete": false
        }),
        None, None,
    ).await?;

    Ok(())
}
```

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data and execute the functions in `main`.

```rust
use appwrite::query::Query;

async fn get_todos(
    tables_db: &TablesDB,
    database_id: &str,
    table_id: &str,
) -> Result<(), Box<dyn std::error::Error>> {
    // Retrieve rows (default limit is 25)
    let todos = tables_db.list_rows(
        database_id,
        table_id,
        None, None, None, None,
    ).await?;

    println!("Todos:");
    for todo in &todos.rows {
        println!("Title: {}\nDescription: {}\nIs Todo Complete: {}\n",
            todo.get::<String>("title").unwrap_or_default(),
            todo.get::<String>("description").unwrap_or_default(),
            todo.get::<bool>("isComplete").unwrap_or_default(),
        );
    }

    // Use queries to filter completed todos with pagination
    let completed_todos = tables_db.list_rows(
        database_id,
        table_id,
        Some(vec![
            Query::equal("isComplete", true).to_string(),
            Query::order_desc("$createdAt").to_string(),
            Query::limit(5).to_string(),
        ]),
        None, None, None,
    ).await?;

    println!("Completed todos (limited to 5):");
    for todo in &completed_todos.rows {
        println!("Title: {}\nDescription: {}\nIs Todo Complete: {}\n",
            todo.get::<String>("title").unwrap_or_default(),
            todo.get::<String>("description").unwrap_or_default(),
            todo.get::<bool>("isComplete").unwrap_or_default(),
        );
    }

    // Query for incomplete todos
    let incomplete_todos = tables_db.list_rows(
        database_id,
        table_id,
        Some(vec![
            Query::equal("isComplete", false).to_string(),
            Query::order_asc("title").to_string(),
        ]),
        None, None, None,
    ).await?;

    println!("Incomplete todos (ordered by title):");
    for todo in &incomplete_todos.rows {
        println!("Title: {}\nDescription: {}\nIs Todo Complete: {}\n",
            todo.get::<String>("title").unwrap_or_default(),
            todo.get::<String>("description").unwrap_or_default(),
            todo.get::<bool>("isComplete").unwrap_or_default(),
        );
    }

    Ok(())
}
```

Make sure to update `main()` with the functions you created. Your `main()` function should look something like this:

```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use appwrite::query::Query;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let tables_db = TablesDB::new(&client);

    let (database_id, table_id) = prepare_database(&tables_db).await?;
    seed_database(&tables_db, &database_id, &table_id).await?;
    get_todos(&tables_db, &database_id, &table_id).await?;

    Ok(())
}
```

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with `cargo run` and view the response in your console.

{% /section %}
