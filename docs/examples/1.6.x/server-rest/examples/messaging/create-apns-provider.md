POST /v1/messaging/providers/apns HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "providerId": "<PROVIDER_ID>",
  "name": "<NAME>",
  "authKey": "<AUTH_KEY>",
  "authKeyId": "<AUTH_KEY_ID>",
  "teamId": "<TEAM_ID>",
  "bundleId": "<BUNDLE_ID>",
  "sandbox": false,
  "enabled": false
}
