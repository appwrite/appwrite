from appwrite.client import Client
from appwrite.services.database import Database

client = Client()

(client
  .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

database = Database(client)

result = database.create_string_attribute('[COLLECTION_ID]', '', 1, False)
