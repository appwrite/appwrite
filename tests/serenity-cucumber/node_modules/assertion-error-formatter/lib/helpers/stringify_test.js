"use strict";var _stringify = _interopRequireDefault(require("./stringify"));
var _mocha = require("mocha");
var _chai = require("chai");function _interopRequireDefault(obj) {return obj && obj.__esModule ? obj : { default: obj };}

function functionWithProperties() {}
functionWithProperties.a = 1;

const circularObject = {};
circularObject.a = circularObject;

const circularArray = [];
circularArray.push(circularArray);

const nestedCircular = { a: [{}] };
nestedCircular.a[0].a = nestedCircular;

const examples = [
{
  input: { b: 1, a: 2 },
  inputDescription: 'an object with unsorted keys',
  output: '{\n' + '  "a": 2\n' + '  "b": 1\n' + '}',
  outputDescription: 'the object with sorted keys' },

{
  input() {},
  inputDescription: 'function with not properties',
  output: '[Function]',
  outputDescription: '[Function]' },

{
  input: functionWithProperties,
  inputDescription: 'function with properties',
  output: '{\n' + '  "a": 1\n' + '}',
  outputDescription: 'the object' },

{
  input: circularObject,
  inputDescription: 'circular object',
  output: '{\n' + '  "a": [Circular]\n' + '}',
  outputDescription: 'the circular property as [Circular]' },

{
  input: circularArray,
  inputDescription: 'circular array',
  output: '[\n' + '  [Circular]\n' + ']',
  outputDescription: 'the circular property as [Circular]' },

{
  input: nestedCircular,
  inputDescription: 'nested circular object',
  output:
  '{\n' +
  '  "a": [\n' +
  '    {\n' +
  '      "a": [Circular]\n' +
  '    }\n' +
  '  ]\n' +
  '}',
  outputDescription: 'the circular property as [Circular]' },

{
  input: null,
  inputDescription: 'null',
  output: '[null]',
  outputDescription: '[null]' },

{
  input: undefined,
  inputDescription: 'undefined',
  output: '[undefined]',
  outputDescription: '[undefined]' },

{
  input: -0,
  inputDescription: '-0',
  output: '-0',
  outputDescription: '-0' },

{
  input: new Date(0),
  inputDescription: 'valid date',
  output: '[Date: 1970-01-01T00:00:00.000Z]',
  outputDescription: '[Date <ISOString>]' },

{
  input: new Date(NaN),
  inputDescription: 'invalid date',
  output: '[Date: Invalid Date]',
  outputDescription: '[Date Invalid Date]' },

{
  input: Buffer.from([1, 2, 3]),
  inputDescription: 'buffer',
  output: '[Buffer: [\n' + '  1\n' + '  2\n' + '  3\n' + ']]',
  outputDescription: '[Buffer <stringified data>]' }];



(0, _mocha.describe)('stringify', function () {
  examples.forEach(function ({
    input,
    inputDescription,
    output,
    outputDescription })
  {
    (0, _mocha.describe)('input is ' + inputDescription, function () {
      (0, _mocha.it)('returns ' + outputDescription, function () {
        (0, _chai.expect)((0, _stringify.default)(input)).to.eql(output);
      });
    });
  });
});