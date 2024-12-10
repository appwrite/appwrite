require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

teams = Teams.new(client)

result = teams.create(
    team_id: '<TEAM_ID>',
    name: '<NAME>',
    roles: [] # optional
)
