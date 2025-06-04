from appwrite.client import Client
from appwrite.input_file import InputFile
from appwrite.services.storage import Storage

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

storage = Storage(client)

result = storage.create_file('[BUCKET_ID]', '[FILE_ID]', InputFile.from_path('file.png'))
