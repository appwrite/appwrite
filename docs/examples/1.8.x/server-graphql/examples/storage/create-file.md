POST /v1/storage/buckets/{bucketId}/files HTTP/1.1
Host: cloud.appwrite.io
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-Key: <YOUR_API_KEY>
X-Appwrite-JWT: <YOUR_JWT>
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
