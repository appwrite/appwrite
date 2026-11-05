## Create a Rust app with Appwrite server integration

Create a Rust app with Appwrite server integration that creates a todo database, seeds it with data, and retrieves rows.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

## Step 1: Create project in Appwrite Console

- If this is the user's first time using Appwrite, guide them to create an account and project at https://cloud.appwrite.io/console
- Under **Integrate with your server**, add an **API Key** with the following scopes:

| Category | Required scopes   | Purpose                                                |
| -------- | ----------------- | ------------------------------------------------------ |
| Database | `databases.write` | Allows API key to create, update, and delete databases |
|          | `tables.write`    | Allows API key to create, update, and delete tables    |
|          | `columns.write`   | Allows API key to create, update, and delete columns   |
|          | `rows.read`       | Allows API key to read rows                            |
|          | `rows.write`      | Allows API key to create, update, and delete rows      |

- Other scopes are optional.

## Step 2: Create Rust project

- If you already have a Rust project open, stay in it and use it.
- Otherwise, run:

```sh
cargo new my_app
cd my_app
```

## Step 3: Install Appwrite SDK

- Run:

```sh
cargo add appwrite
cargo add tokio -F full
cargo add serde_json
```

## Step 4: Import Appwrite and initialize client (ask user for details; never assume)

- Ask the user for:
    - Appwrite Cloud Region (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from Console -> View API Keys)
- Open `src/main.rs` and initialize the Appwrite Client:

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

## Step 5: Initialize database

- Create a function to configure a todo table:

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

## Step 6: Add rows (seed database)

- Create a function to add mock data into the table:

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

## Step 7: Retrieve rows

- Add the query import and create functions to retrieve todos:

```rust
use appwrite::query::Query;

async fn get_todos(
    tables_db: &TablesDB,
    database_id: &str,
    table_id: &str,
) -> Result<(), Box<dyn std::error::Error>> {
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

## Step 8: Update main function and run

- Update `main()` to call all functions:

```rust
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

- Run: `cargo run`
- View the response in the console

## Deliverables

- A running Rust app with working Appwrite server integration
- Files created: `Cargo.toml`, `src/main.rs`
- A **TodosDB** database with a **Todos** table containing `title`, `description`, and `isComplete` columns
- Three seeded todo rows with the ability to query and filter them
