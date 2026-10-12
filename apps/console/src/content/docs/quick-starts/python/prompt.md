## Create a Python app with Appwrite server integration

Create a Python app with Appwrite server integration that creates a todo database, seeds it with data, and retrieves rows.

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

## Step 2: Create Python project

- Create a directory for the project:

```sh
mkdir my_app
cd my_app
```

- Create a virtual environment and activate it:

```sh
# Create a venv
python -m venv .venv

# Active the venv in Unix shell
source .venv/bin/activate

# Or in Powershell
.venv/Scripts/Activate.ps1
```

- Create a file named `my_app.py`

## Step 3: Install Appwrite SDK

- Run: `pip install appwrite==13.6.1`

## Step 4: Import Appwrite and initialize client (ask user for details; never assume)

- Ask the user for:
    - Appwrite Cloud Region (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from Console -> View API Keys)
- Open `my_app.py` and initialize the Appwrite Client:

```py
from appwrite.client import Client
from appwrite.services.tables_db import TablesDB
from appwrite.id import ID

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')
```

## Step 5: Initialize database

- Create a function to configure a todo table:

```py
tablesDB = TablesDB(client)

todoDatabase = None
todoTable = None

def prepare_database():
  global todoDatabase
  global todoTable

  todoDatabase = tablesDB.create(
    database_id=ID.unique(),
    name='TodosDB'
  )

  todoTable = tablesDB.create_table(
    database_id=todoDatabase['$id'],
    table_id=ID.unique(),
    name='Todos'
  )

  tablesDB.create_varchar_column(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    key='title',
    size=255,
    required=True
  )

  tablesDB.create_text_column(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    key='description',
    required=False,
    default='This is a test description.'
  )

  tablesDB.create_boolean_column(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    key='isComplete',
    required=True
  )
```

## Step 6: Add rows

- Create a function to add mock data into the table:

```py
def seed_database():
  testTodo1 = {
    'title': "Buy apples",
    'description': "At least 2KGs",
    'isComplete': True
  }

  testTodo2 = {
    'title': "Wash the apples",
    'isComplete': True
  }

  testTodo3 = {
    'title': "Cut the apples",
    'description': "Don\'t forget to pack them in a box",
    'isComplete': False
  }

  tablesDB.create_row(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    row_id=ID.unique(),
    data=testTodo1
  )

  tablesDB.create_row(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    row_id=ID.unique(),
    data=testTodo2
  )

  tablesDB.create_row(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    row_id=ID.unique(),
    data=testTodo3
  )
```

## Step 7: Retrieve rows

- Create functions to retrieve the mock todo data and execute them in `__main__`:

```py
from appwrite.query import Query

def get_todos():
  # Retrieve rows (default limit is 25)
  todos = tablesDB.list_rows(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id']
  )
  print("Todos:")
  for todo in todos['rows']:
    print(f"Title: {todo['title']}\nDescription: {todo['description']}\nIs Todo Complete: {todo['isComplete']}\n\n")

def get_completed_todos():
  # Use queries to filter completed todos with pagination
  todos = tablesDB.list_rows(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    queries=[
      Query.equal("isComplete", True),
      Query.order_desc("$createdAt"),
      Query.limit(5)
    ]
  )
  print("Completed todos (limited to 5):")
  for todo in todos['rows']:
    print(f"Title: {todo['title']}\nDescription: {todo['description']}\nIs Todo Complete: {todo['isComplete']}\n\n")

def get_incomplete_todos():
  # Query for incomplete todos
  todos = tablesDB.list_rows(
    database_id=todoDatabase['$id'],
    table_id=todoTable['$id'],
    queries=[
      Query.equal("isComplete", False),
      Query.order_asc("title")
    ]
  )
  print("Incomplete todos (ordered by title):")
  for todo in todos['rows']:
    print(f"Title: {todo['title']}\nDescription: {todo['description']}\nIs Todo Complete: {todo['isComplete']}\n\n")

if __name__ == "__main__":
  prepare_database()
  seed_database()
  get_todos()
  get_completed_todos()
  get_incomplete_todos()
```

## Step 8: Run and test

- Run: `python my_app.py`
- View the response in your console
- Verify the todos are printed correctly including:
    - All todos
    - Completed todos (limited to 5)
    - Incomplete todos (ordered by title)

## Deliverables

- A running Python app with working Appwrite server integration
- Files created: `my_app.py`
- A **TodosDB** database with a **Todos** table containing `title`, `description`, and `isComplete` columns
- Three seeded todo rows with the ability to query and filter them
