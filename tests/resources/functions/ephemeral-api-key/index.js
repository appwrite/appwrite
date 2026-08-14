const sdk = require('node-appwrite');

module.exports = async(context) => {
  const client = new sdk.Client();
  client.setEndpoint(process.env.APPWRITE_FUNCTION_API_ENDPOINT);
  client.setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID);
  client.setKey(context.req.headers['x-appwrite-key']);
  
  const users = new sdk.Users(client);
  
  const response = await users.list();
  context.log(JSON.stringify(response));
  
  return context.res.json(response);
};
