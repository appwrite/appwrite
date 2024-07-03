require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

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
