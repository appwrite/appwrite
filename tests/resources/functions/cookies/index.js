module.exports = async(context) => {
    return context.res.send(context.req.headers['cookie'] ?? '');
};
