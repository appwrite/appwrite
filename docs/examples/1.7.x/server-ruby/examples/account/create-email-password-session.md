require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID

account = Account.new(client)

result = account.create_email_password_session(
    email: 'email@example.com',
    password: 'password'
)
