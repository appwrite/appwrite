from appwrite.client import Client
from appwrite.input_file import InputFile

client = Client()

(client
  .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_session('') # The user session to authenticate with
)

storage = Storage(client)

result = storage.create_file('[BUCKET_ID]', '[FILE_ID]', InputFile.from_path('file.png'))
