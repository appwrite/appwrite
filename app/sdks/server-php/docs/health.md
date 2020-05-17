# Health Service

## Get HTTP

```http request
GET https://appwrite.io/v1/health
```

** Check the Appwrite HTTP server is up and responsive. **

## Get Anti virus

```http request
GET https://appwrite.io/v1/health/anti-virus
```

** Check the Appwrite Anti Virus server is up and connection is successful. **

## Get Cache

```http request
GET https://appwrite.io/v1/health/cache
```

** Check the Appwrite in-memory cache server is up and connection is successful. **

## Get DB

```http request
GET https://appwrite.io/v1/health/db
```

** Check the Appwrite database server is up and connection is successful. **

## Get Certificate Queue

```http request
GET https://appwrite.io/v1/health/queue/certificates
```

** Get the number of certificates that are waiting to be issued against [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue server. **

## Get Functions Queue

```http request
GET https://appwrite.io/v1/health/queue/functions
```

## Get Logs Queue

```http request
GET https://appwrite.io/v1/health/queue/logs
```

** Get the number of logs that are waiting to be processed in the Appwrite internal queue server. **

## Get Tasks Queue

```http request
GET https://appwrite.io/v1/health/queue/tasks
```

** Get the number of tasks that are waiting to be processed in the Appwrite internal queue server. **

## Get Usage Queue

```http request
GET https://appwrite.io/v1/health/queue/usage
```

** Get the number of usage stats that are waiting to be processed in the Appwrite internal queue server. **

## Get Webhooks Queue

```http request
GET https://appwrite.io/v1/health/queue/webhooks
```

** Get the number of webhooks that are waiting to be processed in the Appwrite internal queue server. **

## Get Local Storage

```http request
GET https://appwrite.io/v1/health/storage/local
```

** Check the Appwrite local storage device is up and connection is successful. **

## Get Time

```http request
GET https://appwrite.io/v1/health/time
```

** Check the Appwrite server time is synced with Google remote NTP server. We use this technology to smoothly handle leap seconds with no disruptive events. The [Network Time Protocol](https://en.wikipedia.org/wiki/Network_Time_Protocol) (NTP) is used by hundreds of millions of computers and devices to synchronize their clocks over the Internet. If your computer sets its own clock, it likely uses NTP. **

