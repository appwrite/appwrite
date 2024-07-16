POST /v1/messaging/messages/push HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.5.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

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
  "badge": "<BADGE>",
  "draft": false,
  "scheduledAt": 
}
