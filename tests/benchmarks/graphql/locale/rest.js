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
}