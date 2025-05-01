require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites.new(client)

result = sites.update(
    site_id: '<SITE_ID>',
    name: '<NAME>',
    framework: ::ANALOG,
    enabled: false, # optional
    logging: false, # optional
    timeout: 1, # optional
    install_command: '<INSTALL_COMMAND>', # optional
    build_command: '<BUILD_COMMAND>', # optional
    output_directory: '<OUTPUT_DIRECTORY>', # optional
    build_runtime: ::NODE_14_5, # optional
    adapter: ::STATIC, # optional
    fallback_file: '<FALLBACK_FILE>', # optional
    installation_id: '<INSTALLATION_ID>', # optional
    provider_repository_id: '<PROVIDER_REPOSITORY_ID>', # optional
    provider_branch: '<PROVIDER_BRANCH>', # optional
    provider_silent_mode: false, # optional
    provider_root_directory: '<PROVIDER_ROOT_DIRECTORY>', # optional
    specification: '' # optional
)
