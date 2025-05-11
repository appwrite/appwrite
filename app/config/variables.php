<?php

use Utopia\Config\Config;

return [
    [
        'category' => 'General',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_ENV',
                'description' => 'Set your server running environment. By default, the var is set to \'development\'. When deploying to production, change it to: \'production\'.',
                'introduction' => '',
                'default' => 'production',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_LOCALE',
                'description' => 'Set your Appwrite\'s locale. By default, the locale is set to \'en\'.',
                'introduction' => '',
                'default' => 'en',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_OPTIONS_ABUSE',
                'description' => 'Allows you to disable abuse checks and API rate limiting. By default, set to \'enabled\'. To cancel the abuse checking, set to \'disabled\'. It is not recommended to disable this check-in a production environment.',
                'introduction' => '',
                'default' => 'enabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_OPTIONS_FORCE_HTTPS',
                'description' => 'Allows you to force HTTPS connection to your API. This feature redirects any HTTP call to HTTPS and adds the \'Strict-Transport-Security\' header to all HTTP responses. By default, set to \'enabled\'. To disable, set to \'disabled\'. This feature will work only when your ports are set to default 80 and 443, and you have set up wildcard certificates with DNS challenge.',
                'introduction' => '',
                'default' => 'disabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_OPTIONS_FUNCTIONS_FORCE_HTTPS',
                'description' => 'Allows you to force HTTPS connection to function domains. This feature redirects any HTTP call to HTTPS and adds the \'Strict-Transport-Security\' header to all HTTP responses. By default, set to \'enabled\'. To disable, set to \'disabled\'. This feature will work only when your ports are set to default 80 and 443.',
                'introduction' => '',
                'default' => 'disabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_OPTIONS_ROUTER_PROTECTION',
                'description' => 'Protects server from serving requests from unknown hostnames, and from serving Console for custom project domains. By default, set to \'disabled\'. To start router protection, set to \'enabled\'. It is recommended to enable this variable on production environment.',
                'introduction' => '1.4.4',
                'default' => 'disabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_OPENSSL_KEY_V1',
                'description' => 'This is your server private secret key that is used to encrypt all sensitive data on your server. Appwrite server encrypts all secret data on your server like webhooks, HTTP passwords, user sessions, and storage files. The var is not set by default, if you wish to take advantage of Appwrite encryption capabilities you should change it and make sure to **keep it a secret and have a backup for it**.',
                'introduction' => '',
                'default' => 'your-secret-key',
                'required' => true,
                'question' => 'Choose a secret API key, make sure to make a backup of your key in a secure location',
                'filter' => 'token'
            ],
            [
                'name' => '_APP_DOMAIN',
                'description' => 'Your Appwrite domain address. When setting a public suffix domain, Appwrite will attempt to issue a valid SSL certificate automatically. When used with a dev domain, Appwrite will assign a self-signed SSL certificate. The default value is \'localhost\'.',
                'introduction' => '',
                'default' => 'localhost',
                'required' => true,
                'question' => 'Enter your Appwrite hostname',
                'filter' => ''
            ],
            [
                'name' => '_APP_CUSTOM_DOMAIN_DENY_LIST',
                'description' => 'List of reserved or prohibited domains when configuring custom domains.',
                'introduction' => '',
                'default' => 'example.com,test.com,app.example.com',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DOMAIN_FUNCTIONS',
                'description' => 'A domain to use for function preview URLs. Setting to empty turns off function preview URLs.',
                'introduction' => '',
                'default' => 'functions.localhost',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DOMAIN_TARGET',
                'description' => 'A DNS A record hostname to serve as a CNAME target for your Appwrite custom domains. You can use the same value as used for the Appwrite \'_APP_DOMAIN\' variable. The default value is \'localhost\'.',
                'introduction' => '',
                'default' => 'localhost',
                'required' => true,
                'question' => 'Enter a DNS A record hostname to serve as a CNAME for your custom domains.' . PHP_EOL . 'You can use the same value as used for the Appwrite hostname.',
                'filter' => 'domainTarget'
            ],
            [
                'name' => '_APP_CONSOLE_WHITELIST_ROOT',
                'description' => 'This option allows you to disable the creation of new users on the Appwrite console. When enabled only 1 user will be able to use the registration form. New users can be added by inviting them to your project. By default this option is enabled.',
                'introduction' => '0.8.0',
                'default' => 'enabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_CONSOLE_WHITELIST_EMAILS',
                'description' => 'This option allows you to limit creation of new users on the Appwrite console. This option is very useful for small teams or sole developers. To enable it, pass a list of allowed email addresses separated by a comma.',
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_CONSOLE_WHITELIST_IPS',
                'description' => "This last option allows you to limit creation of users in Appwrite console for users sharing the same set of IP addresses. This option is very useful for team working with a VPN service or a company IP.\n\nTo enable/activate this option, pass a list of allowed IP addresses separated by a comma.",
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_CONSOLE_HOSTNAMES',
                'description' => 'This option allows you to add additional hostnames to your Appwrite console. This option is very useful for allowing access to the console project from additional domains. To enable it, pass a list of allowed hostnames separated by a comma.',
                'introduction' => '1.5.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SYSTEM_EMAIL_NAME',
                'description' => 'This is the sender name value that will appear on email messages sent to developers from the Appwrite console. The default value is: \'Appwrite\'. You can use url encoded strings for spaces and special chars.',
                'introduction' => '0.7.0',
                'default' => 'Appwrite',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SYSTEM_EMAIL_ADDRESS',
                'description' => 'This is the sender email address that will appear on email messages sent to developers from the Appwrite console. The default value is \'noreply@appwrite.io\'. You should choose an email address that is allowed to be used from your SMTP server to avoid the server email ending in the users\' SPAM folders.',
                'introduction' => '0.7.0',
                'default' => 'noreply@appwrite.io',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SYSTEM_TEAM_EMAIL',
                'description' => 'This is the sender email address that will appear in the generated specs. The default value is \'team@appwrite.io\'.',
                'introduction' => '1.6.0',
                'default' => 'team@appwrite.io',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SYSTEM_RESPONSE_FORMAT',
                'description' => 'Use this environment variable to set the default Appwrite HTTP response format to support an older version of Appwrite. This option is useful to overcome breaking changes between versions. You can also use the `X-Appwrite-Response-Format` HTTP request header to overwrite the response for a specific request. This variable accepts any valid Appwrite version. To use the current version format, leave the value of the variable empty.',
                'introduction' => '0.7.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SYSTEM_SECURITY_EMAIL_ADDRESS',
                'description' => 'Deprecated since 1.5.1 use _APP_EMAIL_SECURITY and _APP_EMAIL_CERTIFICATES instead',
                'introduction' => '0.7.0',
                'default' => 'certs@appwrite.io',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EMAIL_SECURITY',
                'description' => 'This is the email address used as the user agent in your webhooks payload.',
                'introduction' => '1.5.1',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EMAIL_CERTIFICATES',
                'description' => 'This is the email address used to issue SSL certificates for custom domains',
                'introduction' => '1.5.1',
                'default' => '',
                'required' => true,
                'question' => 'Enter an email that will be used when registering for SSL certificates',
                'filter' => ''
            ],
            [
                'name' => '_APP_USAGE_STATS',
                'description' => 'This variable allows you to disable the collection and displaying of usage stats. This value is set to \'enabled\' by default, to disable the usage stats set the value to \'disabled\'. When disabled, it\'s recommended to turn off the Worker Usage container to reduce resource usage.',
                'introduction' => '0.7.0',
                'default' => 'enabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_LOGGING_PROVIDER',
                'description' => 'Deprecated since 1.6.0, use `_APP_LOGGING_CONFIG` with DSN value instead. This variable allows you to enable logging errors to 3rd party providers. This value is empty by default, set the value to one of \'sentry\', \'raygun\', \'appSignal\', \'logOwl\' to enable the logger.',
                'introduction' => '0.12.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_LOGGING_CONFIG',
                'description' => 'This variable allows you to enable logging errors to third party providers. This value is empty by default, set a DSN value to one of the following `sentry://PROJECT_ID:SENTRY_API_KEY@SENTRY_HOST/`, , `logowl://SERVICE_TICKET@SERIVCE_HOST/` `raygun://RAYGUN_API_KEY/`, `appSignal://API_KEY/` to enable the logger.\n\nFor versions prior `1.5.6` you can use the old syntax.\n\nOld syntax: If using Sentry, this should be \'SENTRY_API_KEY;SENTRY_APP_ID\'. If using Raygun, this should be Raygun API key. If using AppSignal, this should be AppSignal API key. If using LogOwl, this should be LogOwl Service Ticket.',
                'introduction' => '0.12.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_USAGE_AGGREGATION_INTERVAL',
                'description' => 'Interval value containing the number of seconds that the Appwrite usage process should wait before aggregating stats and syncing it to Database from TimeSeries data. The default value is 30 seconds. Reintroduced in 1.1.0.',
                'introduction' => '1.1.0',
                'default' => '30',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_USAGE_TIMESERIES_INTERVAL',
                'description' => 'Deprecated since 1.1.0 use _APP_USAGE_AGGREGATION_INTERVAL instead.',
                'introduction' => '1.0.0',
                'default' => '30',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_USAGE_DATABASE_INTERVAL',
                'description' => 'Deprecated since 1.1.0 use _APP_USAGE_AGGREGATION_INTERVAL instead.',
                'introduction' => '1.0.0',
                'default' => '900',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_WORKER_PER_CORE',
                'description' => 'Internal Worker per core for the API, Realtime and Executor containers. Can be configured to optimize performance.',
                'introduction' => '0.13.0',
                'default' => 6,
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_CONSOLE_SESSION_ALERTS',
                'description' => 'This option allows you configure if a new login in the Appwrite Console should send an alert email to the user. It\'s disabled by default with value "disabled", and to enable it, pass value "enabled".',
                'introduction' => '1.6.0',
                'default' => 'disabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_COMPRESSION_ENABLED',
                'description' => 'This option allows you to enable or disable the response compression for the Appwrite API. It\'s enabled by default with value "enabled", and to disable it, pass value "disabled".',
                'introduction' => '1.6.0',
                'default' => 'enabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_COMPRESSION_MIN_SIZE_BYTES',
                'description' => 'This option allows you to set the minimum size in bytes for the response compression to be applied. The default value is 1024 bytes.',
                'introduction' => '1.6.0',
                'default' => 1024,
                'required' => false,
                'question' => '',
                'filter' => ''
            ]
        ],
    ],
    [
        'category' => 'Redis',
        'description' => 'Appwrite uses a Redis server for managing cache, queues and scheduled tasks. The Redis env vars are used to allow Appwrite server to connect to the Redis container.',
        'variables' => [
            [
                'name' => '_APP_REDIS_HOST',
                'description' => 'Redis server hostname address. Default value is: \'redis\'.',
                'introduction' => '',
                'default' => 'redis',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_REDIS_PORT',
                'description' => 'Redis server TCP port. Default value is: \'6379\'.',
                'introduction' => '',
                'default' => '6379',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_REDIS_USER',
                'description' => 'Redis server user. This is an optional variable. Default value is an empty string.',
                'introduction' => '0.7',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_REDIS_PASS',
                'description' => 'Redis server password. This is an optional variable. Default value is an empty string.',
                'introduction' => '0.7',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'MariaDB',
        'description' => 'Appwrite is using a MariaDB server for managing persistent database data. The MariaDB env vars are used to allow Appwrite server to connect to the MariaDB container.',
        'variables' => [
            [
                'name' => '_APP_DB_HOST',
                'description' => 'MariaDB server host name address. Default value is: \'mariadb\'.',
                'introduction' => '',
                'default' => 'mariadb',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DB_PORT',
                'description' => 'MariaDB server TCP port. Default value is: \'3306\'.',
                'introduction' => '',
                'default' => '3306',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DB_SCHEMA',
                'description' => 'MariaDB server database schema. Default value is: \'appwrite\'.',
                'introduction' => '',
                'default' => 'appwrite',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DB_USER',
                'description' => 'MariaDB server user name. Default value is: \'user\'.',
                'introduction' => '',
                'default' => 'user',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DB_PASS',
                'description' => 'MariaDB server user password. Default value is: \'password\'.',
                'introduction' => '',
                'default' => 'password',
                'required' => false,
                'question' => '',
                'filter' => 'password'
            ],
            [
                'name' => '_APP_DB_ROOT_PASS',
                'description' => 'MariaDB server root password. Default value is: \'rootsecretpassword\'.',
                'introduction' => '',
                'default' => 'rootsecretpassword',
                'required' => false,
                'question' => '',
                'filter' => 'password'
            ],
        ],
    ],
    [
        'category' => 'InfluxDB',
        'description' => 'Deprecated since 1.4.8.',
        'variables' => [
            [
                'name' => '_APP_INFLUXDB_HOST',
                'description' => 'InfluxDB server host name address. Default value is: \'influxdb\'.',
                'introduction' => '',
                'default' => 'influxdb',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_INFLUXDB_PORT',
                'description' => 'InfluxDB server TCP port. Default value is: \'8086\'.',
                'introduction' => '',
                'default' => '8086',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'StatsD',
        'description' => 'Deprecated since 1.4.8.',
        'variables' => [
            [
                'name' => '_APP_STATSD_HOST',
                'description' => 'StatsD server host name address. Default value is: \'telegraf\'.',
                'introduction' => '',
                'default' => 'telegraf',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_STATSD_PORT',
                'description' => 'StatsD server TCP port. Default value is: \'8125\'.',
                'introduction' => '',
                'default' => '8125',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'SMTP',
        'description' => "Appwrite is using an SMTP server for emailing your projects users and server admins. The SMTP env vars are used to allow Appwrite server to connect to the SMTP container.\n\nIf running in production, it might be easier to use a 3rd party SMTP server as it might be a little more difficult to set up a production SMTP server that will not send all your emails into your user\'s SPAM folder.",
        'variables' => [
            [
                'name' => '_APP_SMTP_HOST',
                'description' => 'SMTP server host name address. Use an empty string to disable all mail sending from the server. The default value for this variable is an empty string',
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SMTP_PORT',
                'description' => 'SMTP server TCP port. Empty by default.',
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SMTP_SECURE',
                'description' => 'SMTP secure connection protocol. Empty by default, change to \'tls\' or \'ssl\' if running on a secure connection.',
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SMTP_USERNAME',
                'description' => 'SMTP server user name. Empty by default.',
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SMTP_PASSWORD',
                'description' => 'SMTP server user password. Empty by default.',
                'introduction' => '',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'Phone',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_SMS_PROVIDER',
                'description' => "Provider used for delivering SMS for Phone authentication. Use the following format: 'sms://[USER]:[SECRET]@[PROVIDER]'.\n\nEnsure `[USER]` and `[SECRET]` are URL encoded if they contain any non-alphanumeric characters.\n\nAvailable providers are twilio, Textmagic, telesign, msg91, and vonage.",
                'introduction' => '0.15.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SMS_FROM',
                'description' => 'Phone number used for sending out messages. If using Twilio, this may be a Messaging Service SID, starting with MG. Otherwise, the number must start with a leading \'+\' and maximum of 15 digits without spaces (+123456789). ',
                'introduction' => '0.15.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'Storage',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_STORAGE_LIMIT',
                'description' => 'Maximum file size allowed for file upload. The default value is 30MB. You should pass your size limit value in bytes.',
                'introduction' => '0.7.0',
                'default' => '30000000',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_STORAGE_PREVIEW_LIMIT',
                'description' => 'Maximum file size allowed for file image preview. The default value is 20MB. You should pass your size limit value in bytes.',
                'introduction' => '0.13.4',
                'default' => '20000000',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_STORAGE_ANTIVIRUS',
                'description' => 'This variable allows you to disable the internal anti-virus scans. This value is set to \'disabled\' by default, to enable the scans set the value to \'enabled\'. Before enabling, you must add the ClamAV service and depend on it on main Appwrite service.',
                'introduction' => '',
                'default' => 'disabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_STORAGE_ANTIVIRUS_HOST',
                'description' => 'ClamAV server host name address. Default value is: \'clamav\'.',
                'introduction' => '0.7.0',
                'default' => 'clamav',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_STORAGE_ANTIVIRUS_PORT',
                'description' => 'ClamAV server TCP port. Default value is: \'3310\'.',
                'introduction' => '0.7.0',
                'default' => '3310',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_STORAGE_DEVICE',
                'description' => 'Select default storage device. The default value is \'local\'. List of supported adapters are \'local\', \'s3\', \'dospaces\', \'backblaze\', \'linode\' and \'wasabi\'.',
                'introduction' => '0.13.0',
                'default' => 'local',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_ACCESS_KEY',
                'description' => 'S3 storage access key. Required when the storage adapter is set to S3. You can get your access key from your S3 storage provider',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_SECRET',
                'description' => 'S3 storage secret key. Required when the storage adapter is set to S3. You can get your secret key from your S3 storage provider.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_REGION',
                'description' => 'S3 storage region. Required when storage adapter is set to S3. You can find your region info for your bucket from your S3 storage provider.',
                'introduction' => '0.13.0',
                'default' => 'us-east-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_BUCKET',
                'description' => 'S3 storage bucket. Required when storage adapter is set to S3. You can create buckets in your S3 storage provider.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_ENDPOINT',
                'description' => 'S3 storage endpoint. Required when using S3 storage providers other than AWS.',
                'introduction' => '0.16.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_ACCESS_KEY',
                'description' => 'DigitalOcean spaces access key. Required when the storage adapter is set to DOSpaces. You can get your access key from your DigitalOcean console.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_SECRET',
                'description' => 'DigitalOcean spaces secret key. Required when the storage adapter is set to DOSpaces. You can get your secret key from your DigitalOcean console.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_REGION',
                'description' => 'DigitalOcean spaces region. Required when storage adapter is set to DOSpaces. You can find your region info for your space from DigitalOcean console.',
                'introduction' => '0.13.0',
                'default' => 'us-east-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_BUCKET',
                'description' => 'DigitalOcean spaces bucket. Required when storage adapter is set to DOSpaces. You can create spaces in your DigitalOcean console.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_ACCESS_KEY',
                'description' => 'Backblaze access key. Required when the storage adapter is set to Backblaze. Your Backblaze keyID will be your access key. You can get your keyID from your Backblaze console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_SECRET',
                'description' => 'Backblaze secret key. Required when the storage adapter is set to Backblaze. Your Backblaze applicationKey will be your secret key. You can get your applicationKey from your Backblaze console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_REGION',
                'description' => 'Backblaze region. Required when storage adapter is set to Backblaze. You can find your region info from your Backblaze console.',
                'introduction' => '0.14.2',
                'default' => 'us-west-004',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_BUCKET',
                'description' => 'Backblaze bucket. Required when storage adapter is set to Backblaze. You can create your bucket from your Backblaze console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_ACCESS_KEY',
                'description' => 'Linode object storage access key. Required when the storage adapter is set to Linode. You can get your access key from your Linode console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_SECRET',
                'description' => 'Linode object storage secret key. Required when the storage adapter is set to Linode. You can get your secret key from your Linode console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_REGION',
                'description' => 'Linode object storage region. Required when storage adapter is set to Linode. You can find your region info from your Linode console.',
                'introduction' => '0.14.2',
                'default' => 'eu-central-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_BUCKET',
                'description' => 'Linode object storage bucket. Required when storage adapter is set to Linode. You can create buckets in your Linode console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_ACCESS_KEY',
                'description' => 'Wasabi access key. Required when the storage adapter is set to Wasabi. You can get your access key from your Wasabi console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_SECRET',
                'description' => 'Wasabi secret key. Required when the storage adapter is set to Wasabi. You can get your secret key from your Wasabi console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_REGION',
                'description' => 'Wasabi region. Required when storage adapter is set to Wasabi. You can find your region info from your Wasabi console.',
                'introduction' => '0.14.2',
                'default' => 'eu-central-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_BUCKET',
                'description' => 'Wasabi bucket. Required when storage adapter is set to Wasabi. You can create buckets in your Wasabi console.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
        ],
    ],
    [
        'category' => 'Functions',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_FUNCTIONS_SIZE_LIMIT',
                'description' => 'The maximum size of a function in bytes. The default value is 30MB.',
                'introduction' => '0.13.0',
                'default' => '30000000',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_BUILD_SIZE_LIMIT',
                'description' => 'The maximum size of a built deployment in bytes. The default value is 2,000,000,000 (2GB), and the maximum value is 4,294,967,295 (4.2GB).',
                'introduction' => '1.6.0',
                'default' => '2000000000',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_TIMEOUT',
                'description' => 'The maximum number of seconds allowed as a timeout value when creating a new function. The default value is 900 seconds. This is the global limit, timeout for individual functions are configured in the function\'s settings or in appwrite.json.',
                'introduction' => '0.7.0',
                'default' => '900',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_BUILD_TIMEOUT',
                'description' => 'The maximum number of seconds allowed as a timeout value when building a new function. The default value is 900 seconds.',
                'introduction' => '0.13.0',
                'default' => '900',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_CONTAINERS',
                'description' => 'Deprecated since 1.2.0. Runtimes now timeout by inactivity using \'_APP_FUNCTIONS_INACTIVE_THRESHOLD\'.',
                'introduction' => '0.7.0',
                'default' => '10',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_CPUS',
                'description' => 'The maximum number of CPU core a single cloud function is allowed to use. Please note that setting a value higher than available cores will result in a function error, which might result in an error. The default value is empty. When it\'s empty or 0, CPU limit will be disabled.',
                'introduction' => '0.7.0',
                'default' => '0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_MEMORY',
                'description' => 'The maximum amount of memory a single cloud function is allowed to use in megabytes. The default value is  empty. When it\'s empty or 0, memory limit will be disabled.',
                'introduction' => '0.7.0',
                'default' => '0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_MEMORY_SWAP',
                'description' => 'Deprecated since 1.2.0. High use of swap memory is not recommended to preserve harddrive health.',
                'introduction' => '0.7.0',
                'default' => '0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_RUNTIMES',
                'description' => "This option allows you to enable or disable runtime environments for cloud functions. Disable unused runtimes to save disk space.\n\nTo enable cloud function runtimes, pass a list of enabled environments separated by a comma.\n\nCurrently, supported environments are: " . \implode(', ', \array_keys(Config::getParam('runtimes'))),
                'introduction' => '0.8.0',
                'default' => 'node-16.0,php-8.0,python-3.9,ruby-3.0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EXECUTOR_SECRET',
                'description' => 'The secret key used by Appwrite to communicate with the function executor. Make sure to change this.',
                'introduction' => '0.13.0',
                'default' => 'your-secret-key',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EXECUTOR_HOST',
                'description' => 'The host used by Appwrite to communicate with the function executor.',
                'introduction' => '0.13.0',
                'default' => 'http://exc1/v1',
                'required' => false,
                'overwrite' => true,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EXECUTOR_RUNTIME_NETWORK',
                'description' => 'Deprecated with 0.14.0, use \'OPEN_RUNTIMES_NETWORK\' instead.',
                'introduction' => '0.13.0',
                'default' => 'appwrite_runtimes',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_ENVS',
                'description' => 'Deprecated with 0.8.0, use \'_APP_FUNCTIONS_RUNTIMES\' instead.',
                'introduction' => '0.7.0',
                'default' => 'node-16.0,php-7.4,python-3.9,ruby-3.0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_INACTIVE_THRESHOLD',
                'description' => 'The minimum time a function must be inactive before it can be shut down and cleaned up. This feature is intended to clean up unused containers. Containers may remain active for longer than the interval before being shut down, as Appwrite only cleans up unused containers every hour. If no value is provided, the default is 60 seconds.',
                'introduction' => '0.13.0',
                'default' => '60',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => 'DOCKERHUB_PULL_USERNAME',
                'description' => 'Deprecated with 1.2.0, use \'_APP_DOCKER_HUB_USERNAME\' instead.',
                'introduction' => '0.10.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => 'DOCKERHUB_PULL_PASSWORD',
                'description' => 'Deprecated with 1.2.0, use \'_APP_DOCKER_HUB_PASSWORD\' instead.',
                'introduction' => '0.10.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => 'DOCKERHUB_PULL_EMAIL',
                'description' => 'Deprecated since 1.2.0. Email is no longer needed.',
                'introduction' => '0.10.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => 'OPEN_RUNTIMES_NETWORK',
                'description' => 'Deprecated with 1.2.0, use \'_APP_FUNCTIONS_RUNTIMES_NETWORK\' instead.',
                'introduction' => '0.13.0',
                'default' => 'appwrite_runtimes',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_RUNTIMES_NETWORK',
                'description' => 'The docker network used for communication between the executor and runtimes.',
                'introduction' => '1.2.0',
                'default' => 'runtimes',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DOCKER_HUB_USERNAME',
                'description' => 'The username for hub.docker.com. This variable is used to pull images from hub.docker.com.',
                'introduction' => '1.2.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_DOCKER_HUB_PASSWORD',
                'description' => 'The password for hub.docker.com. This variable is used to pull images from hub.docker.com.',
                'introduction' => '1.2.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_MAINTENANCE_INTERVAL',
                'description' => 'Interval value containing the number of seconds that the executor should wait before checking for inactive runtimes. The default value is 3600 seconds (1 hour).',
                'introduction' => '1.4.0',
                'default' => '3600',
                'required' => false,
                'overwrite' => true,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'VCS (Version Control System)',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_VCS_GITHUB_APP_NAME',
                'description' => 'Name of your GitHub app. This value should be set to your GitHub application\'s URL.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_VCS_GITHUB_PRIVATE_KEY',
                'description' => 'GitHub app RSA private key. You can generate private keys from GitHub application settings.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_VCS_GITHUB_APP_ID',
                'description' => 'GitHub application ID. You can find it in your GitHub application details.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_VCS_GITHUB_CLIENT_ID',
                'description' => 'GitHub client ID. You can find it in your GitHub application details.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_VCS_GITHUB_CLIENT_SECRET',
                'description' => 'GitHub client secret. You can generate secrets in your GitHub application settings.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_VCS_GITHUB_WEBHOOK_SECRET',
                'description' => 'GitHub webhook secret. You can configure it in your GitHub application settings under webhook section.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'Maintenance',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_MAINTENANCE_INTERVAL',
                'description' => 'Interval value containing the number of seconds that the Appwrite maintenance process should wait before executing system cleanups and optimizations. The default value is 86400 seconds (1 day).',
                'introduction' => '0.7.0',
                'default' => '86400',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_DELAY',
                'description' => 'Deprecated with 1.6.2 use _APP_MAINTENANCE_START_TIME instead to run the maintenance at a specific time per day.',
                'introduction' => '1.5.0',
                'default' => '0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_START_TIME',
                'description' => 'The time of day (in 24-hour format) when the maintenance process should start. The default value is 00:00.',
                'introduction' => '1.6.2',
                'default' => '00:00',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_CACHE',
                'description' => 'The maximum duration (in seconds) upto which to retain cached files. The default value is 2592000 seconds (30 days).',
                'introduction' => '1.0.0',
                'default' => '2592000',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_EXECUTION',
                'description' => 'The maximum duration (in seconds) upto which to retain execution logs. The default value is 1209600 seconds (14 days).',
                'introduction' => '0.7.0',
                'default' => '1209600',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_AUDIT',
                'description' => 'The maximum duration (in seconds) upto which to retain audit logs. The default value is 1209600 seconds (14 days).',
                'introduction' => '0.7.0',
                'default' => '1209600',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_AUDIT_CONSOLE',
                'description' => 'The maximum duration (in seconds) upto which to retain console audit logs. The default value is 15778800 seconds (6 months).',
                'introduction' => '1.6.2',
                'default' => '15778800',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_ABUSE',
                'description' => 'The maximum duration (in seconds) upto which to retain abuse logs. The default value is 86400 seconds (1 day).',
                'introduction' => '0.7.0',
                'default' => '86400',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_USAGE_HOURLY',
                'description' => 'The maximum duration (in seconds) upto which to retain hourly usage metrics. The default value is 8640000 seconds (100 days).',
                'introduction' => '',
                'default' => '8640000',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MAINTENANCE_RETENTION_SCHEDULES',
                'description' => 'Schedules deletion interval ( in seconds ) ',
                'introduction' => 'TBD',
                'default' => '86400',
                'required' => false,
                'question' => '',
                'filter' => ''
            ]
        ],
    ],
    [
        'category' => 'GraphQL',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_GRAPHQL_MAX_BATCH_SIZE',
                'description' => 'Maximum number of batched queries per request. The default value is 10.',
                'introduction' => '1.2.0',
                'default' => '10',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_GRAPHQL_MAX_COMPLEXITY',
                'description' => 'Maximum complexity of a GraphQL query. One field adds one to query complexity. Lists multiply the complexity by the number of items requested. The default value is 250.',
                'introduction' => '1.2.0',
                'default' => '250',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_GRAPHQL_MAX_DEPTH',
                'description' => 'Maximum depth of a GraphQL query. One nested field level adds one to query depth. The default value is 3.',
                'introduction' => '1.2.0',
                'default' => '3',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
        ],
    ],
    [
        'category' => 'Migrations',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_MIGRATIONS_FIREBASE_CLIENT_ID',
                'description' => 'Google OAuth client ID. You can find it in your GCP application settings.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_MIGRATIONS_FIREBASE_CLIENT_SECRET',
                'description' => 'Google OAuth client secret. You can generate secrets in your GCP application settings.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ]
        ]
    ],
    [
        'category' => 'Assistant',
        'description' => '',
        'variables' => [
            [
                'name' => '_APP_ASSISTANT_OPENAI_API_KEY',
                'description' => 'OpenAI API key. You can find it in your OpenAI application settings.',
                'introduction' => '1.4.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ]
        ]
    ]
];
