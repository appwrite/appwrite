echo 'Starting Base build...'

docker build --tag appwrite/env-base:1.0 .

echo 'Pushing Base build to registry...'

docker push appwrite/env-base:1.0