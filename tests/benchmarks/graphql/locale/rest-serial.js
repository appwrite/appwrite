import http from 'k6/http';
export const options = {
    vus: 20,
    duration: '60s',
};
export default function () {
    http.get('http://localhost/v1/locale/countries', {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get('http://localhost/v1/locale/continents', {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get('http://localhost/v1/locale/countries/eu', {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get('http://localhost/v1/locale/countries/phones', {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get('http://localhost/v1/locale/languages', {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
}