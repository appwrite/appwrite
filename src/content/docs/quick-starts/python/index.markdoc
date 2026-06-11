---
layout: article
title: Start with Python
description: Learn to get started with server integrations with Appwrite Python SDK.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first Python project powered by Appwrite.
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
{% section #step-2 step=2 title="Create Python project" %}
Create a directory for the project.

```sh
mkdir my_app
cd my_app
```

After that, create a virtual environment in this directory and activate it.

```sh
# Create a venv
python -m venv .venv

# Active the venv in Unix shell
source .venv/bin/activate

# Or in Powershell
.venv/Scripts/Activate.ps1
```

Finally, create a file `my_app.py`.

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the Python Appwrite SDK.

```sh
pip install appwrite
```

Or with `uv`:

```sh
uv add appwrite
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

Open `my_app.py` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

```py
from appwrite.client import Client
from appwrite.services.tables_db import TablesDB
from appwrite.id import ID

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')
```

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, define a Pydantic model for type-safe data access and create a function to configure a todo table.

```py
from pydantic import BaseModel
from typing import Optional

# Define a Pydantic model matching the table schema
class Todo(BaseModel):
    title: str
    description: Optional[str] = None
    isComplete: bool

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
    database_id=todoDatabase.id,
    table_id=ID.unique(),
    name='Todos'
  )

  tablesDB.create_varchar_column(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    key='title',
    size=255,
    required=True
  )

  tablesDB.create_text_column(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    key='description',
    required=False,
    default='This is a test description.'
  )

  tablesDB.create_boolean_column(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    key='isComplete',
    required=True
  )
```

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.

```py
def seed_database():
  testTodo1 = Todo(
    title="Buy apples",
    description="At least 2KGs",
    isComplete=True
  )

  testTodo2 = Todo(
    title="Wash the apples",
    isComplete=True
  )

  testTodo3 = Todo(
    title="Cut the apples",
    description="Don\'t forget to pack them in a box",
    isComplete=False
  )

  tablesDB.create_row(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    row_id=ID.unique(),
    data=testTodo1.model_dump()
  )

  tablesDB.create_row(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    row_id=ID.unique(),
    data=testTodo2.model_dump()
  )

  tablesDB.create_row(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    row_id=ID.unique(),
    data=testTodo3.model_dump()
  )
```

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data,
then execute the functions in `_main_`.

```py
from appwrite.query import Query

def get_todos():
  # Retrieve rows with type-safe access (default limit is 25)
  todos = tablesDB.list_rows(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    model_type=Todo
  )
  print("Todos:")
  for todo in todos.rows:
    print(f"Title: {todo.data.title}\nDescription: {todo.data.description}\nIs Todo Complete: {todo.data.isComplete}\n\n")

def get_completed_todos():
  # Use queries to filter completed todos with pagination
  todos = tablesDB.list_rows(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    model_type=Todo,
    queries=[
      Query.equal("isComplete", True),
      Query.order_desc("$createdAt"),
      Query.limit(5)
    ]
  )
  print("Completed todos (limited to 5):")
  for todo in todos.rows:
    print(f"Title: {todo.data.title}\nDescription: {todo.data.description}\nIs Todo Complete: {todo.data.isComplete}\n\n")

def get_incomplete_todos():
  # Query for incomplete todos
  todos = tablesDB.list_rows(
    database_id=todoDatabase.id,
    table_id=todoTable.id,
    model_type=Todo,
    queries=[
      Query.equal("isComplete", False),
      Query.order_asc("title")
    ]
  )
  print("Incomplete todos (ordered by title):")
  for todo in todos.rows:
    print(f"Title: {todo.data.title}\nDescription: {todo.data.description}\nIs Todo Complete: {todo.data.isComplete}\n\n")

if __name__ == "__main__":
  prepare_database()
  seed_database()
  get_todos()
  get_completed_todos()
  get_incomplete_todos()
```

{% /section %}
{% section #step-8 step=8 title="All set" %}

Run your project with `python my_app.py` and view the response in your console.

{% /section %}
