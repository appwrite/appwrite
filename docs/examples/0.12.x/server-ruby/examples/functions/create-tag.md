require 'appwrite'

client = Appwrite::Client.new

client
    .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

functions = Appwrite::Functions.new(client)

response = functions.create_tag(function_id: '[FUNCTION_ID]', command: '[COMMAND]', code: File.new())

puts response.inspect