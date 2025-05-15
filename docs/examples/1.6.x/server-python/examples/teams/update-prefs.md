from appwrite.client import Client
from appwrite.services.teams import Teams

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

teams = Teams(client)

result = teams.update_prefs(
    team_id = '<TEAM_ID>',
    prefs = {}
)
