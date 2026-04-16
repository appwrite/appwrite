PUT /v1/storage/buckets/{bucketId}/files/{fileId} HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-Key: <YOUR_API_KEY>
X-Appwrite-JWT: <YOUR_JWT>

{
  "name": "<NAME>",
  "permissions": ["read(\"any\")"]
}
