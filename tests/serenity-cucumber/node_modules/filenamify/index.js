'use strict';
const filenamify = require('./filenamify');
const filenamifyPath = require('./filenamify-path');

const filenamifyCombined = filenamify;
filenamifyCombined.path = filenamifyPath;

module.exports = filenamify;
