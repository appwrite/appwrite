require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

users = Users.new(client)

result = users.create_jwt(
    user_id: '<USER_ID>',
    session_id: '<SESSION_ID>', # optional
    duration: 0 # optional
)
