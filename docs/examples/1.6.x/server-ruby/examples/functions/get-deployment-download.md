require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_session('') # The user session to authenticate with

functions = Functions.new(client)

result = functions.get_deployment_download(
    function_id: '<FUNCTION_ID>',
    deployment_id: '<DEPLOYMENT_ID>'
)
