require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
;

teams = Appwrite::Teams.new(client);

response = teams.create_membership(team_id: '[TEAM_ID]', email: 'email@example.com', roles: [], url: 'https://example.com');

puts response