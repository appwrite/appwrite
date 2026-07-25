module.exports = async(context) => {
    const bytes = Buffer.from(Uint8Array.from([0, 10, 255]));
    return context.res.binary(bytes);
};
