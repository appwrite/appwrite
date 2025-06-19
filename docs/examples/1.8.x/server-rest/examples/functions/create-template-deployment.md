POST /v1/functions/{functionId}/deployments/template HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "repository": "<REPOSITORY>",
  "owner": "<OWNER>",
  "rootDirectory": "<ROOT_DIRECTORY>",
  "version": "<VERSION>",
  "activate": false
}
