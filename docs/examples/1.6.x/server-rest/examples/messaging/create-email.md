POST /v1/messaging/messages/email HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "messageId": "<MESSAGE_ID>",
  "subject": "<SUBJECT>",
  "content": "<CONTENT>",
  "topics": [],
  "users": [],
  "targets": [],
  "cc": [],
  "bcc": [],
  "attachments": [],
  "draft": false,
  "html": false,
  "scheduledAt": 
}
