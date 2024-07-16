POST /v1/messaging/providers/twilio HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.5.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "providerId": "<PROVIDER_ID>",
  "name": "<NAME>",
  "from": "+12065550100",
  "accountSid": "<ACCOUNT_SID>",
  "authToken": "<AUTH_TOKEN>",
  "enabled": false
}
