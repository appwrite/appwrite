POST /v1/storage/buckets HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

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
