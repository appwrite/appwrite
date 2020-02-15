from appwrite.client import Client
from appwrite.services.locale import Locale

client = Client()

(client
  .set_project('5df5acd0d48c2')
  .set_key('919c2d18fb5d4...a2ae413da83346ad2')
)

locale = Locale(client)

result = locale.get_continents()
