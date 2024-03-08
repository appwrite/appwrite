from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

functions = Functions(client)

result = functions.update(
    function_id = '<FUNCTION_ID>',
    name = '<NAME>',
    runtime = .NODE_14_5, # optional
    execute = ["any"], # optional
    events = [], # optional
    schedule = '', # optional
    timeout = 1, # optional
    enabled = False, # optional
    logging = False, # optional
    entrypoint = '<ENTRYPOINT>', # optional
    commands = '<COMMANDS>', # optional
    installation_id = '<INSTALLATION_ID>', # optional
    provider_repository_id = '<PROVIDER_REPOSITORY_ID>', # optional
    provider_branch = '<PROVIDER_BRANCH>', # optional
    provider_silent_mode = False, # optional
    provider_root_directory = '<PROVIDER_ROOT_DIRECTORY>' # optional
)
