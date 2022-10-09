# Adding a New Storage Adapter

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting Started

### Agenda

Storage providers help us use various storage services to store our Appwrite data. As of the writing of these lines we already support Local storage, [AWS S3](https://aws.amazon.com/s3/) storage and [Digitalocean Spaces](https://www.digitalocean.com/products/spaces/) storage.

As the storage library is separated into [utopia-php/storage](https://github.com/utopia-php/storage), adding a new storage adapter will consist of two phases. First adding and implementing the new adapter in the [utopia-php/storage](https://github.com/utopia-php/storage) and then adding support to the new storage adapter in Appwrite.

### Phase 1
In phase 1, we will introduce and implement the new device adapter in [utopia-php/storage](https://github.com/utopia-php/storage) library.

### Add new adapter
Add a new storage adapter inside the `src/Storage/Device/` folder. Use one of the existing ones as a reference. The new adapter class should extend `Device` class and implement all the required methods.

Note that the class name should start with a capital letter as PHP FIG standards suggest.

Always use properly named environment variables if any credentials are required.

### Introduce new device constant
Introduce newly added device constant in `src/Storage/Storage.php` alongside existing device constants. The device constant should start with `const DEVICE_<name of device>` as the existing ones.

### Introduce new device tests
Add tests for the newly added device adapter inside `tests/Storage/Device`. Use the existing adapter tests as a reference. The test file and class should be properly named `<Adapter class name>Test.php` and class should be `<Adapter class name>Test`

### Run and verify tests
Run tests using `vendor/bin/phpunit --configuration phpunit.xml` and verify that everything is working correctly.

If everything goes well, create a new pull request in [utopia-php/storage](https://github.com/utopia-php/storage) library.

### Phase 2
In this phase we will add support to the new storage adapter in Appwrite.

* Note for this to happen, your PR in the first phase should have been merged and a new version of [utopia-php/storage](https://github.com/utopia-php/storage) library released.

### Upgrade the utopia-php/storage dependency
Upgrade the utopia-php/storage dependency in `composer.json` file.

### Introduce new environment variables
Introduce new environment variables if the adaptor requires new configuration information or to pass in credentials. The storage environment variables are prefixed as `_APP_STORAGE_DEVICE`. Please read [Adding Environment Variables]() guidelines in order to properly introduce new environment variables.

### Implement the device case
In `app/controllers/shared/api.php` inside init function, there are `switch/case` statements for each supported storage device. Implement the instantiation of your device type for your device case. The device cases are the device constants listed in the `uptopa-php/storage/Storage` class.

### Test and verify everything works
To test you can switch to your newly added device using `_APP_STORAGE_DEVICE` environment variable. Then run `docker compose build && docker compose up -d` in order to build the containers with updated changes. Once the containers are running, login to Appwrite console and create a project. Then in the storage section, try to upload, preview, delete files.

If everything goes well, initiate a pull request to appwrite repository.
