# Locale Service

## Get User Locale

```http request
GET https://appwrite.io/v1/locale
```

** Get the current user location based on IP. Returns an object with user country code, country name, continent name, continent code, ip address and suggested currency. You can use the locale header to get the data in a supported language.

([IP Geolocation by DB-IP](https://db-ip.com)) **

## List Continents

```http request
GET https://appwrite.io/v1/locale/continents
```

** List of all continents. You can use the locale header to get the data in a supported language. **

## List Countries

```http request
GET https://appwrite.io/v1/locale/countries
```

** List of all countries. You can use the locale header to get the data in a supported language. **

## List EU Countries

```http request
GET https://appwrite.io/v1/locale/countries/eu
```

** List of all countries that are currently members of the EU. You can use the locale header to get the data in a supported language. **

## List Countries Phone Codes

```http request
GET https://appwrite.io/v1/locale/countries/phones
```

** List of all countries phone codes. You can use the locale header to get the data in a supported language. **

## List Currencies

```http request
GET https://appwrite.io/v1/locale/currencies
```

** List of all currencies, including currency symol, name, plural, and decimal digits for all major and minor currencies. You can use the locale header to get the data in a supported language. **

