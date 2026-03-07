## Getting Started

### Init your SDK
Initialize your SDK with your Appwrite server API endpoint and project ID which can be found on your project settings page and your new API secret Key from project's API keys section.

```python
from appwrite.client import Client
from appwrite.services.users import Users

client = Client()

(client
  .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
  .set_self_signed() # Use only on dev mode with a self-signed SSL cert
)
```

### Make Your First Request
Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

All service methods return typed Pydantic models, so you can access response fields as attributes:

```python
users = Users(client)

user = users.create(ID.unique(), email = "email@example.com", phone = "+123456789", password = "password", name = "Walter O'Brien")

print(user.name)   # "Walter O'Brien"
print(user.email)  # "email@example.com"
print(user.id)     # The generated user ID
```

### Full Example
```python
from appwrite.client import Client
from appwrite.services.users import Users
from appwrite.id import ID

client = Client()

(client
  .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
  .set_self_signed() # Use only on dev mode with a self-signed SSL cert
)

users = Users(client)

user = users.create(ID.unique(), email = "email@example.com", phone = "+123456789", password = "password", name = "Walter O'Brien")

print(user.name)       # Access fields as attributes
print(user.to_dict())  # Convert to dictionary if needed
```

### Error Handling
The Appwrite Python SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```python
users = Users(client)
try:
  user = users.create(ID.unique(), email = "email@example.com", phone = "+123456789", password = "password", name = "Walter O'Brien")
  print(user.name)
except AppwriteException as e:
  print(e.message)
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Python Playground](https://github.com/appwrite/playground-for-python)
