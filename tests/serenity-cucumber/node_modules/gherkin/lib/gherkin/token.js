function Token(line, location) {
  this.line = line;
  this.location = location;
  this.isEof = line == null;
};

Token.prototype.getTokenValue = function () {
  return this.isEof ? "EOF" : this.line.getLineText(-1);
};

Token.prototype.detach = function () {
  // TODO: Detach line, but is this really needed?
};

module.exports = Token;
