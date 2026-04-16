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
    headers = {
        "Authorization": "Bearer token123",
        "X-Custom-Header": "value"
    }, # optional
    viewport_width = 1920, # optional
    viewport_height = 1080, # optional
    scale = 2, # optional
    theme = Theme.LIGHT, # optional
    user_agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15', # optional
    fullpage = True, # optional
    locale = 'en-US', # optional
    timezone = Timezone.AFRICA_ABIDJAN, # optional
    latitude = 37.7749, # optional
    longitude = -122.4194, # optional
    accuracy = 100, # optional
    touch = True, # optional
    permissions = ["geolocation","notifications"], # optional
    sleep = 3, # optional
    width = 800, # optional
    height = 600, # optional
    quality = 85, # optional
    output = Output.JPG # optional
)
