"use strict";Object.defineProperty(exports, "__esModule", { value: true });exports.default = jsonStringify;var _repeatString = _interopRequireDefault(require("repeat-string"));
var _type = _interopRequireDefault(require("./type"));function _interopRequireDefault(obj) {return obj && obj.__esModule ? obj : { default: obj };}

function jsonStringify(object, depth) {
  depth = depth || 1;

  switch ((0, _type.default)(object)) {
    case 'boolean':
    case 'regexp':
    case 'symbol':
      return object.toString();
    case 'null':
    case 'undefined':
      return '[' + object + ']';
    case 'array':
    case 'object':
      return jsonStringifyProperties(object, depth);
    case 'number':
      if (object === 0 && 1 / object === -Infinity) {
        return '-0';
      } else {
        return object.toString();
      }
    case 'date':
      return jsonStringifyDate(object);
    case 'buffer':
      return jsonStringifyBuffer(object, depth);
    default:
      if (object === '[Function]' || object === '[Circular]') {
        return object;
      } else {
        return JSON.stringify(object); // string
      }}

}

function jsonStringifyBuffer(object, depth) {
  const { data } = object.toJSON();
  return '[Buffer: ' + jsonStringify(data, depth) + ']';
}

function jsonStringifyDate(object) {
  let str;
  if (isNaN(object.getTime())) {
    str = object.toString();
  } else {
    str = object.toISOString();
  }
  return '[Date: ' + str + ']';
}

function jsonStringifyProperties(object, depth) {
  const space = 2 * depth;
  const start = (0, _type.default)(object) === 'array' ? '[' : '{';
  const end = (0, _type.default)(object) === 'array' ? ']' : '}';
  const length =
  typeof object.length === 'number' ?
  object.length :
  Object.keys(object).length;
  let addedProperties = 0;
  let str = start;

  for (const prop in object) {
    if (Object.prototype.hasOwnProperty.call(object, prop)) {
      addedProperties += 1;
      str +=
      '\n' +
      (0, _repeatString.default)(' ', space) + (
      (0, _type.default)(object) === 'array' ? '' : '"' + prop + '": ') +
      jsonStringify(object[prop], depth + 1) + (
      addedProperties === length ? '' : ',');
    }
  }

  if (str.length !== 1) {
    str += '\n' + (0, _repeatString.default)(' ', space - 2);
  }

  return str + end;
}