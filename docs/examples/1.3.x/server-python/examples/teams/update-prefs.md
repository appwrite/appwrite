from appwrite.client import Client
from appwrite.services.teams import Teams

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_jwt('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') # Your secret JSON Web Token
)

teams = Teams(client)

result = teams.update_prefs('[TEAM_ID]', {})
