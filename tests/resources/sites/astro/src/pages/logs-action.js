export async function GET(context) {
  console.log("Log1");
  console.error("Error1");
  console.log("Log2");
  console.error("Error2");
	return new Response('Action logs printed.');
}
