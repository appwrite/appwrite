ENDPOINT="$APPWRITE_FUNCTION_API_ENDPOINT/users"
HEALTH_ENDPOINT="$APPWRITE_FUNCTION_API_ENDPOINT/health"
PROJECT_ID="$APPWRITE_FUNCTION_PROJECT_ID"
API_KEY="$APPWRITE_FUNCTION_API_KEY"

apk add curl

echo "KEY_FOR_TESTS=$API_KEY"

curl -v -X GET $ENDPOINT -H "x-appwrite-project: $PROJECT_ID" -H "x-appwrite-key: $API_KEY"

# Proves the always-granted health.read scope authorizes a health call
HEALTH_STATUS=$(curl -s -o /dev/null -w '%{http_code}' -X GET $HEALTH_ENDPOINT -H "x-appwrite-project: $PROJECT_ID" -H "x-appwrite-key: $API_KEY")
echo "HEALTH_STATUS_FOR_TESTS=$HEALTH_STATUS"
