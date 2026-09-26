# Adding New Storage Adapter

This document is a part of Utopia PHP contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/utopia-php/monorepo/blob/main/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## Getting started

Storage adapters help us use various storage services to store our data. As of writing this guide, we already support Local storage, [AWS S3](https://aws.amazon.com/s3/) storage and [DigitalOcean Spaces](https://www.digitalocean.com/products/spaces/) storage.

## 1. Prerequisites

This library is developed in the [utopia-php monorepo](https://github.com/utopia-php/monorepo) — `utopia-php/storage` is a read-only mirror. Fork the monorepo, clone your fork, and create a `feat-XXX-YYY-storage-adapter` branch from `main`, where `XXX` is the issue ID and `YYY` the storage adapter name. The library lives in `packages/storage`.

## 2. Implement new storage adapter

### 2.1 Add new adapter and implement it

In order to start implementing new storage adapter, add new file inside `packages/storage/src/Storage/Device/YYY.php` where `YYY` is the name of the storage provider in `PascalCase`. Inside the file you should create a class that extends the basic `Device` abstract class. Note that the class name should start with a capital letter, as PHP FIG standards suggest.

Always use properly named environment variables if any credentials are required.

### 2.2. Introduce new device type
Add a case for the new device to the `DeviceType` enum in `src/Storage/DeviceType.php` alongside the existing cases, and return it from your adapter's `getType()` method.

## 3. Test your adapter

After you finish adding your new adapter, you need to ensure that it is usable. Use your newly created adapter to make some sample requests to your storage service. 

Great! You're almost there. You can now move onto writing some tests for your Adapter!  

### 3.1. Introduce new device tests
Add tests for the newly added device adapter inside `tests/Storage/Device`. Use the existing adapter tests as a reference. The test file and class should be properly named `<Adapter class name>Test.php` and class should be `<Adapter class name>Test`

### 3.2. Run and verify tests
Run tests using `bin/monorepo test storage` from the monorepo root and verify that everything is working correctly.

If everything goes well, raise a pull request and be ready to respond to any feedback which can arise during our code review.

## 4. Raise a pull request

First of all, commit the changes with the message `Added YYY Storage adapter` and push the branch to your fork of the monorepo. If you visit your fork on GitHub, you will see a new alert saying you are ready to submit a pull request against `utopia-php/monorepo`. Follow the steps GitHub provides, and at the end, you will have your pull request submitted.

## 🤕 Stuck ?
If you need any help with the contribution, feel free to head over to [our discord channel](https://appwrite.io/discord) and we'll be happy to help you out.
