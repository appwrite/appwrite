from appwrite.client import Client
from appwrite.services.teams import Teams

client = Client()

(client
  .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_j_w_t('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') # Your secret JSON Web Token
)

teams = Teams(client)

result = teams.update_membership_status('[TEAM_ID]', '[INVITE_ID]', '[USER_ID]', '[SECRET]')
