export async function GET({ request }) {
  return Response.json({
    ip: request.headers.get("x-appwrite-client-ip"),
    countryCode: request.headers.get("x-appwrite-country-code"),
    continentEu: request.headers.get("x-appwrite-continent-eu"),
  });
}
