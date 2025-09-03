POST /v1/sites/{siteId}/deployments/vcs HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "type": "branch",
  "reference": "<REFERENCE>",
  "activate": false
}
