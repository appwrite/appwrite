require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID

account = Account.new(client)

result = account.update_phone_session(
    user_id: '<USER_ID>',
    secret: '<SECRET>'
)
