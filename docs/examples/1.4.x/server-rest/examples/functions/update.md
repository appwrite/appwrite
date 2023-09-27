PUT /v1/functions/{functionId} HTTP/1.1
Host: HOSTNAME
Content-Type: application/json
X-Appwrite-Response-Format: 1.4.0
X-Appwrite-Project: 5df5acd0d48c2
X-Appwrite-Key: 919c2d18fb5d4...a2ae413da83346ad2

{
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
  "providerRootDirectory": "[PROVIDER_ROOT_DIRECTORY]"
}
