
echo 'Ruby Packaging...'

cp -r $(pwd)/tests/resources/functions/ruby $(pwd)/tests/resources/functions/packages/ruby

docker run --rm -v $(pwd)/tests/resources/functions/packages/ruby:/app -w /app --env GEM_HOME=./.appwrite appwrite/env-ruby-2.7:1.0.2 bundle install

docker run --rm -v $(pwd)/tests/resources/functions/packages/ruby:/app -w /app appwrite/env-ruby-2.7:1.0.2 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/ruby/code.tar.gz $(pwd)/tests/resources/functions/ruby.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/ruby
