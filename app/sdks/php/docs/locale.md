# Locale Service

## Get User Locale

```http request
GET https://appwrite.test/v1/locale
```

** Get the current user location based on IP. Returns an object with user country code, country name, continent name, continent code, ip address and suggested currency. You can use the locale header to get the data in supported language. **

## List Countries

```http request
GET https://appwrite.test/v1/locale/countries
```

** List of all countries. You can use the locale header to get the data in supported language. **

## List EU Countries

```http request
GET https://appwrite.test/v1/locale/countries/eu
```

** List of all countries that are currently members of the EU. You can use the locale header to get the data in supported language. UK brexit date is currently set to 2019-10-31 and will be updated if and when needed. **

## List Countries Phone Codes

```http request
GET https://appwrite.test/v1/locale/countries/phones
```

** List of all countries phone codes. You can use the locale header to get the data in supported language. **

## List of currencies

```http request
GET https://appwrite.test/v1/locale/currencies
```

** List of all currencies, including currency symol, name, plural, and decimal digits for all major and minor currencies. You can use the locale header to get the data in supported language. **

