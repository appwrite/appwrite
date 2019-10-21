package appwrite

import (
)

// Locale service
type Locale struct {
	client *Client
}

// GetLocale get the current user location based on IP. Returns an object with
// user country code, country name, continent name, continent code, ip address
// and suggested currency. You can use the locale header to get the data in a
// supported language.
func (srv *Locale) GetLocale() (map[string]interface{}, error) {
	path := "/locale"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetContinents list of all continents. You can use the locale header to get
// the data in a supported language.
func (srv *Locale) GetContinents() (map[string]interface{}, error) {
	path := "/locale/continents"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetCountries list of all countries. You can use the locale header to get
// the data in a supported language.
func (srv *Locale) GetCountries() (map[string]interface{}, error) {
	path := "/locale/countries"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetCountriesEU list of all countries that are currently members of the EU.
// You can use the locale header to get the data in a supported language. UK
// brexit date is currently set to 2019-10-31 and will be updated if and when
// needed.
func (srv *Locale) GetCountriesEU() (map[string]interface{}, error) {
	path := "/locale/countries/eu"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetCountriesPhones list of all countries phone codes. You can use the
// locale header to get the data in a supported language.
func (srv *Locale) GetCountriesPhones() (map[string]interface{}, error) {
	path := "/locale/countries/phones"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetCurrencies list of all currencies, including currency symol, name,
// plural, and decimal digits for all major and minor currencies. You can use
// the locale header to get the data in a supported language.
func (srv *Locale) GetCurrencies() (map[string]interface{}, error) {
	path := "/locale/currencies"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}
