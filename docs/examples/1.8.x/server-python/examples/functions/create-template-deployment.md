from appwrite.client import Client
from appwrite.services.functions import Functions
from appwrite.enums import TemplateReferenceType

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

functions = Functions(client)

result = functions.create_template_deployment(
    function_id = '<FUNCTION_ID>',
    repository = '<REPOSITORY>',
    owner = '<OWNER>',
    root_directory = '<ROOT_DIRECTORY>',
    type = TemplateReferenceType.COMMIT,
    reference = '<REFERENCE>',
    activate = False # optional
)
