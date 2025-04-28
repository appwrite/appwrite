POST /v1/storage/buckets HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "bucketId": "<BUCKET_ID>",
  "name": "<NAME>",
  "permissions": ["read(\"any\")"],
  "fileSecurity": false,
  "enabled": false,
  "maximumFileSize": 1,
  "allowedFileExtensions": [],
  "compression": "none",
  "encryption": false,
  "antivirus": false
}
