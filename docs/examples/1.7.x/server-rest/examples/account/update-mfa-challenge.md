PUT /v1/account/mfa/challenges HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-JWT: <YOUR_JWT>

{
  "challengeId": "<CHALLENGE_ID>",
  "otp": "<OTP>"
}
