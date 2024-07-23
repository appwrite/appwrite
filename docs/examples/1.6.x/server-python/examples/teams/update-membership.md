from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_session('') # The user session to authenticate with

teams = Teams(client)

result = teams.update_membership(
    team_id = '<TEAM_ID>',
    membership_id = '<MEMBERSHIP_ID>',
    roles = []
)
