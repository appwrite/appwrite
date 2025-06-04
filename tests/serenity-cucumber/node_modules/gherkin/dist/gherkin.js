/*
The MIT License (MIT)

Copyright (c) Cucumber Ltd, Gaspar Nagy, Björn Rasmusson, Peter Sergeant

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/
(function(){function r(e,n,t){function o(i,f){if(!n[i]){if(!e[i]){var c="function"==typeof require&&require;if(!f&&c)return c(i,!0);if(u)return u(i,!0);var a=new Error("Cannot find module '"+i+"'");throw a.code="MODULE_NOT_FOUND",a}var p=n[i]={exports:{}};e[i][0].call(p.exports,function(r){var n=e[i][1][r];return o(n||r)},p,p.exports,r,e,n,t)}return n[i].exports}for(var u="function"==typeof require&&require,i=0;i<t.length;i++)o(t[i]);return o}return r})()({1:[function(require,module,exports){
(function (factory) {
  if (typeof define === 'function' && define.amd) {
    // AMD. Register as an anonymous module
    define([], factory)
  }
  if (typeof module !== 'undefined' && module.exports) {
    // Node.js/RequireJS
    module.exports = factory();
  }
  if (typeof window === 'object'){
    // Browser globals
    window.Gherkin = factory();
  }
}(function () {
  return {
    Parser: require('./lib/gherkin/parser'),
    TokenScanner: require('./lib/gherkin/token_scanner'),
    TokenMatcher: require('./lib/gherkin/token_matcher'),
    AstBuilder: require('./lib/gherkin/ast_builder'),
    Compiler: require('./lib/gherkin/pickles/compiler'),
    DIALECTS: require('./lib/gherkin/dialects'),
    generateEvents: require('./lib/gherkin/generate_events')
  };
}));

},{"./lib/gherkin/ast_builder":2,"./lib/gherkin/dialects":5,"./lib/gherkin/generate_events":7,"./lib/gherkin/parser":10,"./lib/gherkin/pickles/compiler":11,"./lib/gherkin/token_matcher":13,"./lib/gherkin/token_scanner":14}],2:[function(require,module,exports){
var AstNode = require('./ast_node');
var Errors = require('./errors');

module.exports = function AstBuilder () {

  var stack = [new AstNode('None')];
  var comments = [];

  this.reset = function () {
    stack = [new AstNode('None')];
    comments = [];
  };

  this.startRule = function (ruleType) {
    stack.push(new AstNode(ruleType));
  };

  this.endRule = function (ruleType) {
    var node = stack.pop();
    var transformedNode = transformNode(node);
    currentNode().add(node.ruleType, transformedNode);
  };

  this.build = function (token) {
    if(token.matchedType === 'Comment') {
      comments.push({
        type: 'Comment',
        location: getLocation(token),
        text: token.matchedText
      });
    } else {
      currentNode().add(token.matchedType, token);
    }
  };

  this.getResult = function () {
    return currentNode().getSingle('GherkinDocument');
  };

  function currentNode () {
    return stack[stack.length - 1];
  }

  function getLocation (token, column) {
    return !column ? token.location : {line: token.location.line, column: column};
  }

  function getTags (node) {
    var tags = [];
    var tagsNode = node.getSingle('Tags');
    if (!tagsNode) return tags;
    tagsNode.getTokens('TagLine').forEach(function (token) {
      token.matchedItems.forEach(function (tagItem) {
        tags.push({
          type: 'Tag',
          location: getLocation(token, tagItem.column),
          name: tagItem.text
        });
      });

    });
    return tags;
  }

  function getCells(tableRowToken) {
    return tableRowToken.matchedItems.map(function (cellItem) {
      return {
        type: 'TableCell',
        location: getLocation(tableRowToken, cellItem.column),
        value: cellItem.text
      }
    });
  }

  function getDescription (node) {
    return node.getSingle('Description');
  }

  function getSteps (node) {
    return node.getItems('Step');
  }

  function getTableRows(node) {
    var rows = node.getTokens('TableRow').map(function (token) {
      return {
        type: 'TableRow',
        location: getLocation(token),
        cells: getCells(token)
      };
    });
    ensureCellCount(rows);
    return rows;
  }

  function ensureCellCount(rows) {
    if(rows.length == 0) return;
    var cellCount = rows[0].cells.length;

    rows.forEach(function (row) {
      if (row.cells.length != cellCount) {
        throw Errors.AstBuilderException.create("inconsistent cell count within the table", row.location);
      }
    });
  }

  function transformNode(node) {
    switch(node.ruleType) {
      case 'Step':
        var stepLine = node.getToken('StepLine');
        var stepArgument = node.getSingle('DataTable') || node.getSingle('DocString') || undefined;

        return {
          type: node.ruleType,
          location: getLocation(stepLine),
          keyword: stepLine.matchedKeyword,
          text: stepLine.matchedText,
          argument: stepArgument
        }
      case 'DocString':
        var separatorToken = node.getTokens('DocStringSeparator')[0];
        var contentType = separatorToken.matchedText.length > 0 ? separatorToken.matchedText : undefined;
        var lineTokens = node.getTokens('Other');
        var content = lineTokens.map(function (t) {return t.matchedText}).join("\n");

        var result = {
          type: node.ruleType,
          location: getLocation(separatorToken),
          content: content
        };
        // conditionally add this like this (needed to make tests pass on node 0.10 as well as 4.0)
        if(contentType) {
          result.contentType = contentType;
        }
        return result;
      case 'DataTable':
        var rows = getTableRows(node);
        return {
          type: node.ruleType,
          location: rows[0].location,
          rows: rows,
        }
      case 'Background':
        var backgroundLine = node.getToken('BackgroundLine');
        var description = getDescription(node);
        var steps = getSteps(node);

        return {
          type: node.ruleType,
          location: getLocation(backgroundLine),
          keyword: backgroundLine.matchedKeyword,
          name: backgroundLine.matchedText,
          description: description,
          steps: steps
        };
      case 'Scenario_Definition':
        var tags = getTags(node);
        var scenarioNode = node.getSingle('Scenario');
        if(scenarioNode) {
          var scenarioLine = scenarioNode.getToken('ScenarioLine');
          var description = getDescription(scenarioNode);
          var steps = getSteps(scenarioNode);

          return {
            type: scenarioNode.ruleType,
            tags: tags,
            location: getLocation(scenarioLine),
            keyword: scenarioLine.matchedKeyword,
            name: scenarioLine.matchedText,
            description: description,
            steps: steps
          };
        } else {
          var scenarioOutlineNode = node.getSingle('ScenarioOutline');
          if(!scenarioOutlineNode) throw new Error('Internal grammar error');

          var scenarioOutlineLine = scenarioOutlineNode.getToken('ScenarioOutlineLine');
          var description = getDescription(scenarioOutlineNode);
          var steps = getSteps(scenarioOutlineNode);
          var examples = scenarioOutlineNode.getItems('Examples_Definition');

          return {
            type: scenarioOutlineNode.ruleType,
            tags: tags,
            location: getLocation(scenarioOutlineLine),
            keyword: scenarioOutlineLine.matchedKeyword,
            name: scenarioOutlineLine.matchedText,
            description: description,
            steps: steps,
            examples: examples
          };
        }
      case 'Examples_Definition':
        var tags = getTags(node);
        var examplesNode = node.getSingle('Examples');
        var examplesLine = examplesNode.getToken('ExamplesLine');
        var description = getDescription(examplesNode);
        var exampleTable = examplesNode.getSingle('Examples_Table')

        return {
          type: examplesNode.ruleType,
          tags: tags,
          location: getLocation(examplesLine),
          keyword: examplesLine.matchedKeyword,
          name: examplesLine.matchedText,
          description: description,
          tableHeader: exampleTable != undefined ? exampleTable.tableHeader : undefined,
          tableBody: exampleTable != undefined ? exampleTable.tableBody : undefined
        };
      case 'Examples_Table':
        var rows = getTableRows(node)

        return {
          tableHeader: rows != undefined ? rows[0] : undefined,
          tableBody: rows != undefined ? rows.slice(1) : undefined
        };
      case 'Description':
        var lineTokens = node.getTokens('Other');
        // Trim trailing empty lines
        var end = lineTokens.length;
        while (end > 0 && lineTokens[end-1].line.trimmedLineText === '') {
            end--;
        }
        lineTokens = lineTokens.slice(0, end);

        var description = lineTokens.map(function (token) { return token.matchedText}).join("\n");
        return description;

      case 'Feature':
        var header = node.getSingle('Feature_Header');
        if(!header) return null;
        var tags = getTags(header);
        var featureLine = header.getToken('FeatureLine');
        if(!featureLine) return null;
        var children = []
        var background = node.getSingle('Background');
        if(background) children.push(background);
        children = children.concat(node.getItems('Scenario_Definition'));
        var description = getDescription(header);
        var language = featureLine.matchedGherkinDialect;

        return {
          type: node.ruleType,
          tags: tags,
          location: getLocation(featureLine),
          language: language,
          keyword: featureLine.matchedKeyword,
          name: featureLine.matchedText,
          description: description,
          children: children,
        };
      case 'GherkinDocument':
        var feature = node.getSingle('Feature');

        return {
          type: node.ruleType,
          feature: feature,
          comments: comments
        };
      default:
        return node;
    }
  }

};

},{"./ast_node":3,"./errors":6}],3:[function(require,module,exports){
function AstNode (ruleType) {
  this.ruleType = ruleType;
  this._subItems = {};
}

AstNode.prototype.add = function (ruleType, obj) {
  var items = this._subItems[ruleType];
  if(items === undefined) this._subItems[ruleType] = items = [];
  items.push(obj);
}

AstNode.prototype.getSingle = function (ruleType) {
  return (this._subItems[ruleType] || [])[0];
}

AstNode.prototype.getItems = function (ruleType) {
  return this._subItems[ruleType] || [];
}

AstNode.prototype.getToken = function (tokenType) {
  return this.getSingle(tokenType);
}

AstNode.prototype.getTokens = function (tokenType) {
  return this._subItems[tokenType] || [];
}

module.exports = AstNode;

},{}],4:[function(require,module,exports){
// https://mathiasbynens.be/notes/javascript-unicode
var regexAstralSymbols = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g;

module.exports = function countSymbols(string) {
  return string.replace(regexAstralSymbols, '_').length;
}

},{}],5:[function(require,module,exports){
module.exports = require('./gherkin-languages.json');

},{"./gherkin-languages.json":8}],6:[function(require,module,exports){
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

},{}],7:[function(require,module,exports){
var Parser = require('./parser')
var Compiler = require('./pickles/compiler')

var compiler = new Compiler()
var parser = new Parser()
parser.stopAtFirstError = false

function generateEvents(data, uri, types, language) {
  types = Object.assign({
    'source': true,
    'gherkin-document': true,
    'pickle': true
  }, types || {})

  result = []

  try {
    if (types['source']) {
      result.push({
        type: 'source',
        uri: uri,
        data: data,
        media: {
          encoding: 'utf-8',
          type: 'text/x.cucumber.gherkin+plain'
        }
      })
    }

    if (!types['gherkin-document'] && !types['pickle'])
      return result

    var gherkinDocument = parser.parse(data, language)

    if (types['gherkin-document']) {
      result.push({
        type: 'gherkin-document',
        uri: uri,
        document: gherkinDocument
      })
    }

    if (types['pickle']) {
      var pickles = compiler.compile(gherkinDocument)
      for (var p in pickles) {
        result.push({
          type: 'pickle',
          uri: uri,
          pickle: pickles[p]
        })
      }
    }
  } catch (err) {
    var errors = err.errors || [err]
    for (var e in errors) {
      result.push({
        type: "attachment",
        source: {
          uri: uri,
          start: {
            line: errors[e].location.line,
            column: errors[e].location.column
          }
        },
        data: errors[e].message,
        media: {
          encoding: "utf-8",
          type: "text/x.cucumber.stacktrace+plain"
        }
      })
    }
  }
  return result
}

module.exports = generateEvents

},{"./parser":10,"./pickles/compiler":11}],8:[function(require,module,exports){
module.exports={
  "af": {
    "and": [
      "* ",
      "En "
    ],
    "background": [
      "Agtergrond"
    ],
    "but": [
      "* ",
      "Maar "
    ],
    "examples": [
      "Voorbeelde"
    ],
    "feature": [
      "Funksie",
      "Besigheid Behoefte",
      "Vermoë"
    ],
    "given": [
      "* ",
      "Gegewe "
    ],
    "name": "Afrikaans",
    "native": "Afrikaans",
    "scenario": [
      "Situasie"
    ],
    "scenarioOutline": [
      "Situasie Uiteensetting"
    ],
    "then": [
      "* ",
      "Dan "
    ],
    "when": [
      "* ",
      "Wanneer "
    ]
  },
  "am": {
    "and": [
      "* ",
      "Եվ "
    ],
    "background": [
      "Կոնտեքստ"
    ],
    "but": [
      "* ",
      "Բայց "
    ],
    "examples": [
      "Օրինակներ"
    ],
    "feature": [
      "Ֆունկցիոնալություն",
      "Հատկություն"
    ],
    "given": [
      "* ",
      "Դիցուք "
    ],
    "name": "Armenian",
    "native": "հայերեն",
    "scenario": [
      "Սցենար"
    ],
    "scenarioOutline": [
      "Սցենարի կառուցվացքը"
    ],
    "then": [
      "* ",
      "Ապա "
    ],
    "when": [
      "* ",
      "Եթե ",
      "Երբ "
    ]
  },
  "an": {
    "and": [
      "* ",
      "Y ",
      "E "
    ],
    "background": [
      "Antecedents"
    ],
    "but": [
      "* ",
      "Pero "
    ],
    "examples": [
      "Eixemplos"
    ],
    "feature": [
      "Caracteristica"
    ],
    "given": [
      "* ",
      "Dau ",
      "Dada ",
      "Daus ",
      "Dadas "
    ],
    "name": "Aragonese",
    "native": "Aragonés",
    "scenario": [
      "Caso"
    ],
    "scenarioOutline": [
      "Esquema del caso"
    ],
    "then": [
      "* ",
      "Alavez ",
      "Allora ",
      "Antonces "
    ],
    "when": [
      "* ",
      "Cuan "
    ]
  },
  "ar": {
    "and": [
      "* ",
      "و "
    ],
    "background": [
      "الخلفية"
    ],
    "but": [
      "* ",
      "لكن "
    ],
    "examples": [
      "امثلة"
    ],
    "feature": [
      "خاصية"
    ],
    "given": [
      "* ",
      "بفرض "
    ],
    "name": "Arabic",
    "native": "العربية",
    "scenario": [
      "سيناريو"
    ],
    "scenarioOutline": [
      "سيناريو مخطط"
    ],
    "then": [
      "* ",
      "اذاً ",
      "ثم "
    ],
    "when": [
      "* ",
      "متى ",
      "عندما "
    ]
  },
  "ast": {
    "and": [
      "* ",
      "Y ",
      "Ya "
    ],
    "background": [
      "Antecedentes"
    ],
    "but": [
      "* ",
      "Peru "
    ],
    "examples": [
      "Exemplos"
    ],
    "feature": [
      "Carauterística"
    ],
    "given": [
      "* ",
      "Dáu ",
      "Dada ",
      "Daos ",
      "Daes "
    ],
    "name": "Asturian",
    "native": "asturianu",
    "scenario": [
      "Casu"
    ],
    "scenarioOutline": [
      "Esbozu del casu"
    ],
    "then": [
      "* ",
      "Entós "
    ],
    "when": [
      "* ",
      "Cuando "
    ]
  },
  "az": {
    "and": [
      "* ",
      "Və ",
      "Həm "
    ],
    "background": [
      "Keçmiş",
      "Kontekst"
    ],
    "but": [
      "* ",
      "Amma ",
      "Ancaq "
    ],
    "examples": [
      "Nümunələr"
    ],
    "feature": [
      "Özəllik"
    ],
    "given": [
      "* ",
      "Tutaq ki ",
      "Verilir "
    ],
    "name": "Azerbaijani",
    "native": "Azərbaycanca",
    "scenario": [
      "Ssenari"
    ],
    "scenarioOutline": [
      "Ssenarinin strukturu"
    ],
    "then": [
      "* ",
      "O halda "
    ],
    "when": [
      "* ",
      "Əgər ",
      "Nə vaxt ki "
    ]
  },
  "bg": {
    "and": [
      "* ",
      "И "
    ],
    "background": [
      "Предистория"
    ],
    "but": [
      "* ",
      "Но "
    ],
    "examples": [
      "Примери"
    ],
    "feature": [
      "Функционалност"
    ],
    "given": [
      "* ",
      "Дадено "
    ],
    "name": "Bulgarian",
    "native": "български",
    "scenario": [
      "Сценарий"
    ],
    "scenarioOutline": [
      "Рамка на сценарий"
    ],
    "then": [
      "* ",
      "То "
    ],
    "when": [
      "* ",
      "Когато "
    ]
  },
  "bm": {
    "and": [
      "* ",
      "Dan "
    ],
    "background": [
      "Latar Belakang"
    ],
    "but": [
      "* ",
      "Tetapi ",
      "Tapi "
    ],
    "examples": [
      "Contoh"
    ],
    "feature": [
      "Fungsi"
    ],
    "given": [
      "* ",
      "Diberi ",
      "Bagi "
    ],
    "name": "Malay",
    "native": "Bahasa Melayu",
    "scenario": [
      "Senario",
      "Situasi",
      "Keadaan"
    ],
    "scenarioOutline": [
      "Kerangka Senario",
      "Kerangka Situasi",
      "Kerangka Keadaan",
      "Garis Panduan Senario"
    ],
    "then": [
      "* ",
      "Maka ",
      "Kemudian "
    ],
    "when": [
      "* ",
      "Apabila "
    ]
  },
  "bs": {
    "and": [
      "* ",
      "I ",
      "A "
    ],
    "background": [
      "Pozadina"
    ],
    "but": [
      "* ",
      "Ali "
    ],
    "examples": [
      "Primjeri"
    ],
    "feature": [
      "Karakteristika"
    ],
    "given": [
      "* ",
      "Dato "
    ],
    "name": "Bosnian",
    "native": "Bosanski",
    "scenario": [
      "Scenariju",
      "Scenario"
    ],
    "scenarioOutline": [
      "Scenariju-obris",
      "Scenario-outline"
    ],
    "then": [
      "* ",
      "Zatim "
    ],
    "when": [
      "* ",
      "Kada "
    ]
  },
  "ca": {
    "and": [
      "* ",
      "I "
    ],
    "background": [
      "Rerefons",
      "Antecedents"
    ],
    "but": [
      "* ",
      "Però "
    ],
    "examples": [
      "Exemples"
    ],
    "feature": [
      "Característica",
      "Funcionalitat"
    ],
    "given": [
      "* ",
      "Donat ",
      "Donada ",
      "Atès ",
      "Atesa "
    ],
    "name": "Catalan",
    "native": "català",
    "scenario": [
      "Escenari"
    ],
    "scenarioOutline": [
      "Esquema de l'escenari"
    ],
    "then": [
      "* ",
      "Aleshores ",
      "Cal "
    ],
    "when": [
      "* ",
      "Quan "
    ]
  },
  "cs": {
    "and": [
      "* ",
      "A také ",
      "A "
    ],
    "background": [
      "Pozadí",
      "Kontext"
    ],
    "but": [
      "* ",
      "Ale "
    ],
    "examples": [
      "Příklady"
    ],
    "feature": [
      "Požadavek"
    ],
    "given": [
      "* ",
      "Pokud ",
      "Za předpokladu "
    ],
    "name": "Czech",
    "native": "Česky",
    "scenario": [
      "Scénář"
    ],
    "scenarioOutline": [
      "Náčrt Scénáře",
      "Osnova scénáře"
    ],
    "then": [
      "* ",
      "Pak "
    ],
    "when": [
      "* ",
      "Když "
    ]
  },
  "cy-GB": {
    "and": [
      "* ",
      "A "
    ],
    "background": [
      "Cefndir"
    ],
    "but": [
      "* ",
      "Ond "
    ],
    "examples": [
      "Enghreifftiau"
    ],
    "feature": [
      "Arwedd"
    ],
    "given": [
      "* ",
      "Anrhegedig a "
    ],
    "name": "Welsh",
    "native": "Cymraeg",
    "scenario": [
      "Scenario"
    ],
    "scenarioOutline": [
      "Scenario Amlinellol"
    ],
    "then": [
      "* ",
      "Yna "
    ],
    "when": [
      "* ",
      "Pryd "
    ]
  },
  "da": {
    "and": [
      "* ",
      "Og "
    ],
    "background": [
      "Baggrund"
    ],
    "but": [
      "* ",
      "Men "
    ],
    "examples": [
      "Eksempler"
    ],
    "feature": [
      "Egenskab"
    ],
    "given": [
      "* ",
      "Givet "
    ],
    "name": "Danish",
    "native": "dansk",
    "scenario": [
      "Scenarie"
    ],
    "scenarioOutline": [
      "Abstrakt Scenario"
    ],
    "then": [
      "* ",
      "Så "
    ],
    "when": [
      "* ",
      "Når "
    ]
  },
  "de": {
    "and": [
      "* ",
      "Und "
    ],
    "background": [
      "Grundlage"
    ],
    "but": [
      "* ",
      "Aber "
    ],
    "examples": [
      "Beispiele"
    ],
    "feature": [
      "Funktionalität"
    ],
    "given": [
      "* ",
      "Angenommen ",
      "Gegeben sei ",
      "Gegeben seien "
    ],
    "name": "German",
    "native": "Deutsch",
    "scenario": [
      "Szenario"
    ],
    "scenarioOutline": [
      "Szenariogrundriss"
    ],
    "then": [
      "* ",
      "Dann "
    ],
    "when": [
      "* ",
      "Wenn "
    ]
  },
  "el": {
    "and": [
      "* ",
      "Και "
    ],
    "background": [
      "Υπόβαθρο"
    ],
    "but": [
      "* ",
      "Αλλά "
    ],
    "examples": [
      "Παραδείγματα",
      "Σενάρια"
    ],
    "feature": [
      "Δυνατότητα",
      "Λειτουργία"
    ],
    "given": [
      "* ",
      "Δεδομένου "
    ],
    "name": "Greek",
    "native": "Ελληνικά",
    "scenario": [
      "Σενάριο"
    ],
    "scenarioOutline": [
      "Περιγραφή Σεναρίου",
      "Περίγραμμα Σεναρίου"
    ],
    "then": [
      "* ",
      "Τότε "
    ],
    "when": [
      "* ",
      "Όταν "
    ]
  },
  "em": {
    "and": [
      "* ",
      "😂"
    ],
    "background": [
      "💤"
    ],
    "but": [
      "* ",
      "😔"
    ],
    "examples": [
      "📓"
    ],
    "feature": [
      "📚"
    ],
    "given": [
      "* ",
      "😐"
    ],
    "name": "Emoji",
    "native": "😀",
    "scenario": [
      "📕"
    ],
    "scenarioOutline": [
      "📖"
    ],
    "then": [
      "* ",
      "🙏"
    ],
    "when": [
      "* ",
      "🎬"
    ]
  },
  "en": {
    "and": [
      "* ",
      "And "
    ],
    "background": [
      "Background"
    ],
    "but": [
      "* ",
      "But "
    ],
    "examples": [
      "Examples",
      "Scenarios"
    ],
    "feature": [
      "Feature",
      "Business Need",
      "Ability"
    ],
    "given": [
      "* ",
      "Given "
    ],
    "name": "English",
    "native": "English",
    "scenario": [
      "Scenario"
    ],
    "scenarioOutline": [
      "Scenario Outline",
      "Scenario Template"
    ],
    "then": [
      "* ",
      "Then "
    ],
    "when": [
      "* ",
      "When "
    ]
  },
  "en-Scouse": {
    "and": [
      "* ",
      "An "
    ],
    "background": [
      "Dis is what went down"
    ],
    "but": [
      "* ",
      "Buh "
    ],
    "examples": [
      "Examples"
    ],
    "feature": [
      "Feature"
    ],
    "given": [
      "* ",
      "Givun ",
      "Youse know when youse got "
    ],
    "name": "Scouse",
    "native": "Scouse",
    "scenario": [
      "The thing of it is"
    ],
    "scenarioOutline": [
      "Wharrimean is"
    ],
    "then": [
      "* ",
      "Dun ",
      "Den youse gotta "
    ],
    "when": [
      "* ",
      "Wun ",
      "Youse know like when "
    ]
  },
  "en-au": {
    "and": [
      "* ",
      "Too right "
    ],
    "background": [
      "First off"
    ],
    "but": [
      "* ",
      "Yeah nah "
    ],
    "examples": [
      "You'll wanna"
    ],
    "feature": [
      "Pretty much"
    ],
    "given": [
      "* ",
      "Y'know "
    ],
    "name": "Australian",
    "native": "Australian",
    "scenario": [
      "Awww, look mate"
    ],
    "scenarioOutline": [
      "Reckon it's like"
    ],
    "then": [
      "* ",
      "But at the end of the day I reckon "
    ],
    "when": [
      "* ",
      "It's just unbelievable "
    ]
  },
  "en-lol": {
    "and": [
      "* ",
      "AN "
    ],
    "background": [
      "B4"
    ],
    "but": [
      "* ",
      "BUT "
    ],
    "examples": [
      "EXAMPLZ"
    ],
    "feature": [
      "OH HAI"
    ],
    "given": [
      "* ",
      "I CAN HAZ "
    ],
    "name": "LOLCAT",
    "native": "LOLCAT",
    "scenario": [
      "MISHUN"
    ],
    "scenarioOutline": [
      "MISHUN SRSLY"
    ],
    "then": [
      "* ",
      "DEN "
    ],
    "when": [
      "* ",
      "WEN "
    ]
  },
  "en-old": {
    "and": [
      "* ",
      "Ond ",
      "7 "
    ],
    "background": [
      "Aer",
      "Ær"
    ],
    "but": [
      "* ",
      "Ac "
    ],
    "examples": [
      "Se the",
      "Se þe",
      "Se ðe"
    ],
    "feature": [
      "Hwaet",
      "Hwæt"
    ],
    "given": [
      "* ",
      "Thurh ",
      "Þurh ",
      "Ðurh "
    ],
    "name": "Old English",
    "native": "Englisc",
    "scenario": [
      "Swa"
    ],
    "scenarioOutline": [
      "Swa hwaer swa",
      "Swa hwær swa"
    ],
    "then": [
      "* ",
      "Tha ",
      "Þa ",
      "Ða ",
      "Tha the ",
      "Þa þe ",
      "Ða ðe "
    ],
    "when": [
      "* ",
      "Tha ",
      "Þa ",
      "Ða "
    ]
  },
  "en-pirate": {
    "and": [
      "* ",
      "Aye "
    ],
    "background": [
      "Yo-ho-ho"
    ],
    "but": [
      "* ",
      "Avast! "
    ],
    "examples": [
      "Dead men tell no tales"
    ],
    "feature": [
      "Ahoy matey!"
    ],
    "given": [
      "* ",
      "Gangway! "
    ],
    "name": "Pirate",
    "native": "Pirate",
    "scenario": [
      "Heave to"
    ],
    "scenarioOutline": [
      "Shiver me timbers"
    ],
    "then": [
      "* ",
      "Let go and haul "
    ],
    "when": [
      "* ",
      "Blimey! "
    ]
  },
  "eo": {
    "and": [
      "* ",
      "Kaj "
    ],
    "background": [
      "Fono"
    ],
    "but": [
      "* ",
      "Sed "
    ],
    "examples": [
      "Ekzemploj"
    ],
    "feature": [
      "Trajto"
    ],
    "given": [
      "* ",
      "Donitaĵo ",
      "Komence "
    ],
    "name": "Esperanto",
    "native": "Esperanto",
    "scenario": [
      "Scenaro",
      "Kazo"
    ],
    "scenarioOutline": [
      "Konturo de la scenaro",
      "Skizo",
      "Kazo-skizo"
    ],
    "then": [
      "* ",
      "Do "
    ],
    "when": [
      "* ",
      "Se "
    ]
  },
  "es": {
    "and": [
      "* ",
      "Y ",
      "E "
    ],
    "background": [
      "Antecedentes"
    ],
    "but": [
      "* ",
      "Pero "
    ],
    "examples": [
      "Ejemplos"
    ],
    "feature": [
      "Característica"
    ],
    "given": [
      "* ",
      "Dado ",
      "Dada ",
      "Dados ",
      "Dadas "
    ],
    "name": "Spanish",
    "native": "español",
    "scenario": [
      "Escenario"
    ],
    "scenarioOutline": [
      "Esquema del escenario"
    ],
    "then": [
      "* ",
      "Entonces "
    ],
    "when": [
      "* ",
      "Cuando "
    ]
  },
  "et": {
    "and": [
      "* ",
      "Ja "
    ],
    "background": [
      "Taust"
    ],
    "but": [
      "* ",
      "Kuid "
    ],
    "examples": [
      "Juhtumid"
    ],
    "feature": [
      "Omadus"
    ],
    "given": [
      "* ",
      "Eeldades "
    ],
    "name": "Estonian",
    "native": "eesti keel",
    "scenario": [
      "Stsenaarium"
    ],
    "scenarioOutline": [
      "Raamstsenaarium"
    ],
    "then": [
      "* ",
      "Siis "
    ],
    "when": [
      "* ",
      "Kui "
    ]
  },
  "fa": {
    "and": [
      "* ",
      "و "
    ],
    "background": [
      "زمینه"
    ],
    "but": [
      "* ",
      "اما "
    ],
    "examples": [
      "نمونه ها"
    ],
    "feature": [
      "وِیژگی"
    ],
    "given": [
      "* ",
      "با فرض "
    ],
    "name": "Persian",
    "native": "فارسی",
    "scenario": [
      "سناریو"
    ],
    "scenarioOutline": [
      "الگوی سناریو"
    ],
    "then": [
      "* ",
      "آنگاه "
    ],
    "when": [
      "* ",
      "هنگامی "
    ]
  },
  "fi": {
    "and": [
      "* ",
      "Ja "
    ],
    "background": [
      "Tausta"
    ],
    "but": [
      "* ",
      "Mutta "
    ],
    "examples": [
      "Tapaukset"
    ],
    "feature": [
      "Ominaisuus"
    ],
    "given": [
      "* ",
      "Oletetaan "
    ],
    "name": "Finnish",
    "native": "suomi",
    "scenario": [
      "Tapaus"
    ],
    "scenarioOutline": [
      "Tapausaihio"
    ],
    "then": [
      "* ",
      "Niin "
    ],
    "when": [
      "* ",
      "Kun "
    ]
  },
  "fr": {
    "and": [
      "* ",
      "Et que ",
      "Et qu'",
      "Et "
    ],
    "background": [
      "Contexte"
    ],
    "but": [
      "* ",
      "Mais que ",
      "Mais qu'",
      "Mais "
    ],
    "examples": [
      "Exemples"
    ],
    "feature": [
      "Fonctionnalité"
    ],
    "given": [
      "* ",
      "Soit ",
      "Etant donné que ",
      "Etant donné qu'",
      "Etant donné ",
      "Etant donnée ",
      "Etant donnés ",
      "Etant données ",
      "Étant donné que ",
      "Étant donné qu'",
      "Étant donné ",
      "Étant donnée ",
      "Étant donnés ",
      "Étant données "
    ],
    "name": "French",
    "native": "français",
    "scenario": [
      "Scénario"
    ],
    "scenarioOutline": [
      "Plan du scénario",
      "Plan du Scénario"
    ],
    "then": [
      "* ",
      "Alors "
    ],
    "when": [
      "* ",
      "Quand ",
      "Lorsque ",
      "Lorsqu'"
    ]
  },
  "ga": {
    "and": [
      "* ",
      "Agus"
    ],
    "background": [
      "Cúlra"
    ],
    "but": [
      "* ",
      "Ach"
    ],
    "examples": [
      "Samplaí"
    ],
    "feature": [
      "Gné"
    ],
    "given": [
      "* ",
      "Cuir i gcás go",
      "Cuir i gcás nach",
      "Cuir i gcás gur",
      "Cuir i gcás nár"
    ],
    "name": "Irish",
    "native": "Gaeilge",
    "scenario": [
      "Cás"
    ],
    "scenarioOutline": [
      "Cás Achomair"
    ],
    "then": [
      "* ",
      "Ansin"
    ],
    "when": [
      "* ",
      "Nuair a",
      "Nuair nach",
      "Nuair ba",
      "Nuair nár"
    ]
  },
  "gj": {
    "and": [
      "* ",
      "અને "
    ],
    "background": [
      "બેકગ્રાઉન્ડ"
    ],
    "but": [
      "* ",
      "પણ "
    ],
    "examples": [
      "ઉદાહરણો"
    ],
    "feature": [
      "લક્ષણ",
      "વ્યાપાર જરૂર",
      "ક્ષમતા"
    ],
    "given": [
      "* ",
      "આપેલ છે "
    ],
    "name": "Gujarati",
    "native": "ગુજરાતી",
    "scenario": [
      "સ્થિતિ"
    ],
    "scenarioOutline": [
      "પરિદ્દશ્ય રૂપરેખા",
      "પરિદ્દશ્ય ઢાંચો"
    ],
    "then": [
      "* ",
      "પછી "
    ],
    "when": [
      "* ",
      "ક્યારે "
    ]
  },
  "gl": {
    "and": [
      "* ",
      "E "
    ],
    "background": [
      "Contexto"
    ],
    "but": [
      "* ",
      "Mais ",
      "Pero "
    ],
    "examples": [
      "Exemplos"
    ],
    "feature": [
      "Característica"
    ],
    "given": [
      "* ",
      "Dado ",
      "Dada ",
      "Dados ",
      "Dadas "
    ],
    "name": "Galician",
    "native": "galego",
    "scenario": [
      "Escenario"
    ],
    "scenarioOutline": [
      "Esbozo do escenario"
    ],
    "then": [
      "* ",
      "Entón ",
      "Logo "
    ],
    "when": [
      "* ",
      "Cando "
    ]
  },
  "he": {
    "and": [
      "* ",
      "וגם "
    ],
    "background": [
      "רקע"
    ],
    "but": [
      "* ",
      "אבל "
    ],
    "examples": [
      "דוגמאות"
    ],
    "feature": [
      "תכונה"
    ],
    "given": [
      "* ",
      "בהינתן "
    ],
    "name": "Hebrew",
    "native": "עברית",
    "scenario": [
      "תרחיש"
    ],
    "scenarioOutline": [
      "תבנית תרחיש"
    ],
    "then": [
      "* ",
      "אז ",
      "אזי "
    ],
    "when": [
      "* ",
      "כאשר "
    ]
  },
  "hi": {
    "and": [
      "* ",
      "और ",
      "तथा "
    ],
    "background": [
      "पृष्ठभूमि"
    ],
    "but": [
      "* ",
      "पर ",
      "परन्तु ",
      "किन्तु "
    ],
    "examples": [
      "उदाहरण"
    ],
    "feature": [
      "रूप लेख"
    ],
    "given": [
      "* ",
      "अगर ",
      "यदि ",
      "चूंकि "
    ],
    "name": "Hindi",
    "native": "हिंदी",
    "scenario": [
      "परिदृश्य"
    ],
    "scenarioOutline": [
      "परिदृश्य रूपरेखा"
    ],
    "then": [
      "* ",
      "तब ",
      "तदा "
    ],
    "when": [
      "* ",
      "जब ",
      "कदा "
    ]
  },
  "hr": {
    "and": [
      "* ",
      "I "
    ],
    "background": [
      "Pozadina"
    ],
    "but": [
      "* ",
      "Ali "
    ],
    "examples": [
      "Primjeri",
      "Scenariji"
    ],
    "feature": [
      "Osobina",
      "Mogućnost",
      "Mogucnost"
    ],
    "given": [
      "* ",
      "Zadan ",
      "Zadani ",
      "Zadano "
    ],
    "name": "Croatian",
    "native": "hrvatski",
    "scenario": [
      "Scenarij"
    ],
    "scenarioOutline": [
      "Skica",
      "Koncept"
    ],
    "then": [
      "* ",
      "Onda "
    ],
    "when": [
      "* ",
      "Kada ",
      "Kad "
    ]
  },
  "ht": {
    "and": [
      "* ",
      "Ak ",
      "Epi ",
      "E "
    ],
    "background": [
      "Kontèks",
      "Istorik"
    ],
    "but": [
      "* ",
      "Men "
    ],
    "examples": [
      "Egzanp"
    ],
    "feature": [
      "Karakteristik",
      "Mak",
      "Fonksyonalite"
    ],
    "given": [
      "* ",
      "Sipoze ",
      "Sipoze ke ",
      "Sipoze Ke "
    ],
    "name": "Creole",
    "native": "kreyòl",
    "scenario": [
      "Senaryo"
    ],
    "scenarioOutline": [
      "Plan senaryo",
      "Plan Senaryo",
      "Senaryo deskripsyon",
      "Senaryo Deskripsyon",
      "Dyagram senaryo",
      "Dyagram Senaryo"
    ],
    "then": [
      "* ",
      "Lè sa a ",
      "Le sa a "
    ],
    "when": [
      "* ",
      "Lè ",
      "Le "
    ]
  },
  "hu": {
    "and": [
      "* ",
      "És "
    ],
    "background": [
      "Háttér"
    ],
    "but": [
      "* ",
      "De "
    ],
    "examples": [
      "Példák"
    ],
    "feature": [
      "Jellemző"
    ],
    "given": [
      "* ",
      "Amennyiben ",
      "Adott "
    ],
    "name": "Hungarian",
    "native": "magyar",
    "scenario": [
      "Forgatókönyv"
    ],
    "scenarioOutline": [
      "Forgatókönyv vázlat"
    ],
    "then": [
      "* ",
      "Akkor "
    ],
    "when": [
      "* ",
      "Majd ",
      "Ha ",
      "Amikor "
    ]
  },
  "id": {
    "and": [
      "* ",
      "Dan "
    ],
    "background": [
      "Dasar"
    ],
    "but": [
      "* ",
      "Tapi "
    ],
    "examples": [
      "Contoh"
    ],
    "feature": [
      "Fitur"
    ],
    "given": [
      "* ",
      "Dengan "
    ],
    "name": "Indonesian",
    "native": "Bahasa Indonesia",
    "scenario": [
      "Skenario"
    ],
    "scenarioOutline": [
      "Skenario konsep"
    ],
    "then": [
      "* ",
      "Maka "
    ],
    "when": [
      "* ",
      "Ketika "
    ]
  },
  "is": {
    "and": [
      "* ",
      "Og "
    ],
    "background": [
      "Bakgrunnur"
    ],
    "but": [
      "* ",
      "En "
    ],
    "examples": [
      "Dæmi",
      "Atburðarásir"
    ],
    "feature": [
      "Eiginleiki"
    ],
    "given": [
      "* ",
      "Ef "
    ],
    "name": "Icelandic",
    "native": "Íslenska",
    "scenario": [
      "Atburðarás"
    ],
    "scenarioOutline": [
      "Lýsing Atburðarásar",
      "Lýsing Dæma"
    ],
    "then": [
      "* ",
      "Þá "
    ],
    "when": [
      "* ",
      "Þegar "
    ]
  },
  "it": {
    "and": [
      "* ",
      "E "
    ],
    "background": [
      "Contesto"
    ],
    "but": [
      "* ",
      "Ma "
    ],
    "examples": [
      "Esempi"
    ],
    "feature": [
      "Funzionalità"
    ],
    "given": [
      "* ",
      "Dato ",
      "Data ",
      "Dati ",
      "Date "
    ],
    "name": "Italian",
    "native": "italiano",
    "scenario": [
      "Scenario"
    ],
    "scenarioOutline": [
      "Schema dello scenario"
    ],
    "then": [
      "* ",
      "Allora "
    ],
    "when": [
      "* ",
      "Quando "
    ]
  },
  "ja": {
    "and": [
      "* ",
      "かつ"
    ],
    "background": [
      "背景"
    ],
    "but": [
      "* ",
      "しかし",
      "但し",
      "ただし"
    ],
    "examples": [
      "例",
      "サンプル"
    ],
    "feature": [
      "フィーチャ",
      "機能"
    ],
    "given": [
      "* ",
      "前提"
    ],
    "name": "Japanese",
    "native": "日本語",
    "scenario": [
      "シナリオ"
    ],
    "scenarioOutline": [
      "シナリオアウトライン",
      "シナリオテンプレート",
      "テンプレ",
      "シナリオテンプレ"
    ],
    "then": [
      "* ",
      "ならば"
    ],
    "when": [
      "* ",
      "もし"
    ]
  },
  "jv": {
    "and": [
      "* ",
      "Lan "
    ],
    "background": [
      "Dasar"
    ],
    "but": [
      "* ",
      "Tapi ",
      "Nanging ",
      "Ananging "
    ],
    "examples": [
      "Conto",
      "Contone"
    ],
    "feature": [
      "Fitur"
    ],
    "given": [
      "* ",
      "Nalika ",
      "Nalikaning "
    ],
    "name": "Javanese",
    "native": "Basa Jawa",
    "scenario": [
      "Skenario"
    ],
    "scenarioOutline": [
      "Konsep skenario"
    ],
    "then": [
      "* ",
      "Njuk ",
      "Banjur "
    ],
    "when": [
      "* ",
      "Manawa ",
      "Menawa "
    ]
  },
  "ka": {
    "and": [
      "* ",
      "და"
    ],
    "background": [
      "კონტექსტი"
    ],
    "but": [
      "* ",
      "მაგ­რამ"
    ],
    "examples": [
      "მაგალითები"
    ],
    "feature": [
      "თვისება"
    ],
    "given": [
      "* ",
      "მოცემული"
    ],
    "name": "Georgian",
    "native": "ქართველი",
    "scenario": [
      "სცენარის"
    ],
    "scenarioOutline": [
      "სცენარის ნიმუში"
    ],
    "then": [
      "* ",
      "მაშინ"
    ],
    "when": [
      "* ",
      "როდესაც"
    ]
  },
  "kn": {
    "and": [
      "* ",
      "ಮತ್ತು "
    ],
    "background": [
      "ಹಿನ್ನೆಲೆ"
    ],
    "but": [
      "* ",
      "ಆದರೆ "
    ],
    "examples": [
      "ಉದಾಹರಣೆಗಳು"
    ],
    "feature": [
      "ಹೆಚ್ಚಳ"
    ],
    "given": [
      "* ",
      "ನೀಡಿದ "
    ],
    "name": "Kannada",
    "native": "ಕನ್ನಡ",
    "scenario": [
      "ಕಥಾಸಾರಾಂಶ"
    ],
    "scenarioOutline": [
      "ವಿವರಣೆ"
    ],
    "then": [
      "* ",
      "ನಂತರ "
    ],
    "when": [
      "* ",
      "ಸ್ಥಿತಿಯನ್ನು "
    ]
  },
  "ko": {
    "and": [
      "* ",
      "그리고"
    ],
    "background": [
      "배경"
    ],
    "but": [
      "* ",
      "하지만",
      "단"
    ],
    "examples": [
      "예"
    ],
    "feature": [
      "기능"
    ],
    "given": [
      "* ",
      "조건",
      "먼저"
    ],
    "name": "Korean",
    "native": "한국어",
    "scenario": [
      "시나리오"
    ],
    "scenarioOutline": [
      "시나리오 개요"
    ],
    "then": [
      "* ",
      "그러면"
    ],
    "when": [
      "* ",
      "만일",
      "만약"
    ]
  },
  "lt": {
    "and": [
      "* ",
      "Ir "
    ],
    "background": [
      "Kontekstas"
    ],
    "but": [
      "* ",
      "Bet "
    ],
    "examples": [
      "Pavyzdžiai",
      "Scenarijai",
      "Variantai"
    ],
    "feature": [
      "Savybė"
    ],
    "given": [
      "* ",
      "Duota "
    ],
    "name": "Lithuanian",
    "native": "lietuvių kalba",
    "scenario": [
      "Scenarijus"
    ],
    "scenarioOutline": [
      "Scenarijaus šablonas"
    ],
    "then": [
      "* ",
      "Tada "
    ],
    "when": [
      "* ",
      "Kai "
    ]
  },
  "lu": {
    "and": [
      "* ",
      "an ",
      "a "
    ],
    "background": [
      "Hannergrond"
    ],
    "but": [
      "* ",
      "awer ",
      "mä "
    ],
    "examples": [
      "Beispiller"
    ],
    "feature": [
      "Funktionalitéit"
    ],
    "given": [
      "* ",
      "ugeholl "
    ],
    "name": "Luxemburgish",
    "native": "Lëtzebuergesch",
    "scenario": [
      "Szenario"
    ],
    "scenarioOutline": [
      "Plang vum Szenario"
    ],
    "then": [
      "* ",
      "dann "
    ],
    "when": [
      "* ",
      "wann "
    ]
  },
  "lv": {
    "and": [
      "* ",
      "Un "
    ],
    "background": [
      "Konteksts",
      "Situācija"
    ],
    "but": [
      "* ",
      "Bet "
    ],
    "examples": [
      "Piemēri",
      "Paraugs"
    ],
    "feature": [
      "Funkcionalitāte",
      "Fīča"
    ],
    "given": [
      "* ",
      "Kad "
    ],
    "name": "Latvian",
    "native": "latviešu",
    "scenario": [
      "Scenārijs"
    ],
    "scenarioOutline": [
      "Scenārijs pēc parauga"
    ],
    "then": [
      "* ",
      "Tad "
    ],
    "when": [
      "* ",
      "Ja "
    ]
  },
  "mk-Cyrl": {
    "and": [
      "* ",
      "И "
    ],
    "background": [
      "Контекст",
      "Содржина"
    ],
    "but": [
      "* ",
      "Но "
    ],
    "examples": [
      "Примери",
      "Сценарија"
    ],
    "feature": [
      "Функционалност",
      "Бизнис потреба",
      "Можност"
    ],
    "given": [
      "* ",
      "Дадено ",
      "Дадена "
    ],
    "name": "Macedonian",
    "native": "Македонски",
    "scenario": [
      "Сценарио",
      "На пример"
    ],
    "scenarioOutline": [
      "Преглед на сценарија",
      "Скица",
      "Концепт"
    ],
    "then": [
      "* ",
      "Тогаш "
    ],
    "when": [
      "* ",
      "Кога "
    ]
  },
  "mk-Latn": {
    "and": [
      "* ",
      "I "
    ],
    "background": [
      "Kontekst",
      "Sodrzhina"
    ],
    "but": [
      "* ",
      "No "
    ],
    "examples": [
      "Primeri",
      "Scenaria"
    ],
    "feature": [
      "Funkcionalnost",
      "Biznis potreba",
      "Mozhnost"
    ],
    "given": [
      "* ",
      "Dadeno ",
      "Dadena "
    ],
    "name": "Macedonian (Latin)",
    "native": "Makedonski (Latinica)",
    "scenario": [
      "Scenario",
      "Na primer"
    ],
    "scenarioOutline": [
      "Pregled na scenarija",
      "Skica",
      "Koncept"
    ],
    "then": [
      "* ",
      "Togash "
    ],
    "when": [
      "* ",
      "Koga "
    ]
  },
  "mn": {
    "and": [
      "* ",
      "Мөн ",
      "Тэгээд "
    ],
    "background": [
      "Агуулга"
    ],
    "but": [
      "* ",
      "Гэхдээ ",
      "Харин "
    ],
    "examples": [
      "Тухайлбал"
    ],
    "feature": [
      "Функц",
      "Функционал"
    ],
    "given": [
      "* ",
      "Өгөгдсөн нь ",
      "Анх "
    ],
    "name": "Mongolian",
    "native": "монгол",
    "scenario": [
      "Сценар"
    ],
    "scenarioOutline": [
      "Сценарын төлөвлөгөө"
    ],
    "then": [
      "* ",
      "Тэгэхэд ",
      "Үүний дараа "
    ],
    "when": [
      "* ",
      "Хэрэв "
    ]
  },
  "nl": {
    "and": [
      "* ",
      "En "
    ],
    "background": [
      "Achtergrond"
    ],
    "but": [
      "* ",
      "Maar "
    ],
    "examples": [
      "Voorbeelden"
    ],
    "feature": [
      "Functionaliteit"
    ],
    "given": [
      "* ",
      "Gegeven ",
      "Stel "
    ],
    "name": "Dutch",
    "native": "Nederlands",
    "scenario": [
      "Scenario"
    ],
    "scenarioOutline": [
      "Abstract Scenario"
    ],
    "then": [
      "* ",
      "Dan "
    ],
    "when": [
      "* ",
      "Als ",
      "Wanneer "
    ]
  },
  "no": {
    "and": [
      "* ",
      "Og "
    ],
    "background": [
      "Bakgrunn"
    ],
    "but": [
      "* ",
      "Men "
    ],
    "examples": [
      "Eksempler"
    ],
    "feature": [
      "Egenskap"
    ],
    "given": [
      "* ",
      "Gitt "
    ],
    "name": "Norwegian",
    "native": "norsk",
    "scenario": [
      "Scenario"
    ],
    "scenarioOutline": [
      "Scenariomal",
      "Abstrakt Scenario"
    ],
    "then": [
      "* ",
      "Så "
    ],
    "when": [
      "* ",
      "Når "
    ]
  },
  "pa": {
    "and": [
      "* ",
      "ਅਤੇ "
    ],
    "background": [
      "ਪਿਛੋਕੜ"
    ],
    "but": [
      "* ",
      "ਪਰ "
    ],
    "examples": [
      "ਉਦਾਹਰਨਾਂ"
    ],
    "feature": [
      "ਖਾਸੀਅਤ",
      "ਮੁਹਾਂਦਰਾ",
      "ਨਕਸ਼ ਨੁਹਾਰ"
    ],
    "given": [
      "* ",
      "ਜੇਕਰ ",
      "ਜਿਵੇਂ ਕਿ "
    ],
    "name": "Panjabi",
    "native": "ਪੰਜਾਬੀ",
    "scenario": [
      "ਪਟਕਥਾ"
    ],
    "scenarioOutline": [
      "ਪਟਕਥਾ ਢਾਂਚਾ",
      "ਪਟਕਥਾ ਰੂਪ ਰੇਖਾ"
    ],
    "then": [
      "* ",
      "ਤਦ "
    ],
    "when": [
      "* ",
      "ਜਦੋਂ "
    ]
  },
  "pl": {
    "and": [
      "* ",
      "Oraz ",
      "I "
    ],
    "background": [
      "Założenia"
    ],
    "but": [
      "* ",
      "Ale "
    ],
    "examples": [
      "Przykłady"
    ],
    "feature": [
      "Właściwość",
      "Funkcja",
      "Aspekt",
      "Potrzeba biznesowa"
    ],
    "given": [
      "* ",
      "Zakładając ",
      "Mając ",
      "Zakładając, że "
    ],
    "name": "Polish",
    "native": "polski",
    "scenario": [
      "Scenariusz"
    ],
    "scenarioOutline": [
      "Szablon scenariusza"
    ],
    "then": [
      "* ",
      "Wtedy "
    ],
    "when": [
      "* ",
      "Jeżeli ",
      "Jeśli ",
      "Gdy ",
      "Kiedy "
    ]
  },
  "pt": {
    "and": [
      "* ",
      "E "
    ],
    "background": [
      "Contexto",
      "Cenário de Fundo",
      "Cenario de Fundo",
      "Fundo"
    ],
    "but": [
      "* ",
      "Mas "
    ],
    "examples": [
      "Exemplos",
      "Cenários",
      "Cenarios"
    ],
    "feature": [
      "Funcionalidade",
      "Característica",
      "Caracteristica"
    ],
    "given": [
      "* ",
      "Dado ",
      "Dada ",
      "Dados ",
      "Dadas "
    ],
    "name": "Portuguese",
    "native": "português",
    "scenario": [
      "Cenário",
      "Cenario"
    ],
    "scenarioOutline": [
      "Esquema do Cenário",
      "Esquema do Cenario",
      "Delineação do Cenário",
      "Delineacao do Cenario"
    ],
    "then": [
      "* ",
      "Então ",
      "Entao "
    ],
    "when": [
      "* ",
      "Quando "
    ]
  },
  "ro": {
    "and": [
      "* ",
      "Si ",
      "Și ",
      "Şi "
    ],
    "background": [
      "Context"
    ],
    "but": [
      "* ",
      "Dar "
    ],
    "examples": [
      "Exemple"
    ],
    "feature": [
      "Functionalitate",
      "Funcționalitate",
      "Funcţionalitate"
    ],
    "given": [
      "* ",
      "Date fiind ",
      "Dat fiind ",
      "Dată fiind",
      "Dati fiind ",
      "Dați fiind ",
      "Daţi fiind "
    ],
    "name": "Romanian",
    "native": "română",
    "scenario": [
      "Scenariu"
    ],
    "scenarioOutline": [
      "Structura scenariu",
      "Structură scenariu"
    ],
    "then": [
      "* ",
      "Atunci "
    ],
    "when": [
      "* ",
      "Cand ",
      "Când "
    ]
  },
  "ru": {
    "and": [
      "* ",
      "И ",
      "К тому же ",
      "Также "
    ],
    "background": [
      "Предыстория",
      "Контекст"
    ],
    "but": [
      "* ",
      "Но ",
      "А ",
      "Иначе "
    ],
    "examples": [
      "Примеры"
    ],
    "feature": [
      "Функция",
      "Функциональность",
      "Функционал",
      "Свойство"
    ],
    "given": [
      "* ",
      "Допустим ",
      "Дано ",
      "Пусть "
    ],
    "name": "Russian",
    "native": "русский",
    "scenario": [
      "Сценарий"
    ],
    "scenarioOutline": [
      "Структура сценария"
    ],
    "then": [
      "* ",
      "То ",
      "Затем ",
      "Тогда "
    ],
    "when": [
      "* ",
      "Когда ",
      "Если "
    ]
  },
  "sk": {
    "and": [
      "* ",
      "A ",
      "A tiež ",
      "A taktiež ",
      "A zároveň "
    ],
    "background": [
      "Pozadie"
    ],
    "but": [
      "* ",
      "Ale "
    ],
    "examples": [
      "Príklady"
    ],
    "feature": [
      "Požiadavka",
      "Funkcia",
      "Vlastnosť"
    ],
    "given": [
      "* ",
      "Pokiaľ ",
      "Za predpokladu "
    ],
    "name": "Slovak",
    "native": "Slovensky",
    "scenario": [
      "Scenár"
    ],
    "scenarioOutline": [
      "Náčrt Scenáru",
      "Náčrt Scenára",
      "Osnova Scenára"
    ],
    "then": [
      "* ",
      "Tak ",
      "Potom "
    ],
    "when": [
      "* ",
      "Keď ",
      "Ak "
    ]
  },
  "sl": {
    "and": [
      "In ",
      "Ter "
    ],
    "background": [
      "Kontekst",
      "Osnova",
      "Ozadje"
    ],
    "but": [
      "Toda ",
      "Ampak ",
      "Vendar "
    ],
    "examples": [
      "Primeri",
      "Scenariji"
    ],
    "feature": [
      "Funkcionalnost",
      "Funkcija",
      "Možnosti",
      "Moznosti",
      "Lastnost",
      "Značilnost"
    ],
    "given": [
      "Dano ",
      "Podano ",
      "Zaradi ",
      "Privzeto "
    ],
    "name": "Slovenian",
    "native": "Slovenski",
    "scenario": [
      "Scenarij",
      "Primer"
    ],
    "scenarioOutline": [
      "Struktura scenarija",
      "Skica",
      "Koncept",
      "Oris scenarija",
      "Osnutek"
    ],
    "then": [
      "Nato ",
      "Potem ",
      "Takrat "
    ],
    "when": [
      "Ko ",
      "Ce ",
      "Če ",
      "Kadar "
    ]
  },
  "sr-Cyrl": {
    "and": [
      "* ",
      "И "
    ],
    "background": [
      "Контекст",
      "Основа",
      "Позадина"
    ],
    "but": [
      "* ",
      "Али "
    ],
    "examples": [
      "Примери",
      "Сценарији"
    ],
    "feature": [
      "Функционалност",
      "Могућност",
      "Особина"
    ],
    "given": [
      "* ",
      "За дато ",
      "За дате ",
      "За дати "
    ],
    "name": "Serbian",
    "native": "Српски",
    "scenario": [
      "Сценарио",
      "Пример"
    ],
    "scenarioOutline": [
      "Структура сценарија",
      "Скица",
      "Концепт"
    ],
    "then": [
      "* ",
      "Онда "
    ],
    "when": [
      "* ",
      "Када ",
      "Кад "
    ]
  },
  "sr-Latn": {
    "and": [
      "* ",
      "I "
    ],
    "background": [
      "Kontekst",
      "Osnova",
      "Pozadina"
    ],
    "but": [
      "* ",
      "Ali "
    ],
    "examples": [
      "Primeri",
      "Scenariji"
    ],
    "feature": [
      "Funkcionalnost",
      "Mogućnost",
      "Mogucnost",
      "Osobina"
    ],
    "given": [
      "* ",
      "Za dato ",
      "Za date ",
      "Za dati "
    ],
    "name": "Serbian (Latin)",
    "native": "Srpski (Latinica)",
    "scenario": [
      "Scenario",
      "Primer"
    ],
    "scenarioOutline": [
      "Struktura scenarija",
      "Skica",
      "Koncept"
    ],
    "then": [
      "* ",
      "Onda "
    ],
    "when": [
      "* ",
      "Kada ",
      "Kad "
    ]
  },
  "sv": {
    "and": [
      "* ",
      "Och "
    ],
    "background": [
      "Bakgrund"
    ],
    "but": [
      "* ",
      "Men "
    ],
    "examples": [
      "Exempel"
    ],
    "feature": [
      "Egenskap"
    ],
    "given": [
      "* ",
      "Givet "
    ],
    "name": "Swedish",
    "native": "Svenska",
    "scenario": [
      "Scenario"
    ],
    "scenarioOutline": [
      "Abstrakt Scenario",
      "Scenariomall"
    ],
    "then": [
      "* ",
      "Så "
    ],
    "when": [
      "* ",
      "När "
    ]
  },
  "ta": {
    "and": [
      "* ",
      "மேலும்  ",
      "மற்றும் "
    ],
    "background": [
      "பின்னணி"
    ],
    "but": [
      "* ",
      "ஆனால்  "
    ],
    "examples": [
      "எடுத்துக்காட்டுகள்",
      "காட்சிகள்",
      " நிலைமைகளில்"
    ],
    "feature": [
      "அம்சம்",
      "வணிக தேவை",
      "திறன்"
    ],
    "given": [
      "* ",
      "கொடுக்கப்பட்ட "
    ],
    "name": "Tamil",
    "native": "தமிழ்",
    "scenario": [
      "காட்சி"
    ],
    "scenarioOutline": [
      "காட்சி சுருக்கம்",
      "காட்சி வார்ப்புரு"
    ],
    "then": [
      "* ",
      "அப்பொழுது "
    ],
    "when": [
      "* ",
      "எப்போது "
    ]
  },
  "th": {
    "and": [
      "* ",
      "และ "
    ],
    "background": [
      "แนวคิด"
    ],
    "but": [
      "* ",
      "แต่ "
    ],
    "examples": [
      "ชุดของตัวอย่าง",
      "ชุดของเหตุการณ์"
    ],
    "feature": [
      "โครงหลัก",
      "ความต้องการทางธุรกิจ",
      "ความสามารถ"
    ],
    "given": [
      "* ",
      "กำหนดให้ "
    ],
    "name": "Thai",
    "native": "ไทย",
    "scenario": [
      "เหตุการณ์"
    ],
    "scenarioOutline": [
      "สรุปเหตุการณ์",
      "โครงสร้างของเหตุการณ์"
    ],
    "then": [
      "* ",
      "ดังนั้น "
    ],
    "when": [
      "* ",
      "เมื่อ "
    ]
  },
  "tl": {
    "and": [
      "* ",
      "మరియు "
    ],
    "background": [
      "నేపథ్యం"
    ],
    "but": [
      "* ",
      "కాని "
    ],
    "examples": [
      "ఉదాహరణలు"
    ],
    "feature": [
      "గుణము"
    ],
    "given": [
      "* ",
      "చెప్పబడినది "
    ],
    "name": "Telugu",
    "native": "తెలుగు",
    "scenario": [
      "సన్నివేశం"
    ],
    "scenarioOutline": [
      "కథనం"
    ],
    "then": [
      "* ",
      "అప్పుడు "
    ],
    "when": [
      "* ",
      "ఈ పరిస్థితిలో "
    ]
  },
  "tlh": {
    "and": [
      "* ",
      "'ej ",
      "latlh "
    ],
    "background": [
      "mo'"
    ],
    "but": [
      "* ",
      "'ach ",
      "'a "
    ],
    "examples": [
      "ghantoH",
      "lutmey"
    ],
    "feature": [
      "Qap",
      "Qu'meH 'ut",
      "perbogh",
      "poQbogh malja'",
      "laH"
    ],
    "given": [
      "* ",
      "ghu' noblu' ",
      "DaH ghu' bejlu' "
    ],
    "name": "Klingon",
    "native": "tlhIngan",
    "scenario": [
      "lut"
    ],
    "scenarioOutline": [
      "lut chovnatlh"
    ],
    "then": [
      "* ",
      "vaj "
    ],
    "when": [
      "* ",
      "qaSDI' "
    ]
  },
  "tr": {
    "and": [
      "* ",
      "Ve "
    ],
    "background": [
      "Geçmiş"
    ],
    "but": [
      "* ",
      "Fakat ",
      "Ama "
    ],
    "examples": [
      "Örnekler"
    ],
    "feature": [
      "Özellik"
    ],
    "given": [
      "* ",
      "Diyelim ki "
    ],
    "name": "Turkish",
    "native": "Türkçe",
    "scenario": [
      "Senaryo"
    ],
    "scenarioOutline": [
      "Senaryo taslağı"
    ],
    "then": [
      "* ",
      "O zaman "
    ],
    "when": [
      "* ",
      "Eğer ki "
    ]
  },
  "tt": {
    "and": [
      "* ",
      "Һәм ",
      "Вә "
    ],
    "background": [
      "Кереш"
    ],
    "but": [
      "* ",
      "Ләкин ",
      "Әмма "
    ],
    "examples": [
      "Үрнәкләр",
      "Мисаллар"
    ],
    "feature": [
      "Мөмкинлек",
      "Үзенчәлеклелек"
    ],
    "given": [
      "* ",
      "Әйтик "
    ],
    "name": "Tatar",
    "native": "Татарча",
    "scenario": [
      "Сценарий"
    ],
    "scenarioOutline": [
      "Сценарийның төзелеше"
    ],
    "then": [
      "* ",
      "Нәтиҗәдә "
    ],
    "when": [
      "* ",
      "Әгәр "
    ]
  },
  "uk": {
    "and": [
      "* ",
      "І ",
      "А також ",
      "Та "
    ],
    "background": [
      "Передумова"
    ],
    "but": [
      "* ",
      "Але "
    ],
    "examples": [
      "Приклади"
    ],
    "feature": [
      "Функціонал"
    ],
    "given": [
      "* ",
      "Припустимо ",
      "Припустимо, що ",
      "Нехай ",
      "Дано "
    ],
    "name": "Ukrainian",
    "native": "Українська",
    "scenario": [
      "Сценарій"
    ],
    "scenarioOutline": [
      "Структура сценарію"
    ],
    "then": [
      "* ",
      "То ",
      "Тоді "
    ],
    "when": [
      "* ",
      "Якщо ",
      "Коли "
    ]
  },
  "ur": {
    "and": [
      "* ",
      "اور "
    ],
    "background": [
      "پس منظر"
    ],
    "but": [
      "* ",
      "لیکن "
    ],
    "examples": [
      "مثالیں"
    ],
    "feature": [
      "صلاحیت",
      "کاروبار کی ضرورت",
      "خصوصیت"
    ],
    "given": [
      "* ",
      "اگر ",
      "بالفرض ",
      "فرض کیا "
    ],
    "name": "Urdu",
    "native": "اردو",
    "scenario": [
      "منظرنامہ"
    ],
    "scenarioOutline": [
      "منظر نامے کا خاکہ"
    ],
    "then": [
      "* ",
      "پھر ",
      "تب "
    ],
    "when": [
      "* ",
      "جب "
    ]
  },
  "uz": {
    "and": [
      "* ",
      "Ва "
    ],
    "background": [
      "Тарих"
    ],
    "but": [
      "* ",
      "Лекин ",
      "Бирок ",
      "Аммо "
    ],
    "examples": [
      "Мисоллар"
    ],
    "feature": [
      "Функционал"
    ],
    "given": [
      "* ",
      "Агар "
    ],
    "name": "Uzbek",
    "native": "Узбекча",
    "scenario": [
      "Сценарий"
    ],
    "scenarioOutline": [
      "Сценарий структураси"
    ],
    "then": [
      "* ",
      "Унда "
    ],
    "when": [
      "* ",
      "Агар "
    ]
  },
  "vi": {
    "and": [
      "* ",
      "Và "
    ],
    "background": [
      "Bối cảnh"
    ],
    "but": [
      "* ",
      "Nhưng "
    ],
    "examples": [
      "Dữ liệu"
    ],
    "feature": [
      "Tính năng"
    ],
    "given": [
      "* ",
      "Biết ",
      "Cho "
    ],
    "name": "Vietnamese",
    "native": "Tiếng Việt",
    "scenario": [
      "Tình huống",
      "Kịch bản"
    ],
    "scenarioOutline": [
      "Khung tình huống",
      "Khung kịch bản"
    ],
    "then": [
      "* ",
      "Thì "
    ],
    "when": [
      "* ",
      "Khi "
    ]
  },
  "zh-CN": {
    "and": [
      "* ",
      "而且",
      "并且",
      "同时"
    ],
    "background": [
      "背景"
    ],
    "but": [
      "* ",
      "但是"
    ],
    "examples": [
      "例子"
    ],
    "feature": [
      "功能"
    ],
    "given": [
      "* ",
      "假如",
      "假设",
      "假定"
    ],
    "name": "Chinese simplified",
    "native": "简体中文",
    "scenario": [
      "场景",
      "剧本"
    ],
    "scenarioOutline": [
      "场景大纲",
      "剧本大纲"
    ],
    "then": [
      "* ",
      "那么"
    ],
    "when": [
      "* ",
      "当"
    ]
  },
  "zh-TW": {
    "and": [
      "* ",
      "而且",
      "並且",
      "同時"
    ],
    "background": [
      "背景"
    ],
    "but": [
      "* ",
      "但是"
    ],
    "examples": [
      "例子"
    ],
    "feature": [
      "功能"
    ],
    "given": [
      "* ",
      "假如",
      "假設",
      "假定"
    ],
    "name": "Chinese traditional",
    "native": "繁體中文",
    "scenario": [
      "場景",
      "劇本"
    ],
    "scenarioOutline": [
      "場景大綱",
      "劇本大綱"
    ],
    "then": [
      "* ",
      "那麼"
    ],
    "when": [
      "* ",
      "當"
    ]
  }
}

},{}],9:[function(require,module,exports){
var countSymbols = require('./count_symbols')

function GherkinLine(lineText, lineNumber) {
  this.lineText = lineText;
  this.lineNumber = lineNumber;
  this.trimmedLineText = lineText.replace(/^\s+/g, ''); // ltrim
  this.isEmpty = this.trimmedLineText.length == 0;
  this.indent = countSymbols(lineText) - countSymbols(this.trimmedLineText);
};

GherkinLine.prototype.startsWith = function startsWith(prefix) {
  return this.trimmedLineText.indexOf(prefix) == 0;
};

GherkinLine.prototype.startsWithTitleKeyword = function startsWithTitleKeyword(keyword) {
  return this.startsWith(keyword+':'); // The C# impl is more complicated. Find out why.
};

GherkinLine.prototype.getLineText = function getLineText(indentToRemove) {
  if (indentToRemove < 0 || indentToRemove > this.indent) {
    return this.trimmedLineText;
  } else {
    return this.lineText.substring(indentToRemove);
  }
};

GherkinLine.prototype.getRestTrimmed = function getRestTrimmed(length) {
  return this.trimmedLineText.substring(length).trim();
};

GherkinLine.prototype.getTableCells = function getTableCells() {
  var cells = [];
  var col = 0;
  var startCol = col + 1;
  var cell = '';
  var firstCell = true;
  while (col < this.trimmedLineText.length) {
    var chr = this.trimmedLineText[col];
    col++;

    if (chr == '|') {
      if (firstCell) {
        // First cell (content before the first |) is skipped
        firstCell = false;
      } else {
        var cellIndent = cell.length - cell.replace(/^\s+/g, '').length;
        var span = {column: this.indent + startCol + cellIndent, text: cell.trim()};
        cells.push(span);
      }
      cell = '';
      startCol = col + 1;
    } else if (chr == '\\') {
      chr = this.trimmedLineText[col];
      col += 1;
      if (chr == 'n') {
        cell += '\n';
      } else {
        if (chr != '|' && chr != '\\') {
          cell += '\\';
        }
        cell += chr;
      }
    } else {
      cell += chr;
    }
  }

  return cells;
};

GherkinLine.prototype.getTags = function getTags() {
  var column = this.indent + 1;
  var items = this.trimmedLineText.trim().split('@');
  items.shift();
  return items.map(function (item) {
    var length = item.length;
    var span = {column: column, text: '@' + item.trim()};
    column += length + 1;
    return span;
  });
};

module.exports = GherkinLine;

},{"./count_symbols":4}],10:[function(require,module,exports){
// This file is generated. Do not edit! Edit gherkin-javascript.razor instead.
var Errors = require('./errors');
var AstBuilder = require('./ast_builder');
var TokenScanner = require('./token_scanner');
var TokenMatcher = require('./token_matcher');

var RULE_TYPES = [
  'None',
  '_EOF', // #EOF
  '_Empty', // #Empty
  '_Comment', // #Comment
  '_TagLine', // #TagLine
  '_FeatureLine', // #FeatureLine
  '_BackgroundLine', // #BackgroundLine
  '_ScenarioLine', // #ScenarioLine
  '_ScenarioOutlineLine', // #ScenarioOutlineLine
  '_ExamplesLine', // #ExamplesLine
  '_StepLine', // #StepLine
  '_DocStringSeparator', // #DocStringSeparator
  '_TableRow', // #TableRow
  '_Language', // #Language
  '_Other', // #Other
  'GherkinDocument', // GherkinDocument! := Feature?
  'Feature', // Feature! := Feature_Header Background? Scenario_Definition*
  'Feature_Header', // Feature_Header! := #Language? Tags? #FeatureLine Description_Helper
  'Background', // Background! := #BackgroundLine Description_Helper Step*
  'Scenario_Definition', // Scenario_Definition! := Tags? (Scenario | ScenarioOutline)
  'Scenario', // Scenario! := #ScenarioLine Description_Helper Step*
  'ScenarioOutline', // ScenarioOutline! := #ScenarioOutlineLine Description_Helper Step* Examples_Definition*
  'Examples_Definition', // Examples_Definition! [#Empty|#Comment|#TagLine-&gt;#ExamplesLine] := Tags? Examples
  'Examples', // Examples! := #ExamplesLine Description_Helper Examples_Table?
  'Examples_Table', // Examples_Table! := #TableRow #TableRow*
  'Step', // Step! := #StepLine Step_Arg?
  'Step_Arg', // Step_Arg := (DataTable | DocString)
  'DataTable', // DataTable! := #TableRow+
  'DocString', // DocString! := #DocStringSeparator #Other* #DocStringSeparator
  'Tags', // Tags! := #TagLine+
  'Description_Helper', // Description_Helper := #Empty* Description? #Comment*
  'Description', // Description! := #Other+
];

module.exports = function Parser(builder) {
  builder = builder || new AstBuilder();
  var self = this;
  var context;

  this.parse = function(tokenScanner, tokenMatcher) {
    if(typeof tokenScanner == 'string') {
      tokenScanner = new TokenScanner(tokenScanner);
    }
    tokenMatcher = tokenMatcher || new TokenMatcher();
    builder.reset();
    tokenMatcher.reset();
    context = {
      tokenScanner: tokenScanner,
      tokenMatcher: tokenMatcher,
      tokenQueue: [],
      errors: []
    };
    startRule(context, "GherkinDocument");
    var state = 0;
    var token = null;
    while(true) {
      token = readToken(context);
      state = matchToken(state, token, context);
      if(token.isEof) break;
    }

    endRule(context, "GherkinDocument");

    if(context.errors.length > 0) {
      throw Errors.CompositeParserException.create(context.errors);
    }

    return getResult();
  };

  function addError(context, error) {
    context.errors.push(error);
    if (context.errors.length > 10)
      throw Errors.CompositeParserException.create(context.errors);
  }

  function startRule(context, ruleType) {
    handleAstError(context, function () {
      builder.startRule(ruleType);
    });
  }

  function endRule(context, ruleType) {
    handleAstError(context, function () {
      builder.endRule(ruleType);
    });
  }

  function build(context, token) {
    handleAstError(context, function () {
      builder.build(token);
    });
  }

  function getResult() {
    return builder.getResult();
  }

  function handleAstError(context, action) {
    handleExternalError(context, true, action)
  }

  function handleExternalError(context, defaultValue, action) {
    if(self.stopAtFirstError) return action();
    try {
      return action();
    } catch (e) {
      if(e instanceof Errors.CompositeParserException) {
        e.errors.forEach(function (error) {
          addError(context, error);
        });
      } else if(
        e instanceof Errors.ParserException ||
        e instanceof Errors.AstBuilderException ||
        e instanceof Errors.UnexpectedTokenException ||
        e instanceof Errors.NoSuchLanguageException
      ) {
        addError(context, e);
      } else {
        throw e;
      }
    }
    return defaultValue;
  }

  function readToken(context) {
    return context.tokenQueue.length > 0 ?
      context.tokenQueue.shift() :
      context.tokenScanner.read();
  }

  function matchToken(state, token, context) {
    switch(state) {
    case 0:
      return matchTokenAt_0(token, context);
    case 1:
      return matchTokenAt_1(token, context);
    case 2:
      return matchTokenAt_2(token, context);
    case 3:
      return matchTokenAt_3(token, context);
    case 4:
      return matchTokenAt_4(token, context);
    case 5:
      return matchTokenAt_5(token, context);
    case 6:
      return matchTokenAt_6(token, context);
    case 7:
      return matchTokenAt_7(token, context);
    case 8:
      return matchTokenAt_8(token, context);
    case 9:
      return matchTokenAt_9(token, context);
    case 10:
      return matchTokenAt_10(token, context);
    case 11:
      return matchTokenAt_11(token, context);
    case 12:
      return matchTokenAt_12(token, context);
    case 13:
      return matchTokenAt_13(token, context);
    case 14:
      return matchTokenAt_14(token, context);
    case 15:
      return matchTokenAt_15(token, context);
    case 16:
      return matchTokenAt_16(token, context);
    case 17:
      return matchTokenAt_17(token, context);
    case 18:
      return matchTokenAt_18(token, context);
    case 19:
      return matchTokenAt_19(token, context);
    case 20:
      return matchTokenAt_20(token, context);
    case 21:
      return matchTokenAt_21(token, context);
    case 22:
      return matchTokenAt_22(token, context);
    case 23:
      return matchTokenAt_23(token, context);
    case 24:
      return matchTokenAt_24(token, context);
    case 25:
      return matchTokenAt_25(token, context);
    case 26:
      return matchTokenAt_26(token, context);
    case 28:
      return matchTokenAt_28(token, context);
    case 29:
      return matchTokenAt_29(token, context);
    case 30:
      return matchTokenAt_30(token, context);
    case 31:
      return matchTokenAt_31(token, context);
    case 32:
      return matchTokenAt_32(token, context);
    case 33:
      return matchTokenAt_33(token, context);
    default:
      throw new Error("Unknown state: " + state);
    }
  }


  // Start
  function matchTokenAt_0(token, context) {
    if(match_EOF(context, token)) {
      build(context, token);
      return 27;
    }
    if(match_Language(context, token)) {
      startRule(context, 'Feature');
      startRule(context, 'Feature_Header');
      build(context, token);
      return 1;
    }
    if(match_TagLine(context, token)) {
      startRule(context, 'Feature');
      startRule(context, 'Feature_Header');
      startRule(context, 'Tags');
      build(context, token);
      return 2;
    }
    if(match_FeatureLine(context, token)) {
      startRule(context, 'Feature');
      startRule(context, 'Feature_Header');
      build(context, token);
      return 3;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 0;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 0;
    }
    
    var stateComment = "State: 0 - Start";
    token.detach();
    var expectedTokens = ["#EOF", "#Language", "#TagLine", "#FeatureLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 0;
  }


  // GherkinDocument:0>Feature:0>Feature_Header:0>#Language:0
  function matchTokenAt_1(token, context) {
    if(match_TagLine(context, token)) {
      startRule(context, 'Tags');
      build(context, token);
      return 2;
    }
    if(match_FeatureLine(context, token)) {
      build(context, token);
      return 3;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 1;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 1;
    }
    
    var stateComment = "State: 1 - GherkinDocument:0>Feature:0>Feature_Header:0>#Language:0";
    token.detach();
    var expectedTokens = ["#TagLine", "#FeatureLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 1;
  }


  // GherkinDocument:0>Feature:0>Feature_Header:1>Tags:0>#TagLine:0
  function matchTokenAt_2(token, context) {
    if(match_TagLine(context, token)) {
      build(context, token);
      return 2;
    }
    if(match_FeatureLine(context, token)) {
      endRule(context, 'Tags');
      build(context, token);
      return 3;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 2;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 2;
    }
    
    var stateComment = "State: 2 - GherkinDocument:0>Feature:0>Feature_Header:1>Tags:0>#TagLine:0";
    token.detach();
    var expectedTokens = ["#TagLine", "#FeatureLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 2;
  }


  // GherkinDocument:0>Feature:0>Feature_Header:2>#FeatureLine:0
  function matchTokenAt_3(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Feature_Header');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 3;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 5;
    }
    if(match_BackgroundLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Background');
      build(context, token);
      return 6;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      startRule(context, 'Description');
      build(context, token);
      return 4;
    }
    
    var stateComment = "State: 3 - GherkinDocument:0>Feature:0>Feature_Header:2>#FeatureLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Empty", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 3;
  }


  // GherkinDocument:0>Feature:0>Feature_Header:3>Description_Helper:1>Description:0>#Other:0
  function matchTokenAt_4(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Feature_Header');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      endRule(context, 'Description');
      build(context, token);
      return 5;
    }
    if(match_BackgroundLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Feature_Header');
      startRule(context, 'Background');
      build(context, token);
      return 6;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 4;
    }
    
    var stateComment = "State: 4 - GherkinDocument:0>Feature:0>Feature_Header:3>Description_Helper:1>Description:0>#Other:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 4;
  }


  // GherkinDocument:0>Feature:0>Feature_Header:3>Description_Helper:2>#Comment:0
  function matchTokenAt_5(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Feature_Header');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 5;
    }
    if(match_BackgroundLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Background');
      build(context, token);
      return 6;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Feature_Header');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 5;
    }
    
    var stateComment = "State: 5 - GherkinDocument:0>Feature:0>Feature_Header:3>Description_Helper:2>#Comment:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 5;
  }


  // GherkinDocument:0>Feature:1>Background:0>#BackgroundLine:0
  function matchTokenAt_6(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Background');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 6;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 8;
    }
    if(match_StepLine(context, token)) {
      startRule(context, 'Step');
      build(context, token);
      return 9;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      startRule(context, 'Description');
      build(context, token);
      return 7;
    }
    
    var stateComment = "State: 6 - GherkinDocument:0>Feature:1>Background:0>#BackgroundLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 6;
  }


  // GherkinDocument:0>Feature:1>Background:1>Description_Helper:1>Description:0>#Other:0
  function matchTokenAt_7(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Background');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      endRule(context, 'Description');
      build(context, token);
      return 8;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'Description');
      startRule(context, 'Step');
      build(context, token);
      return 9;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 7;
    }
    
    var stateComment = "State: 7 - GherkinDocument:0>Feature:1>Background:1>Description_Helper:1>Description:0>#Other:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 7;
  }


  // GherkinDocument:0>Feature:1>Background:1>Description_Helper:2>#Comment:0
  function matchTokenAt_8(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Background');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 8;
    }
    if(match_StepLine(context, token)) {
      startRule(context, 'Step');
      build(context, token);
      return 9;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 8;
    }
    
    var stateComment = "State: 8 - GherkinDocument:0>Feature:1>Background:1>Description_Helper:2>#Comment:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 8;
  }


  // GherkinDocument:0>Feature:1>Background:2>Step:0>#StepLine:0
  function matchTokenAt_9(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Background');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      startRule(context, 'DataTable');
      build(context, token);
      return 10;
    }
    if(match_DocStringSeparator(context, token)) {
      startRule(context, 'DocString');
      build(context, token);
      return 32;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 9;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 9;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 9;
    }
    
    var stateComment = "State: 9 - GherkinDocument:0>Feature:1>Background:2>Step:0>#StepLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 9;
  }


  // GherkinDocument:0>Feature:1>Background:2>Step:1>Step_Arg:0>__alt1:0>DataTable:0>#TableRow:0
  function matchTokenAt_10(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Background');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      build(context, token);
      return 10;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 9;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 10;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 10;
    }
    
    var stateComment = "State: 10 - GherkinDocument:0>Feature:1>Background:2>Step:1>Step_Arg:0>__alt1:0>DataTable:0>#TableRow:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 10;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:0>Tags:0>#TagLine:0
  function matchTokenAt_11(token, context) {
    if(match_TagLine(context, token)) {
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Tags');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Tags');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 11;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 11;
    }
    
    var stateComment = "State: 11 - GherkinDocument:0>Feature:2>Scenario_Definition:0>Tags:0>#TagLine:0";
    token.detach();
    var expectedTokens = ["#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 11;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:0>#ScenarioLine:0
  function matchTokenAt_12(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 12;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 14;
    }
    if(match_StepLine(context, token)) {
      startRule(context, 'Step');
      build(context, token);
      return 15;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      startRule(context, 'Description');
      build(context, token);
      return 13;
    }
    
    var stateComment = "State: 12 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:0>#ScenarioLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 12;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:1>Description_Helper:1>Description:0>#Other:0
  function matchTokenAt_13(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      endRule(context, 'Description');
      build(context, token);
      return 14;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'Description');
      startRule(context, 'Step');
      build(context, token);
      return 15;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 13;
    }
    
    var stateComment = "State: 13 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:1>Description_Helper:1>Description:0>#Other:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 13;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:1>Description_Helper:2>#Comment:0
  function matchTokenAt_14(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 14;
    }
    if(match_StepLine(context, token)) {
      startRule(context, 'Step');
      build(context, token);
      return 15;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 14;
    }
    
    var stateComment = "State: 14 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:1>Description_Helper:2>#Comment:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 14;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:0>#StepLine:0
  function matchTokenAt_15(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      startRule(context, 'DataTable');
      build(context, token);
      return 16;
    }
    if(match_DocStringSeparator(context, token)) {
      startRule(context, 'DocString');
      build(context, token);
      return 30;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 15;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 15;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 15;
    }
    
    var stateComment = "State: 15 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:0>#StepLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 15;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:1>Step_Arg:0>__alt1:0>DataTable:0>#TableRow:0
  function matchTokenAt_16(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      build(context, token);
      return 16;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 15;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 16;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 16;
    }
    
    var stateComment = "State: 16 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:1>Step_Arg:0>__alt1:0>DataTable:0>#TableRow:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 16;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:0>#ScenarioOutlineLine:0
  function matchTokenAt_17(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 19;
    }
    if(match_StepLine(context, token)) {
      startRule(context, 'Step');
      build(context, token);
      return 20;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      startRule(context, 'Description');
      build(context, token);
      return 18;
    }
    
    var stateComment = "State: 17 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:0>#ScenarioOutlineLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 17;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:1>Description_Helper:1>Description:0>#Other:0
  function matchTokenAt_18(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      endRule(context, 'Description');
      build(context, token);
      return 19;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'Description');
      startRule(context, 'Step');
      build(context, token);
      return 20;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'Description');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Description');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 18;
    }
    
    var stateComment = "State: 18 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:1>Description_Helper:1>Description:0>#Other:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 18;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:1>Description_Helper:2>#Comment:0
  function matchTokenAt_19(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 19;
    }
    if(match_StepLine(context, token)) {
      startRule(context, 'Step');
      build(context, token);
      return 20;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 19;
    }
    
    var stateComment = "State: 19 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:1>Description_Helper:2>#Comment:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 19;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:0>#StepLine:0
  function matchTokenAt_20(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      startRule(context, 'DataTable');
      build(context, token);
      return 21;
    }
    if(match_DocStringSeparator(context, token)) {
      startRule(context, 'DocString');
      build(context, token);
      return 28;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 20;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'Step');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Step');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 20;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 20;
    }
    
    var stateComment = "State: 20 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:0>#StepLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 20;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:1>Step_Arg:0>__alt1:0>DataTable:0>#TableRow:0
  function matchTokenAt_21(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      build(context, token);
      return 21;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 20;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'DataTable');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 21;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 21;
    }
    
    var stateComment = "State: 21 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:1>Step_Arg:0>__alt1:0>DataTable:0>#TableRow:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 21;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:0>Tags:0>#TagLine:0
  function matchTokenAt_22(token, context) {
    if(match_TagLine(context, token)) {
      build(context, token);
      return 22;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Tags');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 22;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 22;
    }
    
    var stateComment = "State: 22 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:0>Tags:0>#TagLine:0";
    token.detach();
    var expectedTokens = ["#TagLine", "#ExamplesLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 22;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:0>#ExamplesLine:0
  function matchTokenAt_23(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 23;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 25;
    }
    if(match_TableRow(context, token)) {
      startRule(context, 'Examples_Table');
      build(context, token);
      return 26;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      startRule(context, 'Description');
      build(context, token);
      return 24;
    }
    
    var stateComment = "State: 23 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:0>#ExamplesLine:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Empty", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 23;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:1>Description_Helper:1>Description:0>#Other:0
  function matchTokenAt_24(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      endRule(context, 'Description');
      build(context, token);
      return 25;
    }
    if(match_TableRow(context, token)) {
      endRule(context, 'Description');
      startRule(context, 'Examples_Table');
      build(context, token);
      return 26;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Description');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 24;
    }
    
    var stateComment = "State: 24 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:1>Description_Helper:1>Description:0>#Other:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 24;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:1>Description_Helper:2>#Comment:0
  function matchTokenAt_25(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 25;
    }
    if(match_TableRow(context, token)) {
      startRule(context, 'Examples_Table');
      build(context, token);
      return 26;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 25;
    }
    
    var stateComment = "State: 25 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:1>Description_Helper:2>#Comment:0";
    token.detach();
    var expectedTokens = ["#EOF", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 25;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:2>Examples_Table:0>#TableRow:0
  function matchTokenAt_26(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'Examples_Table');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_TableRow(context, token)) {
      build(context, token);
      return 26;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'Examples_Table');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'Examples_Table');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'Examples_Table');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'Examples_Table');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'Examples_Table');
      endRule(context, 'Examples');
      endRule(context, 'Examples_Definition');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 26;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 26;
    }
    
    var stateComment = "State: 26 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:3>Examples_Definition:1>Examples:2>Examples_Table:0>#TableRow:0";
    token.detach();
    var expectedTokens = ["#EOF", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 26;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:1>Step_Arg:0>__alt1:1>DocString:0>#DocStringSeparator:0
  function matchTokenAt_28(token, context) {
    if(match_DocStringSeparator(context, token)) {
      build(context, token);
      return 29;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 28;
    }
    
    var stateComment = "State: 28 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:1>Step_Arg:0>__alt1:1>DocString:0>#DocStringSeparator:0";
    token.detach();
    var expectedTokens = ["#DocStringSeparator", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 28;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:1>Step_Arg:0>__alt1:1>DocString:2>#DocStringSeparator:0
  function matchTokenAt_29(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 20;
    }
    if(match_TagLine(context, token)) {
      if(lookahead_0(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 22;
      }
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ExamplesLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      startRule(context, 'Examples_Definition');
      startRule(context, 'Examples');
      build(context, token);
      return 23;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'ScenarioOutline');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 29;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 29;
    }
    
    var stateComment = "State: 29 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:1>ScenarioOutline:2>Step:1>Step_Arg:0>__alt1:1>DocString:2>#DocStringSeparator:0";
    token.detach();
    var expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 29;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:1>Step_Arg:0>__alt1:1>DocString:0>#DocStringSeparator:0
  function matchTokenAt_30(token, context) {
    if(match_DocStringSeparator(context, token)) {
      build(context, token);
      return 31;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 30;
    }
    
    var stateComment = "State: 30 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:1>Step_Arg:0>__alt1:1>DocString:0>#DocStringSeparator:0";
    token.detach();
    var expectedTokens = ["#DocStringSeparator", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 30;
  }


  // GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:1>Step_Arg:0>__alt1:1>DocString:2>#DocStringSeparator:0
  function matchTokenAt_31(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 15;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Scenario');
      endRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 31;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 31;
    }
    
    var stateComment = "State: 31 - GherkinDocument:0>Feature:2>Scenario_Definition:1>__alt0:0>Scenario:2>Step:1>Step_Arg:0>__alt1:1>DocString:2>#DocStringSeparator:0";
    token.detach();
    var expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 31;
  }


  // GherkinDocument:0>Feature:1>Background:2>Step:1>Step_Arg:0>__alt1:1>DocString:0>#DocStringSeparator:0
  function matchTokenAt_32(token, context) {
    if(match_DocStringSeparator(context, token)) {
      build(context, token);
      return 33;
    }
    if(match_Other(context, token)) {
      build(context, token);
      return 32;
    }
    
    var stateComment = "State: 32 - GherkinDocument:0>Feature:1>Background:2>Step:1>Step_Arg:0>__alt1:1>DocString:0>#DocStringSeparator:0";
    token.detach();
    var expectedTokens = ["#DocStringSeparator", "#Other"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 32;
  }


  // GherkinDocument:0>Feature:1>Background:2>Step:1>Step_Arg:0>__alt1:1>DocString:2>#DocStringSeparator:0
  function matchTokenAt_33(token, context) {
    if(match_EOF(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Background');
      endRule(context, 'Feature');
      build(context, token);
      return 27;
    }
    if(match_StepLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      startRule(context, 'Step');
      build(context, token);
      return 9;
    }
    if(match_TagLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Tags');
      build(context, token);
      return 11;
    }
    if(match_ScenarioLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'Scenario');
      build(context, token);
      return 12;
    }
    if(match_ScenarioOutlineLine(context, token)) {
      endRule(context, 'DocString');
      endRule(context, 'Step');
      endRule(context, 'Background');
      startRule(context, 'Scenario_Definition');
      startRule(context, 'ScenarioOutline');
      build(context, token);
      return 17;
    }
    if(match_Comment(context, token)) {
      build(context, token);
      return 33;
    }
    if(match_Empty(context, token)) {
      build(context, token);
      return 33;
    }
    
    var stateComment = "State: 33 - GherkinDocument:0>Feature:1>Background:2>Step:1>Step_Arg:0>__alt1:1>DocString:2>#DocStringSeparator:0";
    token.detach();
    var expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ScenarioLine", "#ScenarioOutlineLine", "#Comment", "#Empty"];
    var error = token.isEof ?
      Errors.UnexpectedEOFException.create(token, expectedTokens, stateComment) :
      Errors.UnexpectedTokenException.create(token, expectedTokens, stateComment);
    if (self.stopAtFirstError) throw error;
    addError(context, error);
    return 33;
  }



  function match_EOF(context, token) {
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_EOF(token);
    });
  }


  function match_Empty(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_Empty(token);
    });
  }


  function match_Comment(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_Comment(token);
    });
  }


  function match_TagLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_TagLine(token);
    });
  }


  function match_FeatureLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_FeatureLine(token);
    });
  }


  function match_BackgroundLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_BackgroundLine(token);
    });
  }


  function match_ScenarioLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_ScenarioLine(token);
    });
  }


  function match_ScenarioOutlineLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_ScenarioOutlineLine(token);
    });
  }


  function match_ExamplesLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_ExamplesLine(token);
    });
  }


  function match_StepLine(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_StepLine(token);
    });
  }


  function match_DocStringSeparator(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_DocStringSeparator(token);
    });
  }


  function match_TableRow(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_TableRow(token);
    });
  }


  function match_Language(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_Language(token);
    });
  }


  function match_Other(context, token) {
    if(token.isEof) return false;
    return handleExternalError(context, false, function () {
      return context.tokenMatcher.match_Other(token);
    });
  }



  function lookahead_0(context, currentToken) {
    currentToken.detach();
    var token;
    var queue = [];
    var match = false;
    do {
      token = readToken(context);
      token.detach();
      queue.push(token);

      if (false  || match_ExamplesLine(context, token)) {
        match = true;
        break;
      }
    } while(false  || match_Empty(context, token) || match_Comment(context, token) || match_TagLine(context, token));

    context.tokenQueue = context.tokenQueue.concat(queue);

    return match;
  }


}

},{"./ast_builder":2,"./errors":6,"./token_matcher":13,"./token_scanner":14}],11:[function(require,module,exports){
var countSymbols = require('../count_symbols');

function Compiler() {
  this.compile = function (gherkin_document) {
    var pickles = [];

    if (gherkin_document.feature == null) return pickles;

    var feature = gherkin_document.feature;
    var language = feature.language;
    var featureTags = feature.tags;
    var backgroundSteps = [];

    feature.children.forEach(function (scenarioDefinition) {
      if(scenarioDefinition.type === 'Background') {
        backgroundSteps = pickleSteps(scenarioDefinition);
      } else if(scenarioDefinition.type === 'Scenario') {
        compileScenario(featureTags, backgroundSteps, scenarioDefinition, language, pickles);
      } else {
        compileScenarioOutline(featureTags, backgroundSteps, scenarioDefinition, language, pickles);
      }
    });
    return pickles;
  };

  function compileScenario(featureTags, backgroundSteps, scenario, language, pickles) {
    var steps = scenario.steps.length == 0 ? [] : [].concat(backgroundSteps);

    var tags = [].concat(featureTags).concat(scenario.tags);

    scenario.steps.forEach(function (step) {
      steps.push(pickleStep(step));
    });

    var pickle = {
      tags: pickleTags(tags),
      name: scenario.name,
      language: language,
      locations: [pickleLocation(scenario.location)],
      steps: steps
    };
    pickles.push(pickle);
  }

  function compileScenarioOutline(featureTags, backgroundSteps, scenarioOutline, language, pickles) {
    scenarioOutline.examples.filter(function(e) { return e.tableHeader != undefined; }).forEach(function (examples) {
      var variableCells = examples.tableHeader.cells;
      examples.tableBody.forEach(function (values) {
        var valueCells = values.cells;
        var steps = scenarioOutline.steps.length == 0 ? [] : [].concat(backgroundSteps);
        var tags = [].concat(featureTags).concat(scenarioOutline.tags).concat(examples.tags);

        scenarioOutline.steps.forEach(function (scenarioOutlineStep) {
          var stepText = interpolate(scenarioOutlineStep.text, variableCells, valueCells);
          var args = createPickleArguments(scenarioOutlineStep.argument, variableCells, valueCells);
          var pickleStep = {
            text: stepText,
            arguments: args,
            locations: [
              pickleLocation(values.location),
              pickleStepLocation(scenarioOutlineStep)
            ]
          };
          steps.push(pickleStep);
        });

        var pickle = {
          name: interpolate(scenarioOutline.name, variableCells, valueCells),
          language: language,
          steps: steps,
          tags: pickleTags(tags),
          locations: [
            pickleLocation(values.location),
            pickleLocation(scenarioOutline.location)
          ]
        };
        pickles.push(pickle);

      });
    });
  }

  function createPickleArguments(argument, variableCells, valueCells) {
    var result = [];
    if (!argument) return result;
    if (argument.type === 'DataTable') {
      var table = {
        rows: argument.rows.map(function (row) {
          return {
            cells: row.cells.map(function (cell) {
              return {
                location: pickleLocation(cell.location),
                value: interpolate(cell.value, variableCells, valueCells)
              };
            })
          };
        })
      };
      result.push(table);
    } else if (argument.type === 'DocString') {
      var docString = {
        location: pickleLocation(argument.location),
        content: interpolate(argument.content, variableCells, valueCells),
      };
      if(argument.contentType) {
        docString.contentType = interpolate(argument.contentType, variableCells, valueCells);
      }
      result.push(docString);
    } else {
      throw Error('Internal error');
    }
    return result;
  }

  function interpolate(name, variableCells, valueCells) {
    variableCells.forEach(function (variableCell, n) {
      var valueCell = valueCells[n];
      var search = new RegExp('<' + variableCell.value + '>', 'g');
      // JS Specific - dollar sign needs to be escaped with another dollar sign
      // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace#Specifying_a_string_as_a_parameter
      var replacement = valueCell.value.replace(new RegExp('\\$', 'g'), '$$$$')
      name = name.replace(search, replacement);
    });
    return name;
  }

  function pickleSteps(scenarioDefinition) {
    return scenarioDefinition.steps.map(function (step) {
      return pickleStep(step);
    });
  }

  function pickleStep(step) {
    return {
      text: step.text,
      arguments: createPickleArguments(step.argument, [], []),
      locations: [pickleStepLocation(step)]
    }
  }

  function pickleStepLocation(step) {
    return {
      line: step.location.line,
      column: step.location.column + (step.keyword ? countSymbols(step.keyword) : 0)
    };
  }

  function pickleLocation(location) {
    return {
      line: location.line,
      column: location.column
    }
  }

  function pickleTags(tags) {
    return tags.map(function (tag) {
      return pickleTag(tag);
    });
  }

  function pickleTag(tag) {
    return {
      name: tag.name,
      location: pickleLocation(tag.location)
    };
  }
}

module.exports = Compiler;

},{"../count_symbols":4}],12:[function(require,module,exports){
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

},{}],13:[function(require,module,exports){
var DIALECTS = require('./dialects');
var Errors = require('./errors');
var LANGUAGE_PATTERN = /^\s*#\s*language\s*:\s*([a-zA-Z\-_]+)\s*$/;

module.exports = function TokenMatcher(defaultDialectName) {
  defaultDialectName = defaultDialectName || 'en';

  var dialect;
  var dialectName;
  var activeDocStringSeparator;
  var indentToRemove;

  function changeDialect(newDialectName, location) {
    var newDialect = DIALECTS[newDialectName];
    if(!newDialect) {
      throw Errors.NoSuchLanguageException.create(newDialectName, location);
    }

    dialectName = newDialectName;
    dialect = newDialect;
  }

  this.reset = function () {
    if(dialectName != defaultDialectName) changeDialect(defaultDialectName);
    activeDocStringSeparator = null;
    indentToRemove = 0;
  };

  this.reset();

  this.match_TagLine = function match_TagLine(token) {
    if(token.line.startsWith('@')) {
      setTokenMatched(token, 'TagLine', null, null, null, token.line.getTags());
      return true;
    }
    return false;
  };

  this.match_FeatureLine = function match_FeatureLine(token) {
    return matchTitleLine(token, 'FeatureLine', dialect.feature);
  };

  this.match_ScenarioLine = function match_ScenarioLine(token) {
    return matchTitleLine(token, 'ScenarioLine', dialect.scenario);
  };

  this.match_ScenarioOutlineLine = function match_ScenarioOutlineLine(token) {
    return matchTitleLine(token, 'ScenarioOutlineLine', dialect.scenarioOutline);
  };

  this.match_BackgroundLine = function match_BackgroundLine(token) {
    return matchTitleLine(token, 'BackgroundLine', dialect.background);
  };

  this.match_ExamplesLine = function match_ExamplesLine(token) {
    return matchTitleLine(token, 'ExamplesLine', dialect.examples);
  };

  this.match_TableRow = function match_TableRow(token) {
    if (token.line.startsWith('|')) {
      // TODO: indent
      setTokenMatched(token, 'TableRow', null, null, null, token.line.getTableCells());
      return true;
    }
    return false;
  };

  this.match_Empty = function match_Empty(token) {
    if (token.line.isEmpty) {
      setTokenMatched(token, 'Empty', null, null, 0);
      return true;
    }
    return false;
  };

  this.match_Comment = function match_Comment(token) {
    if(token.line.startsWith('#')) {
      var text = token.line.getLineText(0); //take the entire line, including leading space
      setTokenMatched(token, 'Comment', text, null, 0);
      return true;
    }
    return false;
  };

  this.match_Language = function match_Language(token) {
    var match;
    if(match = token.line.trimmedLineText.match(LANGUAGE_PATTERN)) {
      var newDialectName = match[1];
      setTokenMatched(token, 'Language', newDialectName);

      changeDialect(newDialectName, token.location);
      return true;
    }
    return false;
  };

  this.match_DocStringSeparator = function match_DocStringSeparator(token) {
    return activeDocStringSeparator == null
      ?
      // open
      _match_DocStringSeparator(token, '"""', true) ||
      _match_DocStringSeparator(token, '```', true)
      :
      // close
      _match_DocStringSeparator(token, activeDocStringSeparator, false);
  };

  function _match_DocStringSeparator(token, separator, isOpen) {
    if (token.line.startsWith(separator)) {
      var contentType = null;
      if (isOpen) {
        contentType = token.line.getRestTrimmed(separator.length);
        activeDocStringSeparator = separator;
        indentToRemove = token.line.indent;
      } else {
        activeDocStringSeparator = null;
        indentToRemove = 0;
      }

      // TODO: Use the separator as keyword. That's needed for pretty printing.
      setTokenMatched(token, 'DocStringSeparator', contentType);
      return true;
    }
    return false;
  }

  this.match_EOF = function match_EOF(token) {
    if(token.isEof) {
      setTokenMatched(token, 'EOF');
      return true;
    }
    return false;
  };

  this.match_StepLine = function match_StepLine(token) {
    var keywords = []
      .concat(dialect.given)
      .concat(dialect.when)
      .concat(dialect.then)
      .concat(dialect.and)
      .concat(dialect.but);
    var length = keywords.length;
    for(var i = 0, keyword; i < length; i++) {
      var keyword = keywords[i];

      if (token.line.startsWith(keyword)) {
        var title = token.line.getRestTrimmed(keyword.length);
        setTokenMatched(token, 'StepLine', title, keyword);
        return true;
      }
    }
    return false;
  };

  this.match_Other = function match_Other(token) {
    var text = token.line.getLineText(indentToRemove); //take the entire line, except removing DocString indents
    setTokenMatched(token, 'Other', unescapeDocString(text), null, 0);
    return true;
  };

  function matchTitleLine(token, tokenType, keywords) {
    var length = keywords.length;
    for(var i = 0, keyword; i < length; i++) {
      var keyword = keywords[i];

      if (token.line.startsWithTitleKeyword(keyword)) {
        var title = token.line.getRestTrimmed(keyword.length + ':'.length);
        setTokenMatched(token, tokenType, title, keyword);
        return true;
      }
    }
    return false;
  }

  function setTokenMatched(token, matchedType, text, keyword, indent, items) {
    token.matchedType = matchedType;
    token.matchedText = text;
    token.matchedKeyword = keyword;
    token.matchedIndent = (typeof indent === 'number') ? indent : (token.line == null ? 0 : token.line.indent);
    token.matchedItems = items || [];

    token.location.column = token.matchedIndent + 1;
    token.matchedGherkinDialect = dialectName;
  }

  function unescapeDocString(text) {
    return activeDocStringSeparator != null ? text.replace("\\\"\\\"\\\"", "\"\"\"") : text;
  }
};

},{"./dialects":5,"./errors":6}],14:[function(require,module,exports){
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

},{"./gherkin_line":9,"./token":12}]},{},[1]);
