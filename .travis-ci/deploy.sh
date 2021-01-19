# echo 'Nothing to deploy right now.'
docker buildx build --platform linux/amd64,linux/arm64,linux/386,linux/ppc64le -t appwrite/env-ruby-3.0:1.0.0 ./docker/environments/ruby-3.0/ --push