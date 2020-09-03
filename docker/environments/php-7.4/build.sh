echo 'Starting PHP 7.4 build...'

docker build --tag appwrite/env-php:7.4 .

echo 'Pushing PHP 7.4 build to registry...'

docker push appwrite/env-php:7.4