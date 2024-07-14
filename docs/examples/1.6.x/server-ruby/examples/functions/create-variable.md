require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

functions = Functions.new(client)

result = functions.create_variable(
    function_id: '<FUNCTION_ID>',
    key: '<KEY>',
    value: '<VALUE>'
)
