from appwrite.client import Client

client = Client()

(client
  .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
)

account = Account(client)

result = account.create_phone_token('[USER_ID]', '+12065550100')
