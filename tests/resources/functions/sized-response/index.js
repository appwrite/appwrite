// Answers with a body that declares its own content-length: 1 KB on /small,
// and 12 MB on /large, past what the router buffers in memory.
module.exports = async (context) => {
  const size = context.req.path === '/large' ? 12 * 1024 * 1024 : 1024;
  const body = 'a'.repeat(size);
  return context.res.send(body, 200, {
    'content-type': 'text/plain',
    'content-length': String(size),
  });
};
