require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

functions = Functions.new(client)

response = functions.delete_variable(function_id: '[FUNCTION_ID]', variable_id: '[VARIABLE_ID]')

puts response.inspect