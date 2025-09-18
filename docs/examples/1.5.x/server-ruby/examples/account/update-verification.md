require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

account = Account.new(client)

result = account.update_verification(
    user_id: '<USER_ID>',
    secret: '<SECRET>'
)
