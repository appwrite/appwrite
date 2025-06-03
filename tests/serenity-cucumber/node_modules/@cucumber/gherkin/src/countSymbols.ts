// https://mathiasbynens.be/notes/javascript-unicode
const regexAstralSymbols = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g

export default function countSymbols(s: string) {
  return s.replace(regexAstralSymbols, '_').length
}
