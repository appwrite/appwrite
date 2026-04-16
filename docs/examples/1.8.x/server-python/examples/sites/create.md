from appwrite.client import Client
from appwrite.services.sites import Sites
from appwrite.enums import Framework
from appwrite.enums import BuildRuntime
from appwrite.enums import Adapter

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites(client)

result = sites.create(
    site_id = '<SITE_ID>',
    name = '<NAME>',
    framework = Framework.ANALOG,
    build_runtime = BuildRuntime.NODE_14_5,
    enabled = False, # optional
    logging = False, # optional
    timeout = 1, # optional
    install_command = '<INSTALL_COMMAND>', # optional
    build_command = '<BUILD_COMMAND>', # optional
    output_directory = '<OUTPUT_DIRECTORY>', # optional
    adapter = Adapter.STATIC, # optional
    installation_id = '<INSTALLATION_ID>', # optional
    fallback_file = '<FALLBACK_FILE>', # optional
    provider_repository_id = '<PROVIDER_REPOSITORY_ID>', # optional
    provider_branch = '<PROVIDER_BRANCH>', # optional
    provider_silent_mode = False, # optional
    provider_root_directory = '<PROVIDER_ROOT_DIRECTORY>', # optional
    specification = '' # optional
)
