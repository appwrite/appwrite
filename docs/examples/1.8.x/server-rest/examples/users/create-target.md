POST /v1/users/{userId}/targets HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "targetId": "<TARGET_ID>",
  "providerType": "email",
  "identifier": "<IDENTIFIER>",
  "providerId": "<PROVIDER_ID>",
  "name": "<NAME>"
}
