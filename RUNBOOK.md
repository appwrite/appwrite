# RUNBOOK

Common actions and related commands.

## Update PHP Packages

0) Checkout a feature branch

```sh
git checkout -b [[BRANCH_NAME]]
```

1) Start a Composer container mounting the project.

```sh
docker run -it --rm -v .:/app composer:2.0 sh
```

1) Inside the container run the update command

```sh
composer update --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist
```

3) Exit the container, update the CHANGELOG.md

```sh
giut a
```

4) Commit and Push

```sh
git add . && git commit -m "YOUR_MESSAGE_HERE" && git push origin
```
