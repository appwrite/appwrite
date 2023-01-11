import http from 'k6/http';
export const options = {
    vus: 20,
    duration: '60s',
};
export default function () {
    http.post('http://localhost/v1/graphql', JSON.stringify({
        query: `query {
            accountGet {
                _id
                _createdAt
                _updatedAt
                name
                registration
                status
                passwordUpdate
                email
                phone
                emailVerification
                phoneVerification
            }
        }`
    }), {
        headers: {
            'Content-Type': 'application/json',
            'X-Appwrite-Project': 'test',
            'Cookie': ''
        }
    })
}