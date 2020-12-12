
echo 'Deno Packaging...'

cp -r $(pwd)/tests/resources/functions/deno $(pwd)/tests/resources/functions/packages/deno

docker run --rm -v $(pwd)/tests/resources/functions/packages/deno:/app -w /app appwrite/env-deno-1.5:1.0.0 ls
docker run --rm --env DENO_DIR=./.appwrite -v $(pwd)/tests/resources/functions/packages/deno:/app -w /app appwrite/env-deno-1.5:1.0.0 deno cache index.ts
docker run --rm -v $(pwd)/tests/resources/functions/packages/deno:/app -w /app appwrite/env-deno-1.5:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/deno/code.tar.gz $(pwd)/tests/resources/functions/deno.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/deno
