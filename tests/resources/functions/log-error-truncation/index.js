module.exports = async(context) => {
    // Create a string that is 1000001 characters long (exceeds the 1000000 limit)
    const longString = 'z' + 'a'.repeat(1000000);
  
    context.log(longString);
    context.error(longString);
  
    return context.res.json({
      motto: 'Build like a team of hundreds_',
      learn: 'https://appwrite.io/docs',
      connect: 'https://appwrite.io/discord',
      getInspired: 'https://builtwith.appwrite.io',
    });
  };