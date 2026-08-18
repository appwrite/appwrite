export async function GET({ request }) {
  const key = request.headers.get("x-appwrite-key") ?? "";

  const users = await fetch(`${process.env.APPWRITE_SITE_API_ENDPOINT}/users`, {
    headers: {
      "x-appwrite-project": process.env.APPWRITE_SITE_PROJECT_ID,
      "x-appwrite-key": key,
    },
  });

  return new Response(
    JSON.stringify({
      apiKey: key,
      users: await users.json(),
    }),
    {
      headers: {
        "Content-Type": "application/json",
      },
    },
  );
}
