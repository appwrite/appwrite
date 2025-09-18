PATCH /v1/messaging/messages/email/{messageId} HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "topics": [],
  "users": [],
  "targets": [],
  "subject": "<SUBJECT>",
  "content": "<CONTENT>",
  "draft": false,
  "html": false,
  "cc": [],
  "bcc": [],
  "scheduledAt": ,
  "attachments": []
}
