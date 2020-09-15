echo 'Starting Ruby 2.7 build...'

docker build --tag appwrite/env-ruby:2.7 .

echo 'Pushing Ruby 2.7 build to registry...'

docker push appwrite/env-ruby:2.7