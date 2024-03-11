POST /v1/messaging/providers/smtp HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.5.0
X-Appwrite-Project: 5df5acd0d48c2
X-Appwrite-Key: 919c2d18fb5d4...a2ae413da83346ad2

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
