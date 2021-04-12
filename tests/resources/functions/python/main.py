import json 
import os
from appwrite.client import Client
from appwrite.services.storage import Storage

# Setup appwrite client
client = Client()
client.set_endpoint(os.environ["APPWRITE_ENDPOINT"]) # PRIVATE IP OF YOUR APPWRITE CONTAINER
client.set_project(os.environ["APPWRITE_PROJECT"]) # YOUR PROJECT ID
client.set_key(os.environ["APPWRITE_SECRET"])

storage = Storage(client)
# result = storage.get_file(os.environ["APPWRITE_FILEID"])

print(os.environ["APPWRITE_FUNCTION_ID"])
print(os.environ["APPWRITE_FUNCTION_NAME"])
print(os.environ["APPWRITE_FUNCTION_TAG"])
print(os.environ["APPWRITE_FUNCTION_TRIGGER"])
print(os.environ["APPWRITE_FUNCTION_ENV_NAME"])
print(os.environ["APPWRITE_FUNCTION_ENV_VERSION"])
# print(result["$id"])
print(os.environ["APPWRITE_FUNCTION_EVENT"])
print(os.environ["APPWRITE_FUNCTION_EVENT_DATA"])