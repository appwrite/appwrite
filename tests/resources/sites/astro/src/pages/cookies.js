export async function GET(context) {
  const sessionId = context.cookies.get("custom-session-id")?.value ?? 'Custom session ID missing';
  return new Response(sessionId);
}
