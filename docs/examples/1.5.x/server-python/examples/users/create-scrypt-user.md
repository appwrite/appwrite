from appwrite.client import Client
from appwrite.services.users import Users

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

users = Users(client)

result = users.create_scrypt_user(
    user_id = '<USER_ID>',
    email = 'email@example.com',
    password = 'password',
    password_salt = '<PASSWORD_SALT>',
    password_cpu = None,
    password_memory = None,
    password_parallel = None,
    password_length = None,
    name = '<NAME>' # optional
)
