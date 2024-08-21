POST /v1/teams/{teamId}/memberships HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Session: 
X-Appwrite-JWT: &lt;YOUR_JWT&gt;

{
  "email": "email@example.com",
  "userId": "<USER_ID>",
  "phone": "+12065550100",
  "roles": [],
  "url": "https://example.com",
  "name": "<NAME>"
}
