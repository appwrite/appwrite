from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID

account = Account(client)

result = account.create(
    user_id = '<USER_ID>',
    email = 'email@example.com',
    password = '',
    name = '<NAME>' # optional
)
