// https://mathiasbynens.be/notes/javascript-unicode
var regexAstralSymbols = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g;

module.exports = function countSymbols(string) {
  return string.replace(regexAstralSymbols, '_').length;
}
