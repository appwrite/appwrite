POST /v1/messaging/topics HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "topicId": "<TOPIC_ID>",
  "name": "<NAME>",
  "subscribe": ["any"]
}
