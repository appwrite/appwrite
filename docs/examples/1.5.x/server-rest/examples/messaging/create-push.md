POST /v1/messaging/messages/push HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "messageId": "<MESSAGE_ID>",
  "title": "<TITLE>",
  "body": "<BODY>",
  "topics": [],
  "users": [],
  "targets": [],
  "data": {},
  "action": "<ACTION>",
  "image": "[ID1:ID2]",
  "icon": "<ICON>",
  "sound": "<SOUND>",
  "color": "<COLOR>",
  "tag": "<TAG>",
  "badge": 0,
  "draft": false,
  "scheduledAt": ,
  "contentAvailable": false,
  "critical": false,
  "priority": "normal"
}
