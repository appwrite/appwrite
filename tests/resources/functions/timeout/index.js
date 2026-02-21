module.exports = async(context) => {
  await new Promise(resolve => setTimeout(resolve, 1000 * 60));
  return context.res.send('OK');
};
