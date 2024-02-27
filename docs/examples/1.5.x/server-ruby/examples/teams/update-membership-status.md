require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

teams = Teams.new(client)

result = teams.update_membership_status(
    team_id: '<TEAM_ID>',
    membership_id: '<MEMBERSHIP_ID>',
    user_id: '<USER_ID>',
    secret: '<SECRET>'
)
