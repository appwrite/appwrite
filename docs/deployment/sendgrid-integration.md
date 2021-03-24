##  INTEGRATING SENDGRID SMTP WITH APPWRITE

### PREREQUISITES

- Sendgird's email sending API key ([how to create api key](https://sendgrid.com/docs/ui/account-and-settings/api-keys/#creating-an-api-key))
- Apprwrite repo/server locally

### INTEGRATION

- In root directory of repo, `.env` file is where you have to put your credentials of external smtp
- Edit the folowing values in `.env` file :
  - `_APP_SYSTEM_EMAIL_NAME=<FROM_NAME_YOU_WANT_TO_USE>`
  - `_APP_SYSTEM_EMAIL_ADDRESS=<FROM_EMAIL_YOU_WANT_TO_USE>`(make sure email you want to use is authenticated from your sendgrid account)
  - `_APP_SMTP_HOST=smtp.sendgrid.net`
  - `_APP_SMTP_PORT=465`
  - `_APP_SMTP_SECURE=ssl` (If you want to use tls, change `_APP_SMTP_PORT` to 587)
  - `_APP_SMTP_USERNAME=apikey`
  - `_APP_SMTP_PASSWORD=<API_KEY_YOU_GOT_FROM_SENDGRID>`

-  build/re-build with `docker-compose build appwrite-worker-mails && docker-compose up -d`

### DEBUG 

- check logs using `docker-compose logs appwrite-worker-mails`
- check vars used by smtp using `docker-compose exec appwrite-worker-mails vars`
- check your sendgrid's activity dashboard for more details
