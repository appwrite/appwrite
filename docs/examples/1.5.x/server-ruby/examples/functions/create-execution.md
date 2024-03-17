require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

functions = Functions.new(client)

result = functions.create_execution(
    function_id: '<FUNCTION_ID>',
    body: '<BODY>', # optional
    async: false, # optional
    path: '<PATH>', # optional
    method: ExecutionMethod::GET, # optional
    headers: {} # optional
)
