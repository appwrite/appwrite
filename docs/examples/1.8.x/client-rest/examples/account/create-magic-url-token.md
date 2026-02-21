POST /v1/account/tokens/magic-url HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-JWT: <YOUR_JWT>

{
  "userId": "<USER_ID>",
  "email": "email@example.com",
  "url": "https://example.com",
  "phrase": false
}
