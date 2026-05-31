module.exports = async (context) => {
  context.log(JSON.stringify(context.req.body));
  return context.res.empty();
};
