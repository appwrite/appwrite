from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID

account = Account(client)

result = account.update_magic_url_session(
    user_id = '<USER_ID>',
    secret = '<SECRET>'
)
