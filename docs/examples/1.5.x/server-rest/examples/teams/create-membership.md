POST /v1/teams/{teamId}/memberships HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-Key: <YOUR_API_KEY>
X-Appwrite-JWT: <YOUR_JWT>

{
  "email": "email@example.com",
  "userId": "<USER_ID>",
  "phone": "+12065550100",
  "roles": [],
  "url": "https://example.com",
  "name": "<NAME>"
}
