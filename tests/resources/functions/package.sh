echo 'Python Packaging...'

cp -r $(pwd)/tests/resources/functions/python $(pwd)/tests/resources/functions/packages/python

docker run --rm -v $(pwd)/tests/resources/functions/packages/python:/app -w /app --env PIP_TARGET=./.appwrite appwrite/env-python-3.8:1.0.0 pip install -r ./requirements.txt --upgrade --ignore-installed

docker run --rm -v $(pwd)/tests/resources/functions/packages/python:/app -w /app appwrite/env-python-3.8:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/python/code.tar.gz $(pwd)/tests/resources/functions/python.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/python

echo 'PHP Packaging...'

cp -r $(pwd)/tests/resources/functions/php $(pwd)/tests/resources/functions/packages/php

docker run --rm -v $(pwd)/tests/resources/functions/packages/php:/app -w /app composer:2.0 install --ignore-platform-reqs

docker run --rm -v $(pwd)/tests/resources/functions/packages/php:/app -w /app appwrite/env-php-8.0:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/php/code.tar.gz $(pwd)/tests/resources/functions/php.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/php