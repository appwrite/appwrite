from appwrite.client import Client
from appwrite.services.avatars import Avatars

client = Client()

(client
  .set_project('')
  .set_key('')
)

avatars = Avatars(client)

result = avatars.get_image('https://example.com')
