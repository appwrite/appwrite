require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

storage = Storage.new(client)

response = storage.get_file_preview(bucket_id: '[BUCKET_ID]', file_id: '[FILE_ID]')

puts response.inspect