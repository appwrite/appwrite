"use strict";Object.defineProperty(exports, "__esModule", { value: true });exports.format = format;var _inline_diff = _interopRequireDefault(require("./helpers/inline_diff"));
var _stringify = _interopRequireDefault(require("./helpers/stringify"));
var _type = _interopRequireDefault(require("./helpers/type"));
var _unified_diff = _interopRequireDefault(require("./helpers/unified_diff"));function _interopRequireDefault(obj) {return obj && obj.__esModule ? obj : { default: obj };}

function identity(x) {
  return x;
}

function format(err, options) {
  if (!options) {
    options = {};
  }
  if (!options.colorFns) {
    options.colorFns = {};
  }
  ['diffAdded', 'diffRemoved', 'errorMessage', 'errorStack'].forEach(function (
  key)
  {
    if (!options.colorFns[key]) {
      options.colorFns[key] = identity;
    }
  });

  let message;
  if (err.message && typeof err.message.toString === 'function') {
    message = err.message + '';
  } else if (typeof err.inspect === 'function') {
    message = err.inspect() + '';
  } else if (typeof err === 'string') {
    message = err;
  } else {
    message = JSON.stringify(err);
  }

  let stack = err.stack || message;
  const startOfMessageIndex = stack.indexOf(message);
  if (startOfMessageIndex === -1) {
    stack = '\n' + stack;
  } else {
    const endOfMessageIndex = startOfMessageIndex + message.length;
    message = stack.slice(0, endOfMessageIndex);
    stack = stack.slice(endOfMessageIndex); // remove message from stack
  }

  if (err.uncaught) {
    message = 'Uncaught ' + message;
  }

  let actual = err.actual;
  let expected = err.expected;

  if (
  err.showDiff !== false &&
  (0, _type.default)(actual) === (0, _type.default)(expected) &&
  expected !== undefined)
  {
    if (!((0, _type.default)(actual) === 'string' && (0, _type.default)(expected) === 'string')) {
      actual = (0, _stringify.default)(actual);
      expected = (0, _stringify.default)(expected);
    }

    const match = message.match(/^([^:]+): expected/);
    message = options.colorFns.errorMessage(match ? match[1] : message);

    if (options.inlineDiff) {
      message += (0, _inline_diff.default)(actual, expected, options.colorFns);
    } else {
      message += (0, _unified_diff.default)(actual, expected, options.colorFns);
    }
  } else {
    message = options.colorFns.errorMessage(message);
  }

  if (stack) {
    stack = options.colorFns.errorStack(stack);
  }

  return message + stack;
}