echo 'Starting Node 14.5 build...'

docker build --tag appwrite/env-node:14.5 .

echo 'Pushing Node 14.5 build to registry...'

docker push appwrite/env-node:14.5