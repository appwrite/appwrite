echo 'Starting build...'

echo 'Deno 1.2...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-deno-1.2:1.0.0 ./docker/environments/deno-1.2/ --push

echo 'Deno 1.5...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-deno-1.5:1.0.0 ./docker/environments/deno-1.5/ --push

echo 'Deno 1.6...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-deno-1.6:1.0.0 ./docker/environments/deno-1.6/ --push

echo 'Node 14.5...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/ppc64le -t appwrite/env-node-14.5:1.0.0 ./docker/environments/node-14.5/ --push

echo 'Node 15.5...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/ppc64le -t appwrite/env-node-15.5:1.0.0 ./docker/environments/node-15.5/ --push

echo 'PHP 7.4...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-php-7.4:1.0.0 ./docker/environments/php-7.4/ --push

echo 'PHP 8.0...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-php-8.0:1.0.0 ./docker/environments/php-8.0/ --push

echo 'Python 3.8...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-python-3.8:1.0.0 ./docker/environments/python-3.8/ --push

echo 'Ruby 2.7...'
docker buildx build --platform linux/amd64,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-ruby-2.7:1.0.2 ./docker/environments/ruby-2.7/ --push

echo 'Ruby 3.0...'
docker buildx build --platform linux/amd64,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-ruby-3.0:1.0.0 ./docker/environments/ruby-3.0/ --push
