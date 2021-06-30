
echo 'PHP Packaging...'

cp -r $(pwd)/tests/resources/functions/over-max-logs $(pwd)/tests/resources/functions/packages/over-max-logs

docker run --rm -v $(pwd)/tests/resources/functions/packages/over-max-logs:/app -w /app appwrite/env-php-8.0:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/over-max-logs/code.tar.gz $(pwd)/tests/resources/functions/over-max-logs.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/over-max-logs
