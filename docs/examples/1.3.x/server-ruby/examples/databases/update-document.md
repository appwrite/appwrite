require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

databases = Databases.new(client)

response = databases.update_document(database_id: '[DATABASE_ID]', collection_id: '[COLLECTION_ID]', document_id: '[DOCUMENT_ID]')

puts response.inspect