'use strict';
const ansiRegex = require('ansi-regex');

const regex = ansiRegex({onlyFirst: true});

module.exports = string => regex.test(string);
