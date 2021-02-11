
echo 'Timeout Packaging...'

cp -r $(pwd)/tests/resources/functions/timeout $(pwd)/tests/resources/functions/packages/timeout

docker run --rm -v $(pwd)/tests/resources/functions/packages/timeout:/app -w /app appwrite/env-php-8.0:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/timeout/code.tar.gz $(pwd)/tests/resources/functions/timeout.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/timeout