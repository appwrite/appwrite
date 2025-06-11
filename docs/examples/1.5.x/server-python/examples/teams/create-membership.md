from appwrite.client import Client
from appwrite.services.teams import Teams

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

teams = Teams(client)

result = teams.create_membership(
    team_id = '<TEAM_ID>',
    roles = [],
    email = 'email@example.com', # optional
    user_id = '<USER_ID>', # optional
    phone = '+12065550100', # optional
    url = 'https://example.com', # optional
    name = '<NAME>' # optional
)
