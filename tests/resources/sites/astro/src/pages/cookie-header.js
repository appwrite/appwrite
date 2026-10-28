export async function GET({ request }) {
  return Response.json({
    cookie: request.headers.get("cookie"),
    fallbackCookies: request.headers.get("x-fallback-cookies"),
  });
}
