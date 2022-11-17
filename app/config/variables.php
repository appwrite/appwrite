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
                'description' => 'Allows you to force HTTPS connection to your API. This feature redirects any HTTP call to HTTPS and adds the \'Strict-Transport-Security\' header to all HTTP responses. By default, set to \'enabled\'. To disable, set to \'disabled\'. This feature will work only when your ports are set to default 80 and 443.',
                'introduction' => '',
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
            // [
            //     'name' => '_APP_CONSOLE_WHITELIST_DOMAINS',
            //     'description' => 'This option allows you to limit creation of users to Appwrite console for users sharing the same email domains. This option is very useful for team working with company emails domain.\n\nTo enable this option, pass a list of allowed email domains separated by a comma.',
            //     'introduction' => '',
            //     'default' => '',
            //     'required' => false,
            //     'question' => '',
            // ],
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
                'description' => 'This is the sender email address that will appear on email messages sent to developers from the Appwrite console. The default value is \'team@appwrite.io\'. You should choose an email address that is allowed to be used from your SMTP server to avoid the server email ending in the users\' SPAM folders.',
                'introduction' => '0.7.0',
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
                'description' => 'This is the email address used to issue SSL certificates for custom domains or the user agent in your webhooks payload.',
                'introduction' => '0.7.0',
                'default' => 'certs@appwrite.io',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_USAGE_STATS',
                'description' => 'This variable allows you to disable the collection and displaying of usage stats. This value is set to \'enabled\' by default, to disable the usage stats set the value to \'disabled\'. When disabled, it\'s recommended to turn off the Worker Usage, Influxdb and Telegraf containers for better resource usage.',
                'introduction' => '0.7.0',
                'default' => 'enabled',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_LOGGING_PROVIDER',
                'description' => 'This variable allows you to enable logging errors to 3rd party providers. This value is empty by default, to enable the logger set the value to one of \'sentry\', \'raygun\', \'appsignal\', \'logowl\'',
                'introduction' => '0.12.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_LOGGING_CONFIG',
                'description' => 'This variable configures authentication to 3rd party error logging providers. If using Sentry, this should be \'SENTRY_API_KEY;SENTRY_APP_ID\'. If using Raygun, this should be Raygun API key. If using AppSignal, this should be AppSignal API key. If using LogOwl, this should be LogOwl Service Ticket.',
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
            [
                'name' => '_APP_CONNECTIONS_MAX',
                'description' => 'MariaDB server maximum connections.',
                'introduction' => 'TBD',
                'default' => 251,
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
//             [
//                 'name' => '_APP_CONNECTIONS_DB_PROJECT',
//                 'description' => 'A list of comma-separated key value pairs representing Project DBs where key is the database name and value is the DSN connection string.',
//                 'introduction' => 'TBD',
//                 'default' => 'db_fra1_01=mysql://user:password@mariadb:3306/appwrite',
//                 'required' => true,
//                 'question' => '',
//                 'filter' => ''
//             ],
//             [
//                 'name' => '_APP_CONNECTIONS_DB_CONSOLE',
//                 'description' => 'A key value pair representing the Console DB where key is the database name and value is the DSN connection string.',
//                 'introduction' => 'TBD',
//                 'default' => 'db_fra1_01=mysql://user:password@mariadb:3306/appwrite',
//                 'required' => true,
//                 'question' => '',
//                 'filter' => ''
//             ]
        ],
    ],
    [
        'category' => 'InfluxDB',
        'description' => 'Appwrite uses an InfluxDB server for managing time-series data and server stats. The InfluxDB env vars are used to allow Appwrite server to connect to the InfluxDB container.',
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
        'description' => 'Appwrite uses a StatsD server for aggregating and sending stats data over a fast UDP connection. The StatsD env vars are used to allow Appwrite server to connect to the StatsD container.',
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
                'description' => 'SMTP secure connection protocol. Empty by default, change to \'tls\' if running on a secure connection.',
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
                'description' => "Provider used for delivering SMS for Phone authentication. Use the following format: 'sms://[USER]:[SECRET]@[PROVIDER]'. \n\nAvailable providers are twilio, text-magic, telesign, msg91, and vonage.",
                'introduction' => '0.15.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_SMS_FROM',
                'description' => 'Phone number used for sending out messages. Must start with a leading \'+\' and maximum of 15 digits without spaces (+123456789).',
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
                'name' => '_APP_CONNECTIONS_STORAGE',
                'description' => 'A DSN representing the storage device to connect to. The DSN takes the following format <device>://<access_key>:<access_secret>@<host>:<port>/<bucket>?region=<region>. For example, for S3: \'s3://access_key:access_secret@host:port/bucket?region=us-east-1\'. To use the local filesystem, you can leave this variable empty. Available devices are file, s3, dospaces, linode, backblaze and wasabi.',
                'introduction' => '1.1.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DEVICE',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => 'Local',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_ACCESS_KEY',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_SECRET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_REGION',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => 'us-east-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_S3_BUCKET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_ACCESS_KEY',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_SECRET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_REGION',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => 'us-east-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_DO_SPACES_BUCKET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.13.0',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_ACCESS_KEY',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_SECRET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_REGION',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => 'us-west-004',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_BACKBLAZE_BUCKET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_ACCESS_KEY',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_SECRET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_REGION',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => 'eu-central-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_LINODE_BUCKET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_ACCESS_KEY',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_SECRET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => '',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_REGION',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
                'introduction' => '0.14.2',
                'default' => 'eu-central-1',
                'required' => false,
                'question' => '',
            ],
            [
                'name' => '_APP_STORAGE_WASABI_BUCKET',
                'description' => 'Deprecated since 1.2.0. Use _APP_CONNECTIONS_STORAGE instead.',
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
                'description' => 'The maximum size deployment in bytes. The default value is 30MB.',
                'introduction' => '0.13.0',
                'default' => '30000000',
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
                'description' => 'The maximum number of CPU core a single cloud function is allowed to use. Please note that setting a value higher than available cores will result in a function error, which might result in an error. The default value is empty. When it\'s empty, CPU limit will be disabled.',
                'introduction' => '0.7.0',
                'default' => '0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_MEMORY',
                'description' => 'The maximum amount of memory a single cloud function is allowed to use in megabytes. The default value is  empty. When it\'s empty, memory limit will be disabled.',
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
                'description' => 'The secret key used by Appwrite to communicate with the function executor. Make sure to change this!',
                'introduction' => '0.13.0',
                'default' => 'your-secret-key',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EXECUTOR_HOST',
                'description' => 'The host used by Appwrite to communicate with the function executor!',
                'introduction' => '0.13.0',
                'default' => 'http://appwrite-executor/v1',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_EXECUTOR_RUNTIME_NETWORK',
                'description' => 'Deprecated with 0.14.0, use \'OPEN_RUNTIMES_NETWORK\' instead!',
                'introduction' => '0.13.0',
                'default' => 'appwrite_runtimes',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_ENVS',
                'description' => 'Deprecated with 0.8.0, use \'_APP_FUNCTIONS_RUNTIMES\' instead!',
                'introduction' => '0.7.0',
                'default' => 'node-16.0,php-7.4,python-3.9,ruby-3.0',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_INACTIVE_THRESHOLD',
                'description' => 'The minimum time a function can be inactive before it\'s container is shutdown and put to sleep. The default value is 60 seconds.',
                'introduction' => '0.13.0',
                'default' => '60',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => 'DOCKERHUB_PULL_USERNAME',
                'description' => 'Deprecated with 1.2.0, use \'_APP_DOCKER_HUB_USERNAME\' instead!',
                'introduction' => '0.10.0',
                'default' => '',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => 'DOCKERHUB_PULL_PASSWORD',
                'description' => 'Deprecated with 1.2.0, use \'_APP_DOCKER_HUB_PASSWORD\' instead!',
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
                'description' => 'Deprecated with 1.2.0, use \'_APP_FUNCTIONS_RUNTIMES_NETWORK\' instead!',
                'introduction' => '0.13.0',
                'default' => 'appwrite_runtimes',
                'required' => false,
                'question' => '',
                'filter' => ''
            ],
            [
                'name' => '_APP_FUNCTIONS_RUNTIMES_NETWORK',
                'description' => 'The docker network used for communication between the executor and runtimes. Change this if you have altered the default network names.',
                'introduction' => '1.2.0',
                'default' => 'openruntimes-runtimes',
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
            ]
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
                'description' => 'IThe maximum duration (in seconds) upto which to retain audit logs. The default value is 1209600 seconds (14 days).',
                'introduction' => '0.7.0',
                'default' => '1209600',
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
];
