from appwrite.client import Client
from appwrite.services.locale import Locale

client = Client()

(client
  .set_project('')
  .set_key('')
)

locale = Locale(client)

result = locale.get_continents()
