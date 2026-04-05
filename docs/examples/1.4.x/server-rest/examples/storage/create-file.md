POST /v1/storage/buckets/{bucketId}/files HTTP/1.1
Host: HOSTNAME
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.4.0
X-Appwrite-Project: 5df5acd0d48c2
X-Appwrite-Key: 919c2d18fb5d4...a2ae413da83346ad2
X-Appwrite-JWT: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="fileId"

"[FILE_ID]"

--cec8e8123c05ba25
Content-Disposition: form-data; name="file"

cf 94 84 24 8d c4 91 10 0f dc 54 26 6c 8e 4b bc 
e8 ee 55 94 29 e7 94 89 19 26 28 01 26 29 3f 16...

--cec8e8123c05ba25
Content-Disposition: form-data; name="permissions[]"

["read(\"any\")"]

--cec8e8123c05ba25--
