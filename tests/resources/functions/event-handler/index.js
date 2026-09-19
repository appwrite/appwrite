module.exports = async(context) => {
  context.log(context.req.body.$id);
  context.log(context.req.body.name);
  context.log(JSON.stringify(context.req.body));
  return context.res.empty();
};
