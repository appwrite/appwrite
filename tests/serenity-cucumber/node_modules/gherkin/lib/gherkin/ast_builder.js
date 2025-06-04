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
