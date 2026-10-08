// Ends the runtime in the middle of the request. The function has run, but the executor gets
// no response from it and answers the worker with an error instead of the function's response.
module.exports = async () => {
  process.exit(1);
};
