require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

storage = Storage.new(client)

result = storage.create_bucket(
    bucket_id: '<BUCKET_ID>',
    name: '<NAME>',
    permissions: ["read("any")"], # optional
    file_security: false, # optional
    enabled: false, # optional
    maximum_file_size: 1, # optional
    allowed_file_extensions: [], # optional
    compression: ::NONE, # optional
    encryption: false, # optional
    antivirus: false # optional
)
