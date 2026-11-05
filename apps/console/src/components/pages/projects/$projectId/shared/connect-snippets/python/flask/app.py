from appwrite.services.project import Project
from appwrite_client import client
from flask import Flask, jsonify

app = Flask(__name__)
project = Project(client)


@app.patch("/v1/policies")
def update_policies():
    policy = project.update_password_strength_policy(
        min=8,
        uppercase=True,
        number=True,
        symbols=True
    )
    return jsonify(policy.model_dump(by_alias=True))


@app.get("/v1/policies")
def list_policies():
    policies = project.list_policies()
    return jsonify(policies.model_dump(by_alias=True))


if __name__ == "__main__":
    app.run(port=5000)
