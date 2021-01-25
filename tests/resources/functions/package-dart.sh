
echo 'Dart Packaging...'

cp -r $(pwd)/tests/resources/functions/dart $(pwd)/tests/resources/functions/packages/dart

docker run --rm -v $(pwd)/tests/resources/functions/packages/dart:/app -w /app appwrite/env-dart-2.10:1.0.0 ls
docker run --rm -v $(pwd)/tests/resources/functions/packages/dart:/app -w /app --env PUB_CACHE=./.appwrite appwrite/env-dart-2.10:1.0.0 pub get
docker run --rm -v $(pwd)/tests/resources/functions/packages/dart:/app -w /app --env PUB_CACHE=./.appwrite appwrite/env-dart-2.10:1.0.0 pub get --offline

docker run --rm -v $(pwd)/tests/resources/functions/packages/dart:/app -w /app appwrite/env-dart-2.10:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/dart/code.tar.gz $(pwd)/tests/resources/functions/dart.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/dart
