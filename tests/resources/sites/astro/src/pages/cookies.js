export async function GET(context) {
  const sessionId = context.cookies.get("custom-session-id")?.value ?? 'Custom session ID missing';
  const userId = context.cookies.get("custom-user-id")?.value ?? 'Custom user ID missing';

  context.cookies.set('my-cookie-one', 'value-one');
  context.cookies.set('my-cookie-two', 'value-two');

  return new Response(sessionId + ";" + userId);
}
