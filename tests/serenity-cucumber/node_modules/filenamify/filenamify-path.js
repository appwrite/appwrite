'use strict';
const path = require('path');
const filenamify = require('./filenamify');

const filenamifyPath = (filePath, options) => {
	filePath = path.resolve(filePath);
	return path.join(path.dirname(filePath), filenamify(path.basename(filePath), options));
};

module.exports = filenamifyPath;
