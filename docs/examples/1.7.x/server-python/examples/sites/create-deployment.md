from appwrite.client import Client
from appwrite.services.sites import Sites
from appwrite.input_file import InputFile

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites(client)

result = sites.create_deployment(
    site_id = '<SITE_ID>',
    code = InputFile.from_path('file.png'),
    activate = False,
    install_command = '<INSTALL_COMMAND>', # optional
    build_command = '<BUILD_COMMAND>', # optional
    output_directory = '<OUTPUT_DIRECTORY>' # optional
)
