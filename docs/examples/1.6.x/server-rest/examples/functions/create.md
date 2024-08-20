POST /v1/functions HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "functionId": "<FUNCTION_ID>",
  "name": "<NAME>",
  "runtime": "node-14.5",
  "execute": ["any"],
  "events": [],
  "schedule": ,
  "timeout": 1,
  "enabled": false,
  "logging": false,
  "entrypoint": "<ENTRYPOINT>",
  "commands": "<COMMANDS>",
  "scopes": [],
  "installationId": "<INSTALLATION_ID>",
  "providerRepositoryId": "<PROVIDER_REPOSITORY_ID>",
  "providerBranch": "<PROVIDER_BRANCH>",
  "providerSilentMode": false,
  "providerRootDirectory": "<PROVIDER_ROOT_DIRECTORY>",
  "templateRepository": "<TEMPLATE_REPOSITORY>",
  "templateOwner": "<TEMPLATE_OWNER>",
  "templateRootDirectory": "<TEMPLATE_ROOT_DIRECTORY>",
  "templateVersion": "<TEMPLATE_VERSION>",
  "specification": 
}
