require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID

account = Account.new(client)

result = account.update_phone_session(
    user_id: '<USER_ID>',
    secret: '<SECRET>'
)
