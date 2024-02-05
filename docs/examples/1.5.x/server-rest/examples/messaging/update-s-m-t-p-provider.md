PATCH /v1/messaging/providers/smtp/{providerId} HTTP/1.1
Host: HOSTNAME
Content-Type: application/json
X-Appwrite-Response-Format: 1.4.0
X-Appwrite-Project: 5df5acd0d48c2
X-Appwrite-Key: 919c2d18fb5d4...a2ae413da83346ad2

{
  "name": "[NAME]",
  "host": "[HOST]",
  "port": 1,
  "username": "[USERNAME]",
  "password": "[PASSWORD]",
  "encryption": "none",
  "autoTLS": false,
  "fromName": "[FROM_NAME]",
  "fromEmail": "email@example.com",
  "replyToName": "[REPLY_TO_NAME]",
  "replyToEmail": "[REPLY_TO_EMAIL]",
  "enabled": false
}
