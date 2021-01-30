
echo '.NET 5.0 Packaging...'

cp -r $(pwd)/tests/resources/functions/dotnet-5.0 $(pwd)/tests/resources/functions/packages/dotnet-5.0

docker run --rm -v $(pwd)/tests/resources/functions/packages/dotnet-5.0:/app -w /app mcr.microsoft.com/dotnet/sdk:5.0-alpine dotnet restore
docker run --rm -v $(pwd)/tests/resources/functions/packages/dotnet-5.0:/app -w /app mcr.microsoft.com/dotnet/sdk:5.0-alpine dotnet publish -o ./release
docker run --rm -v $(pwd)/tests/resources/functions/packages/dotnet-5.0:/app -w /app/release appwrite/env-dotnet-5.0:1.0.0 tar -zcvf ../code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/dotnet-5.0/code.tar.gz $(pwd)/tests/resources/functions/dotnet-5.0.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/dotnet-5.0
