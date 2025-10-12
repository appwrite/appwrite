PATCH /v1/messaging/messages/push/{messageId} HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "topics": [],
  "users": [],
  "targets": [],
  "title": "<TITLE>",
  "body": "<BODY>",
  "data": {},
  "action": "<ACTION>",
  "image": "<ID1:ID2>",
  "icon": "<ICON>",
  "sound": "<SOUND>",
  "color": "<COLOR>",
  "tag": "<TAG>",
  "badge": 0,
  "draft": false,
  "scheduledAt": "",
  "contentAvailable": false,
  "critical": false,
  "priority": "normal"
}
