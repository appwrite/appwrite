POST /v1/sites HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "siteId": "<SITE_ID>",
  "name": "<NAME>",
  "framework": "analog",
  "enabled": false,
  "logging": false,
  "timeout": 1,
  "installCommand": "<INSTALL_COMMAND>",
  "buildCommand": "<BUILD_COMMAND>",
  "outputDirectory": "<OUTPUT_DIRECTORY>",
  "buildRuntime": "node-14.5",
  "adapter": "static",
  "installationId": "<INSTALLATION_ID>",
  "fallbackFile": "<FALLBACK_FILE>",
  "providerRepositoryId": "<PROVIDER_REPOSITORY_ID>",
  "providerBranch": "<PROVIDER_BRANCH>",
  "providerSilentMode": false,
  "providerRootDirectory": "<PROVIDER_ROOT_DIRECTORY>",
  "specification": 
}
