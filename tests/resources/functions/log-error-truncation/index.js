module.exports = async(context) => {
    // Create a string that is 1000001 characters long (exceeds the 1000000 limit)
    const longString = 'z' + 'a'.repeat(1000000);
  
    // Split the string into chunks of 8000 characters (max limit for each log and error)
    const chunkSize = 8000;
    const chunks = [];
    
    for (let i = 0; i < longString.length; i += chunkSize) {
        chunks.push(longString.slice(i, i + chunkSize));
    }
    
    chunks.forEach((chunk, index) => {
        context.log(chunk);
    });
    
    chunks.forEach((chunk, index) => {
        context.error(chunk);
    });
  
    return context.res.json({
      motto: 'Build like a team of hundreds_',
      learn: 'https://appwrite.io/docs',
      connect: 'https://appwrite.io/discord',
      getInspired: 'https://builtwith.appwrite.io',
    });
  };