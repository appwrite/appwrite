var Token = require('./token');
var GherkinLine = require('./gherkin_line');

/**
 * The scanner reads a gherkin doc (typically read from a .feature file) and creates a token for each line. 
 * The tokens are passed to the parser, which outputs an AST (Abstract Syntax Tree).
 * 
 * If the scanner sees a `#` language header, it will reconfigure itself dynamically to look for 
 * Gherkin keywords for the associated language. The keywords are defined in gherkin-languages.json.
 */
module.exports = function TokenScanner(source) {
  var lines = source.split(/\r?\n/);
  if(lines.length > 0 && lines[lines.length-1].trim() == '') {
    lines.pop();
  }
  var lineNumber = 0;

  this.read = function () {
    var line = lines[lineNumber++];
    var location = {line: lineNumber, column: 0};
    return line == null ? new Token(null, location) : new Token(new GherkinLine(line, lineNumber), location);
  }
};
