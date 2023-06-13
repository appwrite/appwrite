require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_jwt('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') # Your secret JSON Web Token

teams = Teams.new(client)

response = teams.update_membership_status(team_id: '[TEAM_ID]', membership_id: '[MEMBERSHIP_ID]', user_id: '[USER_ID]', secret: '[SECRET]')

puts response.inspect