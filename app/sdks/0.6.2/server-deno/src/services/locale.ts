import { Service } from "../service.ts";
import { DocumentData } from '../client.ts'

export class Locale extends Service {

    /**
     * Get User Locale
     *
     * Get the current user location based on IP. Returns an object with user
     * country code, country name, continent name, continent code, ip address and
     * suggested currency. You can use the locale header to get the data in a
     * supported language.
     * 
     * ([IP Geolocation by DB-IP](https://db-ip.com))
     *
     * @throws Exception
     * @return Promise<string>
     */
    async get(): Promise<string> {
        let path = '/locale';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Continents
     *
     * List of all continents. You can use the locale header to get the data in a
     * supported language.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getContinents(): Promise<string> {
        let path = '/locale/continents';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Countries
     *
     * List of all countries. You can use the locale header to get the data in a
     * supported language.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getCountries(): Promise<string> {
        let path = '/locale/countries';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List EU Countries
     *
     * List of all countries that are currently members of the EU. You can use the
     * locale header to get the data in a supported language.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getCountriesEU(): Promise<string> {
        let path = '/locale/countries/eu';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Countries Phone Codes
     *
     * List of all countries phone codes. You can use the locale header to get the
     * data in a supported language.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getCountriesPhones(): Promise<string> {
        let path = '/locale/countries/phones';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Currencies
     *
     * List of all currencies, including currency symbol, name, plural, and
     * decimal digits for all major and minor currencies. You can use the locale
     * header to get the data in a supported language.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getCurrencies(): Promise<string> {
        let path = '/locale/currencies';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Languages
     *
     * List of all languages classified by ISO 639-1 including 2-letter code, name
     * in English, and name in the respective language.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getLanguages(): Promise<string> {
        let path = '/locale/languages';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}