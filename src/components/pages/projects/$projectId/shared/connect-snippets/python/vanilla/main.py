import json
import os
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint(os.environ.get("APPWRITE_ENDPOINT"))
client.set_project(os.environ.get("APPWRITE_PROJECT_ID"))
client.set_key(os.environ.get("APPWRITE_API_KEY"))

project = Project(client)

policy = project.update_password_strength_policy(
    min=8,
    uppercase=True,
    number=True,
    symbols=True
)

print(json.dumps(policy.model_dump(by_alias=True), indent=2))

policies = project.list_policies()

print(json.dumps(policies.model_dump(by_alias=True), indent=2))
