require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

users = Users.new(client)

result = users.create(
    user_id: '<USER_ID>',
    email: 'email@example.com', # optional
    phone: '+12065550100', # optional
    password: '', # optional
    name: '<NAME>' # optional
)
