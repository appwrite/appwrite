package appwrite

import (
	"encoding/json"
  	"io/ioutil"
	"net/http"
	"net/url"
	"strings"
)

// Client is the client struct to access Appwrite services
type Client struct {
	client     *http.Client
	endpoint   string
	headers    map[string]string
	selfSigned bool
}

// SetEndpoint sets the default endpoint to which the Client connects to
func (clt *Client) SetEndpoint(endpoint string) {
	clt.endpoint = endpoint
}

// SetSelfSigned sets the condition that specify if the Client should allow connections to a server using a self-signed certificate
func (clt *Client) SetSelfSigned(status bool) {
	clt.selfSigned = status
}

// AddHeader add a new custom header that the Client should send on each request
func (clt *Client) AddHeader(key string, value string) {
	clt.headers[key] = value
}

// Your Appwrite project ID
func (clt *Client) SetProject(value string) {
	clt.headers["X-Appwrite-Project"] = value
}

// Your Appwrite project secret key
func (clt *Client) SetKey(value string) {
	clt.headers["X-Appwrite-Key"] = value
}

func (clt *Client) SetLocale(value string) {
	clt.headers["X-Appwrite-Locale"] = value
}

func (clt *Client) SetMode(value string) {
	clt.headers["X-Appwrite-Mode"] = value
}

// Call an API using Client
func (clt *Client) Call(method string, path string, headers map[string]interface{}, params map[string]interface{}) (map[string]interface{}, error) {
	if clt.client == nil {
		// Create HTTP client
		clt.client = &http.Client{}
	}

	if clt.selfSigned {
		// Allow self signed requests
	}

	urlPath := clt.endpoint + path
	isGet := strings.ToUpper(method) == "GET"

	var reqBody *strings.Reader
	if !isGet {
		frm := url.Values{}
		for key, val := range params {
			frm.Add(key, ToString(val))
		}
		reqBody = strings.NewReader(frm.Encode())
	}

	// Create and modify HTTP request before sending
	req, err := http.NewRequest(method, urlPath, reqBody)
	if err != nil {
		return nil, err
	}

	// Set Client headers
	for key, val := range clt.headers {
		req.Header.Set(key, ToString(val))
	}

	// Set Custom headers
	for key, val := range headers {
		req.Header.Set(key, ToString(val))
	}

	if isGet {
		q := req.URL.Query()
		for key, val := range params {
			q.Add(key, ToString(val))
		}
		req.URL.RawQuery = q.Encode()
	}

	// Make request
	response, err := clt.client.Do(req)
	if err != nil {
		return nil, err
	}

	// Handle response
	defer response.Body.Close()
 
	responseData, err := ioutil.ReadAll(response.Body)
	if err != nil {
		return nil, err
	}

	var jsonResponse map[string]interface{}
	json.Unmarshal(responseData, &jsonResponse)

	return jsonResponse, nil
}
