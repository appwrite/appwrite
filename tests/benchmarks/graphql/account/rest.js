import http from 'k6/http';
export const options = {
    vus: 20,
    duration: '60s',
};
export default function () {
    http.get('http://localhost/v1/account', {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
            'Cookie': ''
        }
    })
}