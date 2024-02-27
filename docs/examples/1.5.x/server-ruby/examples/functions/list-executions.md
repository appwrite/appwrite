require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

functions = Functions.new(client)

result = functions.list_executions(
    function_id: '<FUNCTION_ID>',
    queries: [], # optional
    search: '<SEARCH>' # optional
)
