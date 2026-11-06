# Configure GitHub app {% #git %}

Appwrite supports automatic deployments through Git integration. In order for Appwrite to access your repos, you must [create a GitHub app](https://docs.github.com/en/apps/creating-github-apps/about-creating-github-apps/about-creating-github-apps) to enable this integration. The GitHub app requires the following configurations.

For automatic Git deployment to work, Appwrite needs to receive communication from GitHub, this means your Appwrite project must be accessible on the internet. If you're running on `localhost`, you need to run a proxy like [ngrok](https://ngrok.com/).

## GitHub App name {% #github-app-name %}

The GitHub App name will be displayed when connecting Appwrite to GitHub. In addition, this name will be transformed to a slug ("My GitHub App" will become "my-github-app") and appear in the URL when accessing your app in GitHub. This slug should be set as the `_APP_VCS_GITHUB_APP_NAME` environment variable in Appwrite.

## Homepage URL {% #github-homepage-url %}

The homepage URL will appear when looking at the public page of your app. It can be any URL you'd like.

## Callback URL {% #callback %}

GitHub will use **callback URLs** to redirect users back to Appwrite. Set these callback URLs under **Identifying and authorizing users** in the same order as listed below.

| URLs                                                                          |
| ----------------------------------------------------------------------------- |
| `https://<HOSTNAME_OR_IP>/v1/vcs/github/callback`                             |
| `https://<HOSTNAME_OR_IP>/v1/account/sessions/oauth2/callback/github/console` |

Also, check the **Request user authentication (OAuth) during installation** box.

## Post installation {% #post-install %}

Check the **Redirect on update** box under the **Post installation** section.

## Webhook {% #github-webhooks %}

GitHub will notify Appwrite about events like new commits using webhooks. Under **Webhook**, you need to check the **Active** checkbox. You also need to set the **Webhook URL** as `https://<HOSTNAME_OR_IP>/v1/vcs/github/events`.

If you're running Appwrite on `localhost`, GitHub can't send requests to Appwrite through webhooks and automatic deployments won't work. You'll need to host Appwrite on a server or use a proxy like [ngrok](https://ngrok.com/) to make Appwrite accessible to GitHub.

## Repository permissions {% #repository-permission %}

Configure these permissions under the **Repository permission** dropdown.

| Permission      | Access         |
| --------------- | -------------- |
| Administration  | Read and write |
| Checks          | Read and write |
| Commit Statuses | Read and write |
| Contents        | Read and write |
| Issues          | Read and write |
| Metadata        | Read-only      |
| Pull requests   | Read and write |
| Webhooks        | Read and write |

## Account permissions {% #account-permission %}

Configure these permissions under the **Account Permission** dropdown.

| Permission    | Access    |
| ------------- | --------- |
| Email address | Read-only |

## Subscribe to events {% #subscribe-events %}

Select these under the **Subscribe to events** dropdown.

| Events       |
| ------------ |
| Pull request |
| Push         |

## Where can this GitHub App be installed? {% #github-where-installed %}

Check the **Any account** box under **Where can this GitHub App be installed?** section. This is important to allow you to install the GitHub app on multiple Appwrite projects.

## Environment variables {% #github-variables %}

After creating your app, you'll have to configure the following environment variables.

| Variable                         | Description                                                                                                                                                                                                                                                                                                                                                                                                                   |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `_APP_DOMAIN`                    | Your main Appwrite domain used to access the Appwrite Console. When setting a public suffix domain, Appwrite will attempt to issue a valid SSL certificate automatically. When used with a dev domain, Appwrite will assign a self-signed SSL certificate. If you're using a proxy for **localhost development**, such as [ngrok](https://ngrok.com/), this will be the domain of your localhost proxy.                       |
| `_APP_DOMAIN_TARGET`             | A hostname to serve as a CNAME target for your Appwrite custom domains. You can use the same value as used for the Appwrite `_APP_DOMAIN` variable. If you're using a proxy for **localhost development**, such as [ngrok](https://ngrok.com/), this will be the domain of your localhost proxy, such as `dd65-2405-201-4013-d8d7-b4c5-fb73-39f9-285c.ngrok.io`.                                                              |
| `_APP_DOMAIN_FUNCTIONS`          | This will be used for system generated [Function Domains](/docs/products/functions/domains). When a function domain is generated, it will be `[UNIQUE_ID].[_APP_DOMAIN_FUNCTIONS]`. If `_APP_DOMAIN_FUNCTIONS` is set to `example.com` for example, the generated domain for functions will be something like `64d4d22db370ae41a32e.example.com`. You can use the same value as used for the Appwrite `_APP_DOMAIN` variable. |
| `_APP_DOMAIN_SITES`              | This will be used for system generated [Site Domains](/docs/products/sites/domains). When a site domain is generated, it will be `[UNIQUE_ID].[_APP_DOMAIN_SITES]`. If `_APP_DOMAIN_SITES` is set to `example.com` for example, the generated domain for sites will be something like `64d4d22db370ae41a32e.example.com`. You can use the same value as used for the Appwrite `_APP_DOMAIN` variable.                         |
| `_APP_VCS_GITHUB_APP_NAME`       | Name of your GitHub app. This is the display name you'll see on GitHub and it will be visible in your GitHub app's URL.                                                                                                                                                                                                                                                                                                       |
| `_APP_VCS_GITHUB_PRIVATE_KEY`    | RSA private key from GitHub wrapped with double quotes and newlines replaced with `\n`. You can generate private keys from GitHub application settings.                                                                                                                                                                                                                                                                       |
| `_APP_VCS_GITHUB_APP_ID`         | GitHub application ID. You can find it in your GitHub application details.                                                                                                                                                                                                                                                                                                                                                    |
| `_APP_VCS_GITHUB_CLIENT_ID`      | GitHub client ID. You can find it in your GitHub application details.                                                                                                                                                                                                                                                                                                                                                         |
| `_APP_VCS_GITHUB_CLIENT_SECRET`  | GitHub client secret. You can generate secrets in your GitHub application settings.                                                                                                                                                                                                                                                                                                                                           |
| `_APP_VCS_GITHUB_WEBHOOK_SECRET` | GitHub webhook secret. You can configure it in your GitHub application settings under webhook section.                                                                                                                                                                                                                                                                                                                        |

For example, see below.

```bash
_APP_DOMAIN=appwrite.example.com
_APP_DOMAIN_TARGET=appwrite.example.com
_APP_DOMAIN_FUNCTIONS=functions.example.com
_APP_VCS_GITHUB_APP_NAME=my-github-app
_APP_VCS_GITHUB_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\nMIIEogIBAAKCAQEAuT8f3lo/X83hfvb0ZN/KD2pl86o/jl3ywKrkj/PQZBmtEv/z\nIugE//sfFoHWc4cizkcji+n3FNU+GEdvMioKuJlPBqPTY8hAbVn7R0geZLpDV/rs\n[...]\n-----END RSA PRIVATE KEY-----"
_APP_VCS_GITHUB_APP_ID=12415
_APP_VCS_GITHUB_CLIENT_ID=Iv1.35asdf43asd
_APP_VCS_GITHUB_CLIENT_SECRET=35rsdse532q13
_APP_VCS_GITHUB_WEBHOOK_SECRET=super-secret
```

[Learn more about environment variables](/docs/advanced/self-hosting/environment-variables)

## Update existing GitHub apps {% #existing-apps %}

There are additional steps if you're updating permissions in existing GitHub apps.
Every time you update your GitHub app's permissions, GitHub will prompt you to review the changes.
You will receive an email and you'll find a prompt under **Settings** > **Integrations** > **Applications** > **Installed GitHub Apps** > find your GitHub app > **Configure**.
You need to accept the new permissions so they're applied to your app.
