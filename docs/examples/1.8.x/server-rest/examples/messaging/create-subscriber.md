POST /v1/messaging/topics/{topicId}/subscribers HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-JWT: <YOUR_JWT>
X-Appwrite-Session: 
X-Appwrite-Key: <YOUR_API_KEY>

{
  "subscriberId": "<SUBSCRIBER_ID>",
  "targetId": "<TARGET_ID>"
}
