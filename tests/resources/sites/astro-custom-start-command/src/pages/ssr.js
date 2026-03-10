export const prerender = false;

export const GET = async () => {
  return new Response("SSR OK (" + Date.now() + ")");
};
