const Service = require('../service.js');

class Locale extends Service {

    /**
     * Get User Locale
     *
     * /docs/references/locale/get-locale.md
     *
     * @throws Exception
     * @return {}
     */
    async getLocale() {
        let path = '/locale';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * List Countries
     *
     * /docs/references/locale/get-countires.md
     *
     * @throws Exception
     * @return {}
     */
    async getCountries() {
        let path = '/locale/countries';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * List EU Countries
     *
     * /docs/references/locale/get-countries-eu.md
     *
     * @throws Exception
     * @return {}
     */
    async getCountriesEU() {
        let path = '/locale/countries/eu';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * List Countries Phone Codes
     *
     * /docs/references/locale/get-countries-phones.md
     *
     * @throws Exception
     * @return {}
     */
    async getCountriesPhones() {
        let path = '/locale/countries/phones';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * List of currencies
     *
     * /docs/references/locale/get-currencies.md
     *
     * @throws Exception
     * @return {}
     */
    async getCurrencies() {
        let path = '/locale/currencies';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }
}

module.exports = Locale;