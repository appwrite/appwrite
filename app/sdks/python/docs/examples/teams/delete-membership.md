from appwrite.client import Client
from appwrite.services.teams import Teams

client = Client()

(client
  .set_project('')
  .set_key('')
)

teams = Teams(client)

result = teams.delete_membership('[TEAM_ID]', '[INVITE_ID]')
