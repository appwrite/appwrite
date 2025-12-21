from appwrite.client import Client
from appwrite.services.sites import Sites
from appwrite.enums import TemplateReferenceType

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites(client)

result = sites.create_template_deployment(
    site_id = '<SITE_ID>',
    repository = '<REPOSITORY>',
    owner = '<OWNER>',
    root_directory = '<ROOT_DIRECTORY>',
    type = TemplateReferenceType.BRANCH,
    reference = '<REFERENCE>',
    activate = False # optional
)
