echo 'Starting Python 3.8 build...'

docker build --tag appwrite/env-python:3.8 .

echo 'Pushing Python 3.8 build to registry...'

docker push appwrite/env-python:3.8