module.exports = async(context) => {
    // Create a string that is 1000001 characters long (exceeds the 1000000 limit)
    const longString = 'z' + 'a'.repeat(1000000);
  
    // Throw an error with the long string
    throw new Error(longString);
  };
