const sdk = require('node-appwrite');

module.exports = async(context) => {
  const key = context.req.headers['x-appwrite-key'];
  const endpoint = process.env.APPWRITE_FUNCTION_API_ENDPOINT;
  const projectId = process.env.APPWRITE_FUNCTION_PROJECT_ID;

  const client = new sdk.Client();
  client.setEndpoint(endpoint);
  client.setProject(projectId);
  client.setKey(key);

  const users = new sdk.Users(client);

  const response = await users.list();
  context.log(JSON.stringify(response));

  // Proves the always-granted health.read scope authorizes a health call
  const health = await fetch(`${endpoint}/health`, {
    headers: {
      'x-appwrite-project': projectId,
      'x-appwrite-key': key,
    },
  });

  // Logged as well as returned, so async executions expose them too
  context.log(`KEY_FOR_TESTS=${key}`);
  context.log(`HEALTH_STATUS_FOR_TESTS=${health.status}`);

  return context.res.json({
    apiKey: key,
    healthStatus: health.status,
    ...response,
  });
};
