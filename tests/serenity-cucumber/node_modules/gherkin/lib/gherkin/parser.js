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
