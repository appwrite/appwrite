import os
from appwrite.client import Client
from appwrite.services.account import Account

client = Client()
client.set_endpoint(os.environ.get("APPWRITE_ENDPOINT"))
client.set_project(os.environ.get("APPWRITE_PROJECT_ID"))
client.set_key(os.environ.get("APPWRITE_API_KEY"))

# Example: get current user
account = Account(client)
user = account.get()
print(f"Hello, {user['name']}")
