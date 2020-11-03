echo 'Starting build...'

echo 'Deno 1.2...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-deno:1.2 ./docker/environments/deno-1.2/ --push

echo 'Deno 1.5...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-deno:1.5 ./docker/environments/deno-1.5/ --push

echo 'Node 14.5...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/ppc64le -t appwrite/env-node:14.5 ./docker/environments/node-14.5/ --push

echo 'PHP 7.4...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-php:7.4 ./docker/environments/php-7.4/ --push

echo 'Python 3.8...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-python:3.8 ./docker/environments/python-3.8/ --push

echo 'Ruby 2.7...'
docker buildx build --platform linux/amd64,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-ruby:2.7 ./docker/environments/ruby-2.7/ --push
