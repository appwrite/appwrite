require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
    .setproject('5df5acd0d48c2') # Your project ID
    .setkey('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
;

database = Appwrite::Database.new(client);

response = database.delete_document(collection_id: '[COLLECTION_ID]', document_id: '[DOCUMENT_ID]');

puts response