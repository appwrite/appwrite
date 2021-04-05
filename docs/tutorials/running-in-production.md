# Running in Production

This tutorial will cover some basic concepts and best practices for running a production Appwrite server. This tutorial assumes you have some basic knowledge of Docker and Docker Compose command-line tools.

## Error Reporting

By default, Appwrite installation comes with error debugging turned on, We do this to help new users solve issues and report problems while still in development mode.

In production, it is highly recommended to turn error reporting off. To do so, you have to change the Appwrite container environment variable **_APP_ENV** value from **development** to **production**.

## Enable Encryption

By default, the Appwrite setup doesn’t come with a uniquely generated encryption key. This key is used to store your files and sensitive data like webhook passwords or API keys in a safe way. To take advantage of this feature, you must generate a unique key and set it as the value of the **_APP_OPENSSL_KEY_V1** environment variable.

Make sure to keep this key in a safe place and never make it publicly accessible. There are many [online resources]([https://www.freecodecamp.org/news/how-to-securely-store-api-keys-4ff3ea19ebda/](https://www.freecodecamp.org/news/how-to-securely-store-api-keys-4ff3ea19ebda/)) with methods of keeping your secret keys safe in your servers.

## Limit Access to your Console

By default, anyone can signup for your Appwrite server, create projects, and use your computing power. While this is great for testing around or running your Appwrite service in a network isolated environment, it is highly not recommended for public production use.

We are providing three different methods to limit access to your Appwrite console. You can either set a list of [IPs]([https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#_app_console_whitelist_ips](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#_app_console_whitelist_ips)), [email address]([https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#_app_console_whitelist_emails](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#_app_console_whitelist_emails)) or [email domains]([https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#_app_console_whitelist_domains](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#_app_console_whitelist_domains)) which users are allowed to signup from. You can choose one or multiple restriction methods to apply.

## Scaling

Appwrite was built with scalability in mind. Appwrite can potentially scale horizontally infinitely with no known limitations.

Appwrite uses a few containers to run, where each container has its job. Most of the Appwrite containers are stateless, and in order to scale them, all you need is run multiple instances of them and setup a load balancer in front of them.

If you decide to set up a load balancer for a specific container, make sure that the containers that are trying to communicate with it are accessing it through a load balancer and not directly. All connections between Appwrite different containers are set using Docker environment variables.

There are three Appwrite containers that do keep their state are the MariaDB, Redis, and InfluxDB containers that are used for storing data, cache, and stats (in this order). To scale them out, all you need to do is set up a standard cluster (same as you would with any other app using these technologies) according to your needs and performance.

## [ Optional ] Enable ClamAV

### Enable ClamAV Service on Docker Compose
Once you install Appwrite, in the `docker-compose.yml` file, the ClamAV service is disabled, you can simply enable the service by uncommenting the following lines in the `docker-compose.yml` file by removing preceeding `#` in each line.

```yml
  clamav:
    image: appwrite/clamav:1.2.0
    container_name: appwrite-clamav
    networks:
      - appwrite
    volumes:
      - appwrite-uploads:/storage/uploads
```

### Enable ClamAV in Appwrite container
In order to enable ClamAV service to scan the storage, in `appwrite` service, in `docker-compose.yml` under `depends_on` uncomment the line containing `clamav`. After that update the environment variables.
Using the environment variable `_APP_STORAGE_ANTIVIRUS`, you can either disable or enable the antivirus. To enable it, in the environment section, find `_APP_STORAGE_ANTIVIRUS` and set its value to **enabled**, then set `_APP_STORAGE_ANTIVIRUS_HOST` to `clamav` and `_APP_STORAGE_ANTIVIRUS_PORT` to `3310`. This will enable the antivirus checking during new file uploads.

Finally, you can now restart the `appwrite` service and start the `clamav` service. You can do that simply by using `docker-compose up -d` command. This should start the `clamav` service as well as recreate the `appwrite` service.

## Sending Emails

Sending emails is hard. There are a lot of SPAM rules and configurations to master in order to set a functional SMTP server. The SMTP server that comes packaged with Appwrite is great for development but needs some work done to function well against SPAM filters. You can find some guidelines in this [tutorial]([https://www.digitalocean.com/community/tutorials/how-to-use-an-spf-record-to-prevent-spoofing-improve-e-mail-reliability](https://www.digitalocean.com/community/tutorials/how-to-use-an-spf-record-to-prevent-spoofing-improve-e-mail-reliability)).

Another **easier option** is to use an ‘SMTP as a service’ product like [Sendgrid]([https://sendgrid.com/](https://sendgrid.com/)) or [Mailgun]([https://www.mailgun.com/](https://www.mailgun.com/)). You can change Appwrite SMTP settings and credentials to any 3rd party provider you like who support SMTP integration using our [Docker environment variables]([https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#smtp](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/environment-variables.md#smtp)). Most services offer a decent free tier to get started with.

## Backups

Backups are highly recommended for any production environment. Currently, there is not built-in script we provide to do this automatically. To be able to backup your Appwrite server data, stats, and files you will need to do the following.

1.  Create a script to backups and restore your MariaDB Appwrite schema. Note that trying to backup MariaDB using a docker volume backup can result in a corrupted copy of your data. It is recommended to use MariaDB or MySQL built-in tools for this.
2.  Create a script to backups and restore your InfluxDB stats. If you don’t care much about your server stats, you can skip this.
3.  Create a script to backup Appwrite storage volume. There are many [online resources]([https://blog.ssdnodes.com/blog/docker-backup-volumes/](https://blog.ssdnodes.com/blog/docker-backup-volumes/)) explaining different ways to backup a docker volume. When running on multiple servers, it is very recommended to use an attachable storage point. Some cloud providers offer integrated backups to such attachable mount like GCP, AWS, DigitalOcean, and the list continues.
