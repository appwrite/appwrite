
echo 'Python Packaging...'

cp -r $(pwd)/tests/resources/functions/python $(pwd)/tests/resources/functions/packages/python

docker run --rm -v $(pwd)/tests/resources/functions/packages/python:/app -w /app --env PIP_TARGET=./.appwrite appwrite/env-python-3.8:1.0.0 pip install -r ./requirements.txt --upgrade --ignore-installed

docker run --rm -v $(pwd)/tests/resources/functions/packages/python:/app -w /app appwrite/env-python-3.8:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/python/code.tar.gz $(pwd)/tests/resources/functions/python.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/python
