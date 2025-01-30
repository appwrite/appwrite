import { check } from "k6";
import http from "k6/http";

const amount = 100_000;
const databaseId = "674918b20017411b94b2";
const collectionId = "674918b4002b46c47d5d";

const documents = Array(amount).fill({
    $id: "unique()",
    name: "asd",
});

export default function () {
    const payload = JSON.stringify({
        documents,
    });

    const res = http.post(`http://localhost/v1/databases/${databaseId}/collections/${collectionId}/documents`,
        payload,
        {
            headers: {
                "X-Appwrite-Key": "standard_fa89c4834660f39e95ca2c2996fe7dd4ff498725e37c09323234c009570c1719f1c10610bf3541cf9ead120c107e41397a4eae1c787c83bdf577857bbc5963341641c77f582cc41e11a0d50eb4c2e4b1fda74418a8b9a253d6e63008e33560ba35310b9dc2fed5f09ca599e646f744cc6308b8ccd27ff04f9e498ec5a5f2c3db",
                "X-Appwrite-Project": "674818bc0017934d58dd",
                "Content-Type": "application/json",
            },
        }
    );

    check(res, {
        "status is 200": (r) => r.status === 201,
    });
}
