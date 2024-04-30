require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

storage = Storage.new(client)

result = storage.create_file(
    bucket_id: '<BUCKET_ID>',
    file_id: '<FILE_ID>',
    file: InputFile.from_path('dir/file.png'),
    permissions: ["read("any")"] # optional
)
