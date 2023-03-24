# appwrite

![Version: 1.0.0](https://img.shields.io/badge/Version-1.0.0-informational?style=flat-square) ![Type: application](https://img.shields.io/badge/Type-application-informational?style=flat-square) ![AppVersion: 1.2.1](https://img.shields.io/badge/AppVersion-1.2.1-informational?style=flat-square)

Appwrite Helm chart

## Requirements

| Repository | Name | Version |
|------------|------|---------|
| https://charts.bitnami.com/bitnami | mariadb | 11.5.5 |
| https://charts.bitnami.com/bitnami | redis | 17.9.0 |
| https://helm.influxdata.com/ | telegraf | 1.8.26 |

## Values

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| appwrite.autoscaling.enabled | bool | `false` | Whether to enable autoscaling |
| appwrite.console.whitelist.emails | list | `[]` | This option allows you to limit creation of new users on the Appwrite console. This option is very useful for small teams or sole developers. |
| appwrite.console.whitelist.ips | list | `[]` | This last option allows you to limit creation of users in Appwrite console for users sharing the same set of IP addresses. This option is very useful for team working with a VPN service or a company IP. |
| appwrite.console.whitelist.root | bool | `true` | This option allows you to disable the creation of new users on the Appwrite console. When enabled only 1 user will be able to use the registration form. New users can be added by inviting them to your project. |
| appwrite.domain | string | `"appwrite.local"` | Domain used for ingresses |
| appwrite.env | string | `"development"` | Server Environment |
| appwrite.image.pullPolicy | string | `""` | Image pull policy, Always when tag is latest, IfNotPresent for any other tag You probably don't need to change it. |
| appwrite.image.repository | string | `"appwrite/appwrite"` | The repository (and image name) where the appwrite docker image is stored |
| appwrite.image.tag | string | `""` | Overrides the image tag whose default is the chart appVersion |
| appwrite.ingress.annotations | object | `{"cert-manager.io/cluster-issuer":"letsencrypt-prod"}` | Core ingress annotation |
| appwrite.ingress.enabled | bool | `true` | Core API Ingress |
| appwrite.ingress.tls.enabled | bool | `false` | Core ingress TLS enabled |
| appwrite.locale | string | `"en"` | Locale |
| appwrite.options.abuse | bool | `true` | Abuse checks and API rate limiting. |
| appwrite.options.forceHttps | bool | `false` | Force SSL |
| appwrite.replicaCount | int | `1` | How many replicas of appwrite should be deployed, it is ignored if autoscaling is enabled |
| appwrite.resources | object | `{"limits":{"cpu":"250m","memory":"256Mi"},"requests":{"cpu":"100m","memory":"128Mi"}}` | Resources for the main appwrite pods |
| appwrite.sslKey | string | `"changeme"` | This is your server private key (as base64 string) that is used to encrypt all sensitive data on your server. Appwrite server encrypts all secret data on your server like webhooks, HTTP passwords, user sessions, and storage files. `--set-file appwrite.sslKey=key.pem` can be used to import a key file. |
| appwrite.system.email.addr | string | `"team@appwrite.io"` | This is the sender email address that will appear on email messages sent to developers from the Appwrite console. You should choose an email address that is allowed to be used from your SMTP server to avoid the server email ending in the users' SPAM folders. |
| appwrite.system.email.name | string | `"Appwrite"` | This is the sender name value that will appear on email messages sent to developers from the Appwrite console. You can use url encoded strings for spaces and special chars. |
| appwrite.system.responseFormat | string | `""` | Use this environment variable to set the default Appwrite HTTP response format to support an older version of Appwrite. This option is useful to overcome breaking changes between versions. You can also use the X-Appwrite-Response-Format HTTP request header to overwrite the response for a specific request. This variable accepts any valid Appwrite version. To use the current version format, leave the value of the variable empty. |
| appwrite.system.securityAddress | string | `"certs@appwrite.io"` | This is the email address used to issue SSL certificates for custom domains or the user agent in your webhooks payload. |
| appwrite.usageStats.enabled | bool | `true` | This variable allows you to disable the collection and displaying of usage stats.  You will need an external InfluxDB instance in the `monitoring` namespace. When disabled, the Usage and Telegraf containers will be turned off. |
| appwrite.usageStats.interval | int | `30` | The stats aggregation interval in seconds |
| appwrite.volumes.cache.size | string | `"1Gi"` | Cache volume size |
| appwrite.volumes.certificates.size | string | `"1Gi"` | Certificates volume size |
| appwrite.volumes.config.size | string | `"1Gi"` | Configuration volume size |
| appwrite.volumes.functions.size | string | `"1Gi"` | Functions volume size |
| appwrite.volumes.uploads.size | string | `"1Gi"` | Upload volume size |
| appwrite.workersPerCore | int | `6` | Internal Worker per core for the API containers. Can be configured to optimize performance. |
| clamav.clamd.resources | object | `{"limits":{"cpu":1,"memory":"2Gi"},"requests":{"cpu":"100m","memory":"256Mi"}}` | Resources for the ClamAV scan process |
| clamav.enabled | bool | `true` | This variable allows you to disable the internal anti-virus (ClamAV) scans. |
| clamav.freshclam.resources | object | `{"limits":{"cpu":"250m","memory":"256Mi"},"requests":{"cpu":"100m","memory":"128Mi"}}` | Resources for the ClamAV auth-update process |
| clamav.image | object | `{"pullPolicy":"","repository":"appwrite/clamav","tag":"2.0.0"}` | Clamav image settings |
| clamav.persistence.size | string | `"1Gi"` | ClamAV storage size |
| commonLabels | object | `{}` | Labels to append to all manifests |
| fullnameOverride | string | `""` | String to fully override the deployment name |
| functions.containers | int | `10` | The maximum number of containers Appwrite is allowed to keep alive in the background for function environments.  Running containers allow faster execution time as there is no need to recreate each container every time a function gets executed. |
| functions.cpus | int | `1` | The maximum number of CPU core a single cloud function is allowed to use.  Please note that setting a value higher than available cores will result in a function error, which might result in an error.  When empty, CPU limit will be disabled. |
| functions.memory | string | `"256Mb"` | The maximum amount of memory a single cloud function is allowed to use in megabytes.  When empty, memory limit will be disabled. |
| functions.runtimes | list | `["node-16.0","php-8.0","python-3.9","ruby-3.0","java-16.0","dart-2.14","dotnet-5.0"]` | This option allows you to limit the available environments for cloud functions. |
| functions.swap | string | `"256Mb"` | The maximum amount of swap memory a single cloud function is allowed to use in megabytes.  The default value is empty. When empty, swap memory limit will be disabled. |
| functions.timeout | int | `900` | The maximum number of seconds allowed as a timeout value when creating a new function. |
| influxdb.host | string | `"influxdb.monitoring.svc"` | InfluxDB hostname |
| influxdb.port | int | `8086` | InfluxDB port |
| maintenance.replicaCount | int | `1` | Number of maintenance pods |
| mariadb | object | `{"auth":{"database":"appwrite","existingSecret":"","password":"changeme","rootPassword":"myrootsecret","username":"appwrite"},"primary":{"persistence":{"size":"2Gi"}},"volumePermissions":{"enabled":true}}` | See https://artifacthub.io/packages/helm/bitnami/mariadb |
| mariadb.auth.database | string | `"appwrite"` | MariaDB appwrite database |
| mariadb.auth.existingSecret | string | `""` | Use this if you'd like to use a secret object instead. The secret has to contain the keys `mariadb-root-password`, `mariadb-replication-password` and `mariadb-password` |
| mariadb.auth.password | string | `"changeme"` | MariaDB appwrite password |
| mariadb.auth.rootPassword | string | `"myrootsecret"` | MariaDB root password |
| mariadb.auth.username | string | `"appwrite"` | MariaDB appwrite username |
| mariadb.primary.persistence.size | string | `"2Gi"` | MariaDB storage size |
| nameOverride | string | `""` | String to partially override the deployment name (will maintain the release name) |
| namespaceOverride | string | `nil` | String to fully override the deployment namespace |
| realtime.autoscaling.enabled | bool | `false` | Whether to enable autoscaling |
| realtime.ingress.annotations | object | `{"cert-manager.io/cluster-issuer":"letsencrypt-prod"}` | Real-time ingress annotation |
| realtime.ingress.enabled | bool | `true` | Real-time API ingress |
| realtime.ingress.tls.enabled | bool | `false` | Real-time ingress TLS enabled |
| realtime.replicaCount | int | `1` |  |
| realtime.resources | object | `{"limits":{"cpu":"250m","memory":"256Mi"},"requests":{"cpu":"100m","memory":"128Mi"}}` | Resources for the realtime appwrite pods |
| realtime.workersPerCore | int | `6` | Internal Worker per core for the Realtime containers. Can be configured to optimize performance. |
| redis | object | `{"architecture":"standalone","auth":{"enabled":false,"existingSecret":"","existingSecretPasswordKey":"","password":"","sentinel":false,"usePasswordFiles":false},"master":{"persistence":{"size":"1Gi"},"resources":{"limits":{"cpu":"250m","memory":"256Mi"},"requests":{"cpu":"100m","memory":"128Mi"}}}}` | See https://artifacthub.io/packages/helm/bitnami/redis |
| schedule.replicaCount | int | `1` | Number of schedule pods |
| sms.from | string | `""` | Phone number used for sending out messages. Must start with a leading '+' and maximum of 15 digits without spaces (+123456789). |
| sms.provider | string | `""` | Provider used for delivering SMS for Phone authentication. Use the following format: `sms://[USER]:[SECRET]@[PROVIDER]`. Available providers are twilio, text-magic, telesign, msg91, and vonage. |
| smtp.host | string | `"maildev"` | SMTP server host name address. Use an empty string to disable all mail sending from the server.  The default value for this variable is an empty string |
| smtp.pass | string | `""` | SMTP server user password. Empty by default. |
| smtp.port | int | `1025` | SMTP server TCP port. Empty by default. |
| smtp.secure | bool | `false` | SMTP secure connection protocol. Empty by default, change to 'tls' if running on a secure connection. |
| smtp.user | string | `""` | SMTP server user name. Empty by default. |
| storage.bucket.accessKey | string | `""` | Bucket API access key ID |
| storage.bucket.name | string | `""` | Bucket name |
| storage.bucket.region | string | `"eu-west-1"` | Bucket region |
| storage.bucket.secret | string | `""` | Bucket API secret key |
| storage.device | string | `"local"` | Select default storage device. The default value is 'local'.  List of supported adapters are 'local', 's3', 'dospaces', 'backblaze', 'linode' and 'wasabi'. |
| storage.uploadLimit | string | `"30Mi"` | Maximum file size allowed for file upload. The default value is 30MB limitation. |
| telegraf | object | `{"image":{"repo":"appwrite/telegraf","tag":"1.4.0"},"resources":{"limits":{"cpu":"250m","memory":"256Mi"},"requests":{"cpu":"100m","memory":"128Mi"}}}` | See https://artifacthub.io/packages/helm/influxdata/telegraf |

----------------------------------------------
Autogenerated from chart metadata using [helm-docs v1.11.0](https://github.com/norwoodj/helm-docs/releases/v1.11.0)
