"use strict";Object.defineProperty(exports, "__esModule", { value: true });exports.default = type;function type(value) {
  if (value === undefined) {
    return 'undefined';
  } else if (value === null) {
    return 'null';
  } else if (typeof Buffer !== 'undefined' && Buffer.isBuffer(value)) {
    return 'buffer';
  }
  return Object.prototype.toString.
  call(value).
  replace(/^\[.+\s(.+?)\]$/, '$1').
  toLowerCase();
}