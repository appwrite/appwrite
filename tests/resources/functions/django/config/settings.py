# A plain Django project with no Appwrite code, run as a function with the
# `django` framework: the runtime finds WSGI_APPLICATION from manage.py and serves
# it with gunicorn. No database and no apps; every host allowed.
SECRET_KEY = "appwrite-functions-test"
DEBUG = False
ALLOWED_HOSTS = ["*"]

ROOT_URLCONF = "config.urls"
WSGI_APPLICATION = "config.wsgi.application"

INSTALLED_APPS = []
MIDDLEWARE = []
DATABASES = {}

USE_TZ = True
