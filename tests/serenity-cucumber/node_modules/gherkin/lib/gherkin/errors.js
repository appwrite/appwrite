var Errors = {};

[
  'ParserException',
  'CompositeParserException',
  'UnexpectedTokenException',
  'UnexpectedEOFException',
  'AstBuilderException',
  'NoSuchLanguageException'
].forEach(function (name) {

  function ErrorProto (message) {
    this.message = message || ('Unspecified ' + name);
    if (Error.captureStackTrace) {
      Error.captureStackTrace(this, arguments.callee);
    }
  }

  ErrorProto.prototype = Object.create(Error.prototype);
  ErrorProto.prototype.name = name;
  ErrorProto.prototype.constructor = ErrorProto;
  Errors[name] = ErrorProto;
});

Errors.CompositeParserException.create = function(errors) {
  var message = "Parser errors:\n" + errors.map(function (e) { return e.message; }).join("\n");
  var err = new Errors.CompositeParserException(message);
  err.errors = errors;
  return err;
};

Errors.UnexpectedTokenException.create = function(token, expectedTokenTypes, stateComment) {
  var message = "expected: " + expectedTokenTypes.join(', ') + ", got '" + token.getTokenValue().trim() + "'";
  var location = !token.location.column
    ? {line: token.location.line, column: token.line.indent + 1 }
    : token.location;
  return createError(Errors.UnexpectedEOFException, message, location);
};

Errors.UnexpectedEOFException.create = function(token, expectedTokenTypes, stateComment) {
  var message = "unexpected end of file, expected: " + expectedTokenTypes.join(', ');
  return createError(Errors.UnexpectedTokenException, message, token.location);
};

Errors.AstBuilderException.create = function(message, location) {
  return createError(Errors.AstBuilderException, message, location);
};

Errors.NoSuchLanguageException.create = function(language, location) {
  var message = "Language not supported: " + language;
  return createError(Errors.NoSuchLanguageException, message, location);
};

function createError(Ctor, message, location) {
  var fullMessage = "(" + location.line + ":" + location.column + "): " + message;
  var error = new Ctor(fullMessage);
  error.location = location;
  return error;
}

module.exports = Errors;
