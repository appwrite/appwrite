require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

storage = Storage.new(client)

result = storage.list_files(
    bucket_id: '<BUCKET_ID>',
    queries: [], # optional
    search: '<SEARCH>' # optional
)
