module.exports = function TokenFormatterBuilder() {
  var tokensText = '';

  this.reset = function () {
    tokensText = '';
  };

  this.startRule = function(ruleType) {};

  this.endRule = function(ruleType) {};

  this.build = function(token) {
    tokensText += formatToken(token) + '\n';
  };

  this.getResult = function() {
    return tokensText;
  }

  function formatToken(token) {
    if(token.isEof) return 'EOF';

    return "(" +
    token.location.line +
    ":" +
    token.location.column +
    ")" +
    token.matchedType +
    ":" +
    (typeof token.matchedKeyword === 'string' ? token.matchedKeyword : '') +
    "/" +
    (typeof token.matchedText === 'string' ? token.matchedText : '') +
    "/" +
    token.matchedItems.map(function (i) { return i.column + ':' + i.text; }).join(',');
  }
};
