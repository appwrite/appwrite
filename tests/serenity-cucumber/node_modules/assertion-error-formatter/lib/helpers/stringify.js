"use strict";Object.defineProperty(exports, "__esModule", { value: true });exports.default = stringify;var _canonicalize = _interopRequireDefault(require("./canonicalize"));
var _json_stringify = _interopRequireDefault(require("./json_stringify"));function _interopRequireDefault(obj) {return obj && obj.__esModule ? obj : { default: obj };}

function stringify(value) {
  return (0, _json_stringify.default)((0, _canonicalize.default)(value)).replace(/,(\n|$)/g, '$1');
}