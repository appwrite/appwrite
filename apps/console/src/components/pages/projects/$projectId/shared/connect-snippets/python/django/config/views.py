from appwrite.services.project import Project
from django.http import JsonResponse
from django.views.decorators.csrf import csrf_exempt

from .appwrite import client

project = Project(client)


@csrf_exempt
def policies(request):
    if request.method == "PATCH":
        policy = project.update_password_strength_policy(
            min=8,
            uppercase=True,
            number=True,
            symbols=True
        )
        return JsonResponse(policy.model_dump(by_alias=True))

    policies = project.list_policies()
    return JsonResponse(policies.model_dump(by_alias=True))
