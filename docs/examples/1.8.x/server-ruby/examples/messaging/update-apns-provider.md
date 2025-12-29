require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.update_apns_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>', # optional
    enabled: false, # optional
    auth_key: '<AUTH_KEY>', # optional
    auth_key_id: '<AUTH_KEY_ID>', # optional
    team_id: '<TEAM_ID>', # optional
    bundle_id: '<BUNDLE_ID>', # optional
    sandbox: false # optional
)
