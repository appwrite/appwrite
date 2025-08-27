require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

functions = Functions.new(client)

result = functions.create_deployment(
    function_id: '<FUNCTION_ID>',
    code: InputFile.from_path('dir/file.png'),
    activate: false,
    entrypoint: '<ENTRYPOINT>', # optional
    commands: '<COMMANDS>' # optional
)
