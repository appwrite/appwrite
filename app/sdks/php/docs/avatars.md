# Avatars Service

## Get Browser Icon

```http request
GET https://appwrite.io/v1/avatars/browsers/{code}
```

** /docs/references/avatars/get-browser.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| code | string | **Required** Browser Code. |  |
| width | integer | Image width. Pass an integer between 0 to 2000. Defaults to 100 | 100 |
| height | integer | Image height. Pass an integer between 0 to 2000. Defaults to 100 | 100 |
| quality | integer | Image quality. Pass an integer between 0 to 100. Defaults to 100 | 100 |

## Get Credit Card Icon

```http request
GET https://appwrite.io/v1/avatars/credit-cards/{code}
```

** /docs/references/avatars/get-credit-cards.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| code | string | **Required** Credit Card Code. Possible values: amex, argencard, cabal, censosud, diners, discover, elo, hipercard, jcb, mastercard, naranja, targeta-shopping, union-china-pay, visa. |  |
| width | integer | Image width. Pass an integer between 0 to 2000. Defaults to 100 | 100 |
| height | integer | Image height. Pass an integer between 0 to 2000. Defaults to 100 | 100 |
| quality | integer | Image quality. Pass an integer between 0 to 100. Defaults to 100 | 100 |

## Get Favicon

```http request
GET https://appwrite.io/v1/avatars/favicon
```

** /docs/references/avatars/get-favicon.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| url | string | **Required** Website URL which you want to fetch the favicon from. |  |

## Get Country Flag

```http request
GET https://appwrite.io/v1/avatars/flags/{code}
```

** /docs/references/avatars/get-flag.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| code | string | **Required** Country Code. ISO Alpha-2 country code format. |  |
| width | integer | Image width. Pass an integer between 0 to 2000. Defaults to 100 | 100 |
| height | integer | Image height. Pass an integer between 0 to 2000. Defaults to 100 | 100 |
| quality | integer | Image quality. Pass an integer between 0 to 100. Defaults to 100 | 100 |

## Get Image from URL

```http request
GET https://appwrite.io/v1/avatars/image
```

** /docs/references/avatars/get-image.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| url | string | **Required** Image URL which you want to crop. |  |
| width | integer | Resize preview image width, Pass an integer between 0 to 4000 | 400 |
| height | integer | Resize preview image height, Pass an integer between 0 to 4000 | 400 |

## Text to QR Generator

```http request
GET https://appwrite.io/v1/avatars/qr
```

** /docs/references/avatars/get-qr.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| text | string | **Required** Plain text to be converted to QR code image |  |
| size | integer | QR code size. Pass an integer between 0 to 1000. Defaults to 400. | 400 |
| margin | integer | Margin From Edge. Pass an integer between 0 to 10. Defaults to 1. | 1 |
| download | integer | Return resulting image with &#039;Content-Disposition: attachment &#039; headers for the browser to start downloading it. Pass 0 for no header, or 1 for otherwise. Default value is set to 0. | 0 |

