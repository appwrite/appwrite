const sdk = require('node-appwrite');

module.exports = async(context) => {
  const client = new sdk.Client();
  client.setEndpoint(process.env.APPWRITE_FUNCTION_API_ENDPOINT);
  client.setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID);

  if ((context.req.query['mode'] ?? '') === 'session-current') {
    client.setJWT(context.req.headers['x-appwrite-user-jwt']);

    try {
      const account = new sdk.Account(client);
      const session = await account.getSession('current');

      return context.res.json(session);
    } catch (error) {
      const code = error.code || error.response?.code || 500;

      return context.res.json({
        code,
        message: error.message || '',
        type: error.type || error.response?.type || '',
      }, code);
    }
  }

  client.setKey(context.req.headers['x-appwrite-key']);
  
  const users = new sdk.Users(client);
  
  const response = await users.list();
  context.log(JSON.stringify(response));
  
  return context.res.json(response);
};
