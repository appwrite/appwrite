import filenamify = require('./filenamify');

/**
Convert the filename in a path a valid filename and return the augmented path.
*/
declare const filenamifyPath: (path: string, options?: filenamify.Options) => string;

export = filenamifyPath;
