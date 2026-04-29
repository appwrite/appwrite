const crypto = require('crypto')

module.exports = async(context) => {
  const hash = crypto.createHash('md5').update(context.req.bodyBinary).digest("hex")
  return context.res.send(hash);
};
