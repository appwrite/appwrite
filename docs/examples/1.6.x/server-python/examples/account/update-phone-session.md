from appwrite.client import Client
from appwrite.services.account import Account

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID

account = Account(client)

result = account.update_phone_session(
    user_id = '<USER_ID>',
    secret = '<SECRET>'
)
