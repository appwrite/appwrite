# Locale Service

## Get User Locale

```http request
GET https://appwrite.io/v1/locale
```

** Get the current user location based on IP. Returns an object with user country code, country name, continent name, continent code, ip address and suggested currency. You can use the locale header to get the data in supported language. **

## List Countries

```http request
GET https://appwrite.io/v1/locale/countries
```

** List of all countries. You can use the locale header to get the data in supported language. **

## List EU Countries

```http request
GET https://appwrite.io/v1/locale/countries/eu
```

** List of all countries that are currently members of the EU. You can use the locale header to get the data in supported language. **

## List Countries Phone Codes

```http request
GET https://appwrite.io/v1/locale/countries/phones
```

** List of all countries phone codes. You can use the locale header to get the data in supported language. **

