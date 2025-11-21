from appwrite.client import Client
from appwrite.services.avatars import Avatars
from appwrite.enums import Theme
from appwrite.enums import Timezone
from appwrite.enums import Output

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

avatars = Avatars(client)

result = avatars.get_screenshot(
    url = 'https://example.com',
    headers = {}, # optional
    viewport_width = 1, # optional
    viewport_height = 1, # optional
    scale = 0.1, # optional
    theme = Theme.LIGHT, # optional
    user_agent = '<USER_AGENT>', # optional
    fullpage = False, # optional
    locale = '<LOCALE>', # optional
    timezone = Timezone.AFRICA_ABIDJAN, # optional
    latitude = -90, # optional
    longitude = -180, # optional
    accuracy = 0, # optional
    touch = False, # optional
    permissions = [], # optional
    sleep = 0, # optional
    width = 0, # optional
    height = 0, # optional
    quality = -1, # optional
    output = Output.JPG # optional
)
