module.exports = async(context) => {
  context.log(context.req.body.$id);
  context.log(context.req.body.name);
  if (context.req.headers["x-appwrite-execution-delay"]) {
    context.log("execution-delay-is-valid");
  } else {
    context.log("execution-delay-is-invalid");
  }

  if (context.req.headers["x-appwrite-scheduled-at"]) {
    context.log("scheduled-at-is-valid");
  } else {
    context.log("scheduled-at-is-invalid");
  }

  if (context.req.headers["x-appwrite-executed-at"]) {
    context.log("executed-at-is-valid");
  } else {
    context.log("executed-at-is-invalid");
  }
  return context.res.empty();
};
