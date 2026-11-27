import json
import os

from django.http import HttpResponse, JsonResponse
from django.views.decorators.csrf import csrf_exempt


# GET / answers with the request; events and schedules arrive as POST /, told
# apart by x-appwrite-trigger.
@csrf_exempt
def root(request):
    if request.method == "POST":
        body = json.loads(request.body or b"{}")
        print(
            f"trigger={request.headers.get('x-appwrite-trigger')} "
            f"event={request.headers.get('x-appwrite-event')} id={body.get('$id', '')}"
        )
        return HttpResponse("")
    return JsonResponse({"framework": "django", "method": request.method, "path": request.path})


@csrf_exempt
def echo(request):
    response = JsonResponse(
        {"body": json.loads(request.body), "header": request.headers.get("x-test")},
        status=201,
    )
    response["x-echo"] = "yes"
    return response


def env(request):
    print("django log line")
    return JsonResponse({"functionId": os.environ.get("APPWRITE_FUNCTION_ID"), "port": os.environ.get("PORT")})
