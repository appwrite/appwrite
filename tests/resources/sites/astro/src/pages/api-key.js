export async function GET({ request }) {
  const key = request.headers.get("x-appwrite-key") ?? "";
  const endpoint = process.env.APPWRITE_SITE_API_ENDPOINT;
  const projectId = process.env.APPWRITE_SITE_PROJECT_ID;

  const headers = {
    "x-appwrite-project": projectId,
    "x-appwrite-key": key,
  };

  const users = await fetch(`${endpoint}/users`, { headers });

  // Proves the always-granted health.read scope authorizes a health call
  const health = await fetch(`${endpoint}/health`, { headers });

  return new Response(
    JSON.stringify({
      apiKey: key,
      healthStatus: health.status,
      users: await users.json(),
    }),
    {
      headers: {
        "Content-Type": "application/json",
      },
    },
  );
}
