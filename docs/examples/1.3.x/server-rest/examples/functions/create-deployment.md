POST /v1/functions/{functionId}/deployments HTTP/1.1
Host: HOSTNAME
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.0.0
X-Appwrite-Project: 5df5acd0d48c2
X-Appwrite-Key: 919c2d18fb5d4...a2ae413da83346ad2
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="entrypoint"

"[ENTRYPOINT]"

--cec8e8123c05ba25
Content-Disposition: form-data; name="code"

cf 94 84 24 8d c4 91 10 0f dc 54 26 6c 8e 4b bc 
e8 ee 55 94 29 e7 94 89 19 26 28 01 26 29 3f 16...

--cec8e8123c05ba25
Content-Disposition: form-data; name="activate"

false

--cec8e8123c05ba25--
