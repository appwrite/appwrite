module.exports = async (context) => {
  await new Promise((resolve) => setTimeout(resolve, 1000));
  return context.res.send('OK');
};
