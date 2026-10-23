export async function GET({ request }) {
  return Response.json({
    cookie: request.headers.get("cookie"),
  });
}
