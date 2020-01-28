from appwrite.client import Client
from appwrite.services.users import Users

client = Client()

(client
  .set_project('')
  .set_key('')
)

users = Users(client)

result = users.update_user_prefs('[USER_ID]', '')
