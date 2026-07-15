---
layout: article
title: Phone OTP
description: Learn how Appwrite handles SMS-based OTP authentication for secure user verification.
---

Appwrite supports SMS-based OTP (One-Time Password) authentication to provide secure and reliable user verification. This feature enhances your app's security by adding an extra layer of authentication.

# Free testing {% #free-testing %}

You can use the [Mock phone numbers](/docs/products/auth/security#mock-phone-numbers) feature to test your integrations without incurring any costs.

# SMS messages {% #sms-messages %}

You'll be charged per SMS sent.

The cost for additional messages is calculated based on two factors:
1. The number of messages sent
2. The destination country of each message

As part of our commitment to making Appwrite as accessible as possible, we regularly collaborate with telecom providers to negotiate lower SMS rates. Our goal is to keep costs affordable for all users. However, due to the unique pricing structures of each vendor, our rates may fluctuate from time to time.

## Rates {% #rates %}

SMS rates vary by country due to differences in telecom infrastructure and regulations. Here is a breakdown of the rates:

| Country code | Country name             | Price / SMS (USD) |
|--------------|--------------------------|-------------------|
| +213         | Algeria                  | $ 0.38            |
| +376         | Andorra                  | $ 0.14            |
| +244         | Angola                   | $ 0.15            |
| +54          | Argentina                | $ 0.14            |
| +374         | Armenia                  | $ 0.29            |
| +297         | Aruba                    | $ 0.35            |
| +61          | Australia                | $ 0.05            |
| +43          | Austria                  | $ 0.04            |
| +994         | Azerbaijan               | $ 0.54            |
| +973         | Bahrain                  | $ 0.05            |
| +880         | Bangladesh               | $ 0.62            |
| +375         | Belarus                  | $ 0.32            |
| +32          | Belgium                  | $ 0.14            |
| +501         | Belize                   | $ 0.43            |
| +229         | Benin                    | $ 0.39            |
| +975         | Bhutan                   | $ 0.47            |
| +591         | Bolivia                  | $ 0.31            |
| +387         | Bosnia and Herzegovina   | $ 0.08            |
| +267         | Botswana                 | $ 0.13            |
| +55          | Brazil                   | $ 0.05            |
| +673         | Brunei                   | $ 0.09            |
| +359         | Bulgaria                 | $ 0.23            |
| +226         | Burkina Faso             | $ 0.34            |
| +257         | Burundi                  | $ 0.55            |
| +855         | Cambodia                 | $ 0.57            |
| +237         | Cameroon                 | $ 0.38            |
| +238         | Cape Verde Islands       | $ 0.28            |
| +56          | Chile                    | $ 0.04            |
| +86          | China                    | $ 0.03            |
| +57          | Colombia                 | $ 0.05            |
| +269         | Comoros and Mayotte      | $ 0.54            |
| +242         | Congo                    | $ 0.39            |
| +682         | Cook Islands             | $ 0.17            |
| +506         | Costa Rica               | $ 0.22            |
| +385         | Croatia                  | $ 0.19            |
| +53          | Cuba                     | $ 0.11            |
| +357         | Cyprus                   | $ 0.02            |
| +420         | Czech Republic           | $ 0.09            |
| +45          | Denmark                  | $ 0.08            |
| +253         | Djibouti                 | $ 0.19            |
| +593         | Ecuador                  | $ 0.32            |
| +20          | Egypt                    | $ 0.54            |
| +503         | El Salvador              | $ 0.11            |
| +240         | Equatorial Guinea        | $ 0.28            |
| +291         | Eritrea                  | $ 0.17            |
| +372         | Estonia                  | $ 0.07            |
| +251         | Ethiopia                 | $ 0.51            |
| +500         | Falkland Islands         | $ 0.13            |
| +298         | Faroe Islands            | $ 0.09            |
| +679         | Fiji                     | $ 0.29            |
| +358         | Finland                  | $ 0.12            |
| +33          | France                   | $ 0.10            |
| +594         | French Guiana            | $ 0.19            |
| +689         | French Polynesia         | $ 0.14            |
| +241         | Gabon                    | $ 0.42            |
| +220         | Gambia                   | $ 0.13            |
| +995         | Georgia                  | $ 0.20            |
| +49          | Germany                  | $ 0.14            |
| +233         | Ghana                    | $ 0.45            |
| +350         | Gibraltar                | $ 0.12            |
| +30          | Greece                   | $ 0.07            |
| +299         | Greenland                | $ 0.04            |
| +590         | Guadeloupe               | $ 0.21            |
| +1671        | Guam                     | $ 0.04            |
| +502         | Guatemala                | $ 0.31            |
| +224         | Guinea                   | $ 0.37            |
| +245         | Guinea-Bissau            | $ 0.37            |
| +592         | Guyana                   | $ 0.31            |
| +509         | Haiti                    | $ 0.47            |
| +504         | Honduras                 | $ 0.33            |
| +852         | Hong Kong                | $ 0.08            |
| +36          | Hungary                  | $ 0.10            |
| +354         | Iceland                  | $ 0.10            |
| +91          | India                    | $ 0.003           |
| +62          | Indonesia                | $ 0.58            |
| +98          | Iran                     | $ 0.39            |
| +964         | Iraq                     | $ 0.56            |
| +353         | Ireland                  | $ 0.11            |
| +972         | Israel                   | $ 0.01            |
| +39          | Italy                    | $ 0.07            |
| +81          | Japan                    | $ 0.09            |
| +962         | Jordan                   | $ 0.57            |
| +254         | Kenya                    | $ 0.38            |
| +686         | Kiribati                 | $ 0.08            |
| +850         | North Korea              | $ 0.03            |
| +82          | South Korea              | $ 0.03            |
| +965         | Kuwait                   | $ 0.30            |
| +996         | Kyrgyzstan               | $ 0.49            |
| +856         | Laos                     | $ 0.19            |
| +371         | Latvia                   | $ 0.09            |
| +961         | Lebanon                  | $ 0.42            |
| +266         | Lesotho                  | $ 0.16            |
| +231         | Liberia                  | $ 0.38            |
| +218         | Libya                    | $ 0.60            |
| +423         | Liechtenstein            | $ 0.05            |
| +370         | Lithuania                | $ 0.07            |
| +352         | Luxembourg               | $ 0.13            |
| +853         | Macao                    | $ 0.04            |
| +389         | Macedonia                | $ 0.07            |
| +261         | Madagascar               | $ 0.62            |
| +265         | Malawi                   | $ 0.40            |
| +60          | Malaysia                 | $ 0.40            |
| +960         | Maldives                 | $ 0.44            |
| +223         | Mali                     | $ 0.36            |
| +356         | Malta                    | $ 0.08            |
| +692         | Marshall Islands         | $ 0.03            |
| +596         | Martinique               | $ 0.21            |
| +222         | Mauritania               | $ 0.30            |
| +52          | Mexico                   | $ 0.35            |
| +691         | Micronesia               | $ 0.03            |
| +373         | Moldova                  | $ 0.11            |
| +377         | Monaco                   | $ 0.13            |
| +976         | Mongolia                 | $ 0.46            |
| +212         | Morocco                  | $ 0.36            |
| +258         | Mozambique               | $ 0.29            |
| +95          | Myanmar                  | $ 0.59            |
| +264         | Namibia                  | $ 0.07            |
| +674         | Nauru                    | $ 0.29            |
| +977         | Nepal                    | $ 0.47            |
| +31          | Netherlands              | $ 0.15            |
| +687         | New Caledonia            | $ 0.12            |
| +64          | New Zealand              | $ 0.11            |
| +505         | Nicaragua                | $ 0.21            |
| +227         | Niger                    | $ 0.42            |
| +234         | Nigeria                  | $ 0.61            |
| +683         | Niue                     | $ 0.05            |
| +672         | Norfolk Islands          | $ 0.07            |
| +1           | North America            | $ 0.02            |
| +1670        | Northern Mariana Islands | $ 0.11            |
| +47          | Norway                   | $ 0.11            |
| +968         | Oman                     | $ 0.25            |
| +680         | Palau                    | $ 0.12            |
| +92          | Pakistan                 | $ 0.58            |
| +507         | Panama                   | $ 0.22            |
| +675         | Papua New Guinea         | $ 0.26            |
| +595         | Paraguay                 | $ 0.12            |
| +51          | Peru                     | $ 0.04            |
| +63          | Philippines              | $ 0.34            |
| +48          | Poland                   | $ 0.04            |
| +351         | Portugal                 | $ 0.04            |
| +974         | Qatar                    | $ 0.34            |
| +262         | Reunion                  | $ 0.09            |
| +40          | Romania                  | $ 0.08            |
| +7           | Russia and Kazakhstan    | $ 0.52            |
| +250         | Rwanda                   | $ 0.49            |
| +378         | San Marino               | $ 0.06            |
| +239         | Sao Tome and Principe    | $ 0.11            |
| +966         | Saudi Arabia             | $ 0.33            |
| +221         | Senegal                  | $ 0.46            |
| +381         | Serbia                   | $ 0.46            |
| +248         | Seychelles               | $ 0.43            |
| +232         | Sierra Leone             | $ 0.35            |
| +65          | Singapore                | $ 0.09            |
| +421         | Slovak Republic          | $ 0.09            |
| +386         | Slovenia                 | $ 0.18            |
| +677         | Solomon Islands          | $ 0.12            |
| +252         | Somalia                  | $ 0.24            |
| +27          | South Africa             | $ 0.16            |
| +34          | Spain                    | $ 0.07            |
| +94          | Sri Lanka                | $ 0.60            |
| +290         | St. Helena               | $ 0.06            |
| +249         | Sudan                    | $ 0.48            |
| +597         | Suriname                 | $ 0.27            |
| +268         | Swaziland                | $ 0.13            |
| +46          | Sweden                   | $ 0.09            |
| +41          | Switzerland              | $ 0.06            |
| +963         | Syria                    | $ 0.60            |
| +886         | Taiwan                   | $ 0.08            |
| +992         | Tajikistan               | $ 0.57            |
| +255         | Tanzania                 | $ 0.49            |
| +66          | Thailand                 | $ 0.03            |
| +228         | Togo                     | $ 0.53            |
| +676         | Tonga                    | $ 0.25            |
| +216         | Tunisia                  | $ 0.54            |
| +90          | Turkey                   | $ 0.01            |
| +993         | Turkmenistan             | $ 0.39            |
| +688         | Tuvalu                   | $ 0.13            |
| +256         | Uganda                   | $ 0.41            |
| +380         | Ukraine                  | $ 0.25            |
| +971         | United Arab Emirates     | $ 0.16            |
| +44          | United Kingdom           | $ 0.08            |
| +598         | Uruguay                  | $ 0.11            |
| +998         | Uzbekistan               | $ 0.64            |
| +678         | Vanuatu                  | $ 0.28            |
| +58          | Venezuela                | $ 0.44            |
| +84          | Vietnam                  | $ 0.28            |
| +967         | Yemen                    | $ 0.34            |
| +260         | Zambia                   | $ 0.46            |
| +263         | Zimbabwe                 | $ 0.32            |
