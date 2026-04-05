from appwrite.client import Client
from appwrite.services.databases import Databases

client = Client()

(client
  .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

databases = Databases(client)

result = databases.delete_document('[DATABASE_ID]', '[COLLECTION_ID]', '[DOCUMENT_ID]')
