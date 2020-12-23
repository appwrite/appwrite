# Avatars Service

## Get Browser Icon

```http request
GET https://appwrite.io/v1/avatars/browsers/{code}
```

** You can use this endpoint to show different browser icons to your users. The code argument receives the browser code as it appears in your user /account/sessions endpoint. Use width, height and quality arguments to change the output settings. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| code | string | **Required** Browser Code. |  |
| width | integer | Image width. Pass an integer between 0 to 2000. Defaults to 100. | 100 |
| height | integer | Image height. Pass an integer between 0 to 2000. Defaults to 100. | 100 |
| quality | integer | Image quality. Pass an integer between 0 to 100. Defaults to 100. | 100 |

## Get Credit Card Icon

```http request
GET https://appwrite.io/v1/avatars/credit-cards/{code}
```

** Need to display your users with your billing method or their payment methods? The credit card endpoint will return you the icon of the credit card provider you need. Use width, height and quality arguments to change the output settings. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| code | string | **Required** Credit Card Code. Possible values: amex, argencard, cabal, censosud, diners, discover, elo, hipercard, jcb, mastercard, naranja, targeta-shopping, union-china-pay, visa. |  |
| width | integer | Image width. Pass an integer between 0 to 2000. Defaults to 100. | 100 |
| height | integer | Image height. Pass an integer between 0 to 2000. Defaults to 100. | 100 |
| quality | integer | Image quality. Pass an integer between 0 to 100. Defaults to 100. | 100 |

## Get Favicon

```http request
GET https://appwrite.io/v1/avatars/favicon
```

** Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote website URL. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| url | string | **Required** Website URL which you want to fetch the favicon from. |  |

## Get Country Flag

```http request
GET https://appwrite.io/v1/avatars/flags/{code}
```

** You can use this endpoint to show different country flags icons to your users. The code argument receives the 2 letter country code. Use width, height and quality arguments to change the output settings. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| code | string | **Required** Country Code. ISO Alpha-2 country code format. |  |
| width | integer | Image width. Pass an integer between 0 to 2000. Defaults to 100. | 100 |
| height | integer | Image height. Pass an integer between 0 to 2000. Defaults to 100. | 100 |
| quality | integer | Image quality. Pass an integer between 0 to 100. Defaults to 100. | 100 |

## Get Image from URL

```http request
GET https://appwrite.io/v1/avatars/image
```

** Use this endpoint to fetch a remote image URL and crop it to any image size you want. This endpoint is very useful if you need to crop and display remote images in your app or in case you want to make sure a 3rd party image is properly served using a TLS protocol. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| url | string | **Required** Image URL which you want to crop. |  |
| width | integer | Resize preview image width, Pass an integer between 0 to 2000. | 400 |
| height | integer | Resize preview image height, Pass an integer between 0 to 2000. | 400 |

## Get QR Code

```http request
GET https://appwrite.io/v1/avatars/qr
```

** Converts a given plain text to a QR code image. You can use the query parameters to change the size and style of the resulting image. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| text | string | **Required** Plain text to be converted to QR code image. |  |
| size | integer | QR code size. Pass an integer between 0 to 1000. Defaults to 400. | 400 |
| margin | integer | Margin from edge. Pass an integer between 0 to 10. Defaults to 1. | 1 |
| download | integer | Return resulting image with &#039;Content-Disposition: attachment &#039; headers for the browser to start downloading it. Pass 0 for no header, or 1 for otherwise. Default value is set to 0. | 0 |

