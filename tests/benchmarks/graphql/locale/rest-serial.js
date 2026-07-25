import http from 'k6/http';
import { ENDPOINT } from '../../config.js';
export const options = {
    vus: 20,
    duration: '60s',
};
export default function () {
    http.get(`${ENDPOINT}/locale/countries`, {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get(`${ENDPOINT}/locale/continents`, {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get(`${ENDPOINT}/locale/countries/eu`, {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get(`${ENDPOINT}/locale/countries/phones`, {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
    http.get(`${ENDPOINT}/locale/languages`, {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
        }
    })
}
