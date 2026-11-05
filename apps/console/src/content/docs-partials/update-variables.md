{% info title="Applying changes" %}

After editing your `docker-compose.yml` or `.env` files, you will need to recreate your Appwrite stack by running the following compose command in your terminal.

```sh
docker compose up -d
```

You can verify if the changes have been successfully applied by running this command:

```sh
docker compose exec appwrite vars
```

{% /info %}
