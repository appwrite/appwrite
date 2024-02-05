from appwrite.client import Client

client = Client()

(client
  .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_session('') # The user session to authenticate with
)

storage = Storage(client)

result = storage.update_file('[BUCKET_ID]', '[FILE_ID]')
