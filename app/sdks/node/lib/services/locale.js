const Service = require('../service.js');

class Locale extends Service {

    /**
     * Get User Locale
     *
     * Get the current user location based on IP. Returns an object with user
     * country code, country name, continent name, continent code, ip address and
     * suggested currency. You can use the locale header to get the data in a
     * supported language.
     *
     * @throws Exception
     * @return {}
     */
    async getLocale() {
        let path = '/locale';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Countries
     *
     * List of all continents. You can use the locale header to get the data in a
     * supported language.
     *
     * @throws Exception
     * @return {}
     */
    async getContinents() {
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
     * @return {}
     */
    async getCountries() {
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
     * locale header to get the data in a supported language. UK brexit date is
     * currently set to 2019-10-31 and will be updated if and when needed.
     *
     * @throws Exception
     * @return {}
     */
    async getCountriesEU() {
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
     * @return {}
     */
    async getCountriesPhones() {
        let path = '/locale/countries/phones';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List of currencies
     *
     * List of all currencies, including currency symol, name, plural, and decimal
     * digits for all major and minor currencies. You can use the locale header to
     * get the data in a supported language.
     *
     * @throws Exception
     * @return {}
     */
    async getCurrencies() {
        let path = '/locale/currencies';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}

module.exports = Locale;