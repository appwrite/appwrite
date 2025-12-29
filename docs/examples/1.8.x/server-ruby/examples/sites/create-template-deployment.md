require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites.new(client)

result = sites.create_template_deployment(
    site_id: '<SITE_ID>',
    repository: '<REPOSITORY>',
    owner: '<OWNER>',
    root_directory: '<ROOT_DIRECTORY>',
    type: TemplateReferenceType::BRANCH,
    reference: '<REFERENCE>',
    activate: false # optional
)
