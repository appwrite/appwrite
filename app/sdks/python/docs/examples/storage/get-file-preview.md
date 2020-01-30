from appwrite.client import Client
from appwrite.services.storage import Storage

client = Client()

(client
  .set_project('')
  .set_key('')
)

storage = Storage(client)

result = storage.get_file_preview('[FILE_ID]')
