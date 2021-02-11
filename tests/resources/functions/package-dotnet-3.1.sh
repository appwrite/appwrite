
echo '.NET 3.1 Packaging...'

cp -r $(pwd)/tests/resources/functions/dotnet-3.1 $(pwd)/tests/resources/functions/packages/dotnet-3.1

docker run --rm -v $(pwd)/tests/resources/functions/packages/dotnet-3.1:/app -w /app mcr.microsoft.com/dotnet/sdk:3.1-alpine dotnet restore
docker run --rm -v $(pwd)/tests/resources/functions/packages/dotnet-3.1:/app -w /app mcr.microsoft.com/dotnet/sdk:3.1-alpine dotnet publish -o ./release
docker run --rm -v $(pwd)/tests/resources/functions/packages/dotnet-3.1:/app -w /app/release appwrite/env-dotnet-3.1:1.0.0 tar -zcvf ../code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/dotnet-3.1/code.tar.gz $(pwd)/tests/resources/functions/dotnet-3.1.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/dotnet-3.1
