export async function GET({ request }) {
  return Response.json({
    ip: request.headers.get("x-appwrite-client-ip"),
  });
}
