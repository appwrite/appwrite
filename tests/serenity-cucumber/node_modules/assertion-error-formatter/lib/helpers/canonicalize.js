"use strict";Object.defineProperty(exports, "__esModule", { value: true });exports.default = canonicalize;var _has_property = _interopRequireDefault(require("./has_property"));
var _type = _interopRequireDefault(require("./type"));function _interopRequireDefault(obj) {return obj && obj.__esModule ? obj : { default: obj };}

function canonicalize(value, stack) {
  stack = stack || [];

  function withStack(fn) {
    stack.push(value);
    const result = fn();
    stack.pop();
    return result;
  }

  if (stack.indexOf(value) !== -1) {
    return '[Circular]';
  }

  switch ((0, _type.default)(value)) {
    case 'array':
      return withStack(function () {
        return value.map(function (item) {
          return canonicalize(item, stack);
        });
      });
    case 'function':
      if (!(0, _has_property.default)(value)) {
        return '[Function]';
      }
    /* falls through */
    case 'object':
      return withStack(function () {
        const canonicalizedObj = {};
        Object.keys(value).
        sort().
        map(function (key) {
          canonicalizedObj[key] = canonicalize(value[key], stack);
        });
        return canonicalizedObj;
      });
    case 'boolean':
    case 'buffer':
    case 'date':
    case 'null':
    case 'number':
    case 'regexp':
    case 'symbol':
    case 'undefined':
      return value;
    default:
      return value.toString();}

}