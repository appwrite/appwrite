require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

functions = Functions.new(client)

result = functions.get_execution(
    function_id: '<FUNCTION_ID>',
    execution_id: '<EXECUTION_ID>'
)
