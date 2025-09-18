PATCH /v1/messaging/providers/apns/{providerId} HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "name": "<NAME>",
  "enabled": false,
  "authKey": "<AUTH_KEY>",
  "authKeyId": "<AUTH_KEY_ID>",
  "teamId": "<TEAM_ID>",
  "bundleId": "<BUNDLE_ID>",
  "sandbox": false
}
