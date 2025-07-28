PATCH /v1/messaging/providers/mailgun/{providerId} HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "name": "<NAME>",
  "apiKey": "<API_KEY>",
  "domain": "<DOMAIN>",
  "isEuRegion": false,
  "enabled": false,
  "fromName": "<FROM_NAME>",
  "fromEmail": "email@example.com",
  "replyToName": "<REPLY_TO_NAME>",
  "replyToEmail": "<REPLY_TO_EMAIL>"
}
