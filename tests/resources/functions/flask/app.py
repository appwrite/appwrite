# A plain Flask app with no Appwrite code, run as a function with the `flask`
# framework: the runtime serves its `app` with gunicorn, so it never calls app.run().
import os

from flask import Flask, jsonify, request

app = Flask(__name__)


@app.get("/")
def root():
    return jsonify(framework="flask", method=request.method, path=request.path)


# Events and schedules arrive here, told apart by x-appwrite-trigger.
@app.post("/")
def trigger():
    body = request.get_json(silent=True) or {}
    print(
        f"trigger={request.headers.get('x-appwrite-trigger')} "
        f"event={request.headers.get('x-appwrite-event')} id={body.get('$id', '')}"
    )
    return ""


@app.post("/echo")
def echo():
    response = jsonify(body=request.get_json(), header=request.headers.get("x-test"))
    response.status_code = 201
    response.headers["x-echo"] = "yes"
    return response


@app.get("/env")
def env():
    print("flask log line")
    return jsonify(functionId=os.environ.get("APPWRITE_FUNCTION_ID"), port=os.environ.get("PORT"))
