POST /v1/functions HTTP/1.1
Host: HOSTNAME
Content-Type: application/json
X-Appwrite-Response-Format: 1.4.0
X-Appwrite-Project: 5df5acd0d48c2
X-Appwrite-Key: 919c2d18fb5d4...a2ae413da83346ad2

{
  "functionId": "[FUNCTION_ID]",
  "name": "[NAME]",
  "runtime": "node-18.0",
  "execute": ["any"],
  "events": [],
  "schedule": ,
  "timeout": 1,
  "enabled": false,
  "logging": false,
  "entrypoint": "[ENTRYPOINT]",
  "commands": "[COMMANDS]",
  "installationId": "[INSTALLATION_ID]",
  "providerRepositoryId": "[PROVIDER_REPOSITORY_ID]",
  "providerBranch": "[PROVIDER_BRANCH]",
  "providerSilentMode": false,
  "providerRootDirectory": "[PROVIDER_ROOT_DIRECTORY]",
  "templateRepository": "[TEMPLATE_REPOSITORY]",
  "templateOwner": "[TEMPLATE_OWNER]",
  "templateRootDirectory": "[TEMPLATE_ROOT_DIRECTORY]",
  "templateBranch": "[TEMPLATE_BRANCH]"
}
