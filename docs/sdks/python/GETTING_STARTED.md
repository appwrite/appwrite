## Getting started

### Initialize the client

Create a client with your Appwrite endpoint, project ID and an [API key](https://appwrite.io/docs/partners/project/api-keys). Find the endpoint and project ID in your project's **Settings** page, and create an API key with only the scopes you need under **API keys** in the project sidebar.

```python
from appwrite.client import Client

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')
```

API keys grant server-level access to your project. Keep them out of client-side code and version control.

### Make a request

Create a service object with the client and call any of its methods. Every method returns a typed Pydantic model, so response fields are available as attributes.

```python
from appwrite.id import ID
from appwrite.services.users import Users

users = Users(client)

user = users.create(
    user_id=ID.unique(),
    email='email@example.com',
    password='password',
    name="Walter O'Brien",
)

print(user.id)
print(user.name)
print(user.to_dict())
```

See the [API reference](https://appwrite.io/docs/references/cloud/server-python/users) for every service and method.

### Work with rows

Use `TablesDB` to read and write rows. Pass a Pydantic model as `model_type` to get typed row data back; without it, `row.data` is a `dict`.

```python
from typing import Optional

from pydantic import BaseModel

from appwrite.id import ID
from appwrite.query import Query
from appwrite.services.tables_db import TablesDB


class Post(BaseModel):
    title: str
    published: bool
    excerpt: Optional[str] = None


tables_db = TablesDB(client)

row = tables_db.create_row(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    row_id=ID.unique(),
    data={'title': 'Hello, world', 'published': True},
    model_type=Post,
)

print(row.id)
print(row.data.title)

result = tables_db.list_rows(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    queries=[Query.equal('published', True), Query.limit(25)],
    model_type=Post,
)

for row in result.rows:
    print(row.data.title)
```

### Handle errors

Failed requests raise `AppwriteException`, which carries `message`, `code`, `type` and `response`.

```python
from appwrite.exception import AppwriteException

try:
    user = users.get(user_id='<USER_ID>')
except AppwriteException as error:
    print(error.code, error.type, error.message)
```

### Self-hosted Appwrite

Point the client at your own instance instead of Appwrite Cloud. Call `set_self_signed()` only in development, when the server uses a self-signed TLS certificate.

```python
client.set_endpoint('https://appwrite.example.com/v1')
client.set_self_signed()
```

### Learn more

- [Python quick start](https://appwrite.io/docs/quick-starts/python)
- [Python API reference](https://appwrite.io/docs/references/cloud/server-python/users)
- [Appwrite docs](https://appwrite.io/docs)
- [Discord community](https://appwrite.io/discord)
- [Appwrite Python playground](https://github.com/appwrite/playground-for-python)
