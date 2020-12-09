import json 
import os
from appwrite.client import Client
from appwrite.services.storage import Storage

#payload = json.loads(os.environ["APPWRITE_FUNCTION_EVENT_PAYLOAD"] or "{}")
#fileID = payload["$id"] or os.environ["APPWRITE_FILE"]

# Setup appwrite client
client = Client()
client.set_endpoint(os.environ["APPWRITE_ENDPOINT"]) # PRIVATE IP OF YOUR APPWRITE CONTAINER
client.set_project(os.environ["APPWRITE_PROJECT"]) # YOUR PROJECT ID
client.set_key(os.environ["APPWRITE_SECRET"])

storage = Storage(client)
#result = storage.get_file("")

print(os.environ["APPWRITE_FUNCTION_ID"])
print(os.environ["APPWRITE_FUNCTION_NAME"])
print(os.environ["APPWRITE_FUNCTION_TAG"])
print(os.environ["APPWRITE_FUNCTION_TRIGGER"])
print(os.environ["APPWRITE_FUNCTION_ENV_NAME"])
print(os.environ["APPWRITE_FUNCTION_ENV_VERSION"])
print(os.environ["APPWRITE_FUNCTION_EVENT"])
print(os.environ["APPWRITE_FUNCTION_EVENT_PAYLOAD"])