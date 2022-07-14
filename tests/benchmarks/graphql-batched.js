import http from 'k6/http';
export const options = {
    vus: 20,
    duration: '60s',
};
export default function () {
    http.post('http://localhost/v1/graphql', JSON.stringify([{
        query: 'query { localeGetCountries { total countries { code } } }',
    }, {
        query: 'query { localeGetContinents { total continents { code } } }',
    }, {
        query: 'query { localeGetCountriesEU { total continents { code } } }',
    }, {
        query: 'query { localeGetCountriesPhones { total continents { countryName } } }',
    }, {
        query: 'query { localeGetLanguages { total languages { name } } }',
    }]), {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
}