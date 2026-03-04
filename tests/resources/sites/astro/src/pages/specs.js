export async function GET(_context) {
  return new Response(
    JSON.stringify({
      APPWRITE_SITE_MEMORY: process.env.APPWRITE_SITE_MEMORY,
      APPWRITE_SITE_CPUS: process.env.APPWRITE_SITE_CPUS,
    }),
    {
      headers: {
        "Content-Type": "application/json",
      },
    },
  );
}
