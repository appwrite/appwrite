"use strict";var _type = _interopRequireDefault(require("./type"));
var _mocha = require("mocha");
var _chai = require("chai");function _interopRequireDefault(obj) {return obj && obj.__esModule ? obj : { default: obj };}

const examples = [
{
  description: 'an object',
  input: {},
  output: 'object' },

{
  description: 'an array',
  input: [],
  output: 'array' },

{
  description: 'a number',
  input: 1,
  output: 'number' },

{
  description: 'a boolean',
  input: false,
  output: 'boolean' },

{
  description: 'string',
  input: 'a',
  output: 'string' },

{
  description: 'Infinity',
  input: Infinity,
  output: 'number' },

{
  description: 'null',
  input: null,
  output: 'null' },

{
  description: 'undefined',
  input: undefined,
  output: 'undefined' },

{
  description: 'Date',
  input: new Date(),
  output: 'date' },

{
  description: 'regular expression',
  input: /foo/,
  output: 'regexp' },

{
  description: 'global',
  input: global,
  output: 'global' }];



(0, _mocha.describe)('type', function () {
  examples.forEach(function ({ description, input, output }) {
    (0, _mocha.describe)('input is ' + description, function () {
      (0, _mocha.it)('returns ' + output, function () {
        (0, _chai.expect)((0, _type.default)(input)).to.eql(output);
      });
    });
  });
});