require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging.new(client)

response = messaging.update_apns_provider(
    provider_id: '[PROVIDER_ID]',
    name: '[NAME]', # optional
    enabled: false, # optional
    auth_key: '[AUTH_KEY]', # optional
    auth_key_id: '[AUTH_KEY_ID]', # optional
    team_id: '[TEAM_ID]', # optional
    bundle_id: '[BUNDLE_ID]' # optional
)

puts response.inspect
