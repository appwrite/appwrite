from appwrite.client import Client
from appwrite.enums import Browser

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_session('') # The user session to authenticate with

avatars = Avatars(client)

result = avatars.get_browser(
    code = Browser.AVANT_BROWSER,
    width = 0, # optional
    height = 0, # optional
    quality = 0 # optional
)
