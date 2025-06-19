POST /v1/messaging/providers/smtp HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "providerId": "<PROVIDER_ID>",
  "name": "<NAME>",
  "host": "<HOST>",
  "port": 1,
  "username": "<USERNAME>",
  "password": "<PASSWORD>",
  "encryption": "none",
  "autoTLS": false,
  "mailer": "<MAILER>",
  "fromName": "<FROM_NAME>",
  "fromEmail": "email@example.com",
  "replyToName": "<REPLY_TO_NAME>",
  "replyToEmail": "email@example.com",
  "enabled": false
}
