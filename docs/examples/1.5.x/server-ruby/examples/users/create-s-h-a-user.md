require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

users = Users.new(client)

result = users.create_sha_user(
    user_id: '<USER_ID>',
    email: 'email@example.com',
    password: 'password',
    password_version: PasswordHash::SHA1, # optional
    name: '<NAME>' # optional
)
