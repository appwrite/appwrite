POST /v1/account/tokens/magic-url HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>

{
  "userId": "<USER_ID>",
  "email": "email@example.com",
  "url": "https://example.com",
  "phrase": false
}
