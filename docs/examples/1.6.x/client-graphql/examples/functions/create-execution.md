POST /v1/functions/{functionId}/executions HTTP/1.1
Host: cloud.appwrite.io
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Session: 
X-Appwrite-JWT: &lt;YOUR_JWT&gt;
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="operations"

{ "query": "mutation { functionsCreateExecution(functionId: $functionId, body: $body, async: $async, path: $path, method: $method, headers: $headers, scheduledAt: $scheduledAt) { id }" }, "variables": { "functionId": "<FUNCTION_ID>", "body": "<BODY>", "async": false, "path": "<PATH>", "method": "GET", "headers": "{}", "scheduledAt": "" } }

--cec8e8123c05ba25
Content-Disposition: form-data; name="map"

{  }

--cec8e8123c05ba25--
