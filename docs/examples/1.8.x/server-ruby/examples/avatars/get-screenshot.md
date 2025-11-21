require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

avatars = Avatars.new(client)

result = avatars.get_screenshot(
    url: 'https://example.com',
    headers: {}, # optional
    viewport_width: 1, # optional
    viewport_height: 1, # optional
    scale: 0.1, # optional
    theme: Theme::LIGHT, # optional
    user_agent: '<USER_AGENT>', # optional
    fullpage: false, # optional
    locale: '<LOCALE>', # optional
    timezone: Timezone::AFRICA_ABIDJAN, # optional
    latitude: -90, # optional
    longitude: -180, # optional
    accuracy: 0, # optional
    touch: false, # optional
    permissions: [], # optional
    sleep: 0, # optional
    width: 0, # optional
    height: 0, # optional
    quality: -1, # optional
    output: Output::JPG # optional
)
