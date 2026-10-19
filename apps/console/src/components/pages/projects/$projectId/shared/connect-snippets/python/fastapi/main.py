from appwrite.services.project import Project
from appwrite_client import client
from fastapi import FastAPI

app = FastAPI()
project = Project(client)


@app.patch("/v1/policies")
def update_policies():
    return project.update_password_strength_policy(
        min=8,
        uppercase=True,
        number=True,
        symbols=True
    )


@app.get("/v1/policies")
def list_policies():
    return project.list_policies()
