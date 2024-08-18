POST /v1/functions/{functionId}/executions HTTP/1.1
Host: cloud.appwrite.io
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Session: 
X-Appwrite-JWT: &lt;YOUR_JWT&gt;
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="body"

"<BODY>"

--cec8e8123c05ba25
Content-Disposition: form-data; name="async"

false

--cec8e8123c05ba25
Content-Disposition: form-data; name="path"

"<PATH>"

--cec8e8123c05ba25
Content-Disposition: form-data; name="method"

"GET"

--cec8e8123c05ba25
Content-Disposition: form-data; name="headers"

{}

--cec8e8123c05ba25
Content-Disposition: form-data; name="scheduledAt"



--cec8e8123c05ba25--
