POST /v1/storage/buckets/{bucketId}/files HTTP/1.1
Host: cloud.appwrite.io
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Session: 
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;
X-Appwrite-JWT: &lt;YOUR_JWT&gt;
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="operations"

{ "query": "mutation { storageCreateFile(bucketId: $bucketId, fileId: $fileId, file: $file, permissions: $permissions) { id }" }, "variables": { "bucketId": "<BUCKET_ID>", "fileId": "<FILE_ID>", "file": null, "permissions": ["read("any")"] } }

--cec8e8123c05ba25
Content-Disposition: form-data; name="map"

{ "0": ["variables.file"],  }

--cec8e8123c05ba25
Content-Disposition: form-data; name="0"; filename="file.ext"

File contents

--cec8e8123c05ba25--
