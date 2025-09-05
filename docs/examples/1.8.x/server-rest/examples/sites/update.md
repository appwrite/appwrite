PUT /v1/sites/{siteId} HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
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
  "fallbackFile": "<FALLBACK_FILE>",
  "installationId": "<INSTALLATION_ID>",
  "providerRepositoryId": "<PROVIDER_REPOSITORY_ID>",
  "providerBranch": "<PROVIDER_BRANCH>",
  "providerSilentMode": false,
  "providerRootDirectory": "<PROVIDER_ROOT_DIRECTORY>",
  "specification": ""
}
