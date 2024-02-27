require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

storage = Storage.new(client)

result = storage.get_file_preview(
    bucket_id: '<BUCKET_ID>',
    file_id: '<FILE_ID>',
    width: 0, # optional
    height: 0, # optional
    gravity: ImageGravity::CENTER, # optional
    quality: 0, # optional
    border_width: 0, # optional
    border_color: '', # optional
    border_radius: 0, # optional
    opacity: 0, # optional
    rotation: -360, # optional
    background: '', # optional
    output: ImageFormat::JPG # optional
)
