echo 'Starting Deno 1.2 build...'

docker build --tag appwrite/env-deno:1.2 .

echo 'Pushing Deno 1.2 build to registry...'

docker push appwrite/env-deno:1.2