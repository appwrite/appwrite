"use strict";
// This file is generated. Do not edit! Edit gherkin-javascript.razor instead.
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.RuleType = exports.TokenType = exports.Token = void 0;
const Errors_1 = require("./Errors");
const TokenExceptions_1 = require("./TokenExceptions");
const TokenScanner_1 = __importDefault(require("./TokenScanner"));
const GherkinLine_1 = __importDefault(require("./GherkinLine"));
class Token {
    constructor(line, location) {
        this.line = line;
        this.location = location;
        this.isEof = !line;
    }
    getTokenValue() {
        return this.isEof ? 'EOF' : this.line.getLineText(-1);
    }
    detach() {
        // TODO: Detach line, but is this really needed?
    }
}
exports.Token = Token;
var TokenType;
(function (TokenType) {
    TokenType[TokenType["None"] = 0] = "None";
    TokenType[TokenType["EOF"] = 1] = "EOF";
    TokenType[TokenType["Empty"] = 2] = "Empty";
    TokenType[TokenType["Comment"] = 3] = "Comment";
    TokenType[TokenType["TagLine"] = 4] = "TagLine";
    TokenType[TokenType["FeatureLine"] = 5] = "FeatureLine";
    TokenType[TokenType["RuleLine"] = 6] = "RuleLine";
    TokenType[TokenType["BackgroundLine"] = 7] = "BackgroundLine";
    TokenType[TokenType["ScenarioLine"] = 8] = "ScenarioLine";
    TokenType[TokenType["ExamplesLine"] = 9] = "ExamplesLine";
    TokenType[TokenType["StepLine"] = 10] = "StepLine";
    TokenType[TokenType["DocStringSeparator"] = 11] = "DocStringSeparator";
    TokenType[TokenType["TableRow"] = 12] = "TableRow";
    TokenType[TokenType["Language"] = 13] = "Language";
    TokenType[TokenType["Other"] = 14] = "Other";
})(TokenType = exports.TokenType || (exports.TokenType = {}));
var RuleType;
(function (RuleType) {
    RuleType[RuleType["None"] = 0] = "None";
    RuleType[RuleType["_EOF"] = 1] = "_EOF";
    RuleType[RuleType["_Empty"] = 2] = "_Empty";
    RuleType[RuleType["_Comment"] = 3] = "_Comment";
    RuleType[RuleType["_TagLine"] = 4] = "_TagLine";
    RuleType[RuleType["_FeatureLine"] = 5] = "_FeatureLine";
    RuleType[RuleType["_RuleLine"] = 6] = "_RuleLine";
    RuleType[RuleType["_BackgroundLine"] = 7] = "_BackgroundLine";
    RuleType[RuleType["_ScenarioLine"] = 8] = "_ScenarioLine";
    RuleType[RuleType["_ExamplesLine"] = 9] = "_ExamplesLine";
    RuleType[RuleType["_StepLine"] = 10] = "_StepLine";
    RuleType[RuleType["_DocStringSeparator"] = 11] = "_DocStringSeparator";
    RuleType[RuleType["_TableRow"] = 12] = "_TableRow";
    RuleType[RuleType["_Language"] = 13] = "_Language";
    RuleType[RuleType["_Other"] = 14] = "_Other";
    RuleType[RuleType["GherkinDocument"] = 15] = "GherkinDocument";
    RuleType[RuleType["Feature"] = 16] = "Feature";
    RuleType[RuleType["FeatureHeader"] = 17] = "FeatureHeader";
    RuleType[RuleType["Rule"] = 18] = "Rule";
    RuleType[RuleType["RuleHeader"] = 19] = "RuleHeader";
    RuleType[RuleType["Background"] = 20] = "Background";
    RuleType[RuleType["ScenarioDefinition"] = 21] = "ScenarioDefinition";
    RuleType[RuleType["Scenario"] = 22] = "Scenario";
    RuleType[RuleType["ExamplesDefinition"] = 23] = "ExamplesDefinition";
    RuleType[RuleType["Examples"] = 24] = "Examples";
    RuleType[RuleType["ExamplesTable"] = 25] = "ExamplesTable";
    RuleType[RuleType["Step"] = 26] = "Step";
    RuleType[RuleType["StepArg"] = 27] = "StepArg";
    RuleType[RuleType["DataTable"] = 28] = "DataTable";
    RuleType[RuleType["DocString"] = 29] = "DocString";
    RuleType[RuleType["Tags"] = 30] = "Tags";
    RuleType[RuleType["DescriptionHelper"] = 31] = "DescriptionHelper";
    RuleType[RuleType["Description"] = 32] = "Description";
})(RuleType = exports.RuleType || (exports.RuleType = {}));
class Parser {
    constructor(builder, tokenMatcher) {
        this.builder = builder;
        this.tokenMatcher = tokenMatcher;
        this.stopAtFirstError = false;
    }
    parse(gherkinSource) {
        const tokenScanner = new TokenScanner_1.default(gherkinSource, (line, location) => {
            const gherkinLine = line === null || line === undefined
                ? null
                : new GherkinLine_1.default(line, location.line);
            return new Token(gherkinLine, location);
        });
        this.builder.reset();
        this.tokenMatcher.reset();
        this.context = {
            tokenScanner,
            tokenQueue: [],
            errors: [],
        };
        this.startRule(this.context, RuleType.GherkinDocument);
        let state = 0;
        let token = null;
        while (true) {
            token = this.readToken(this.context);
            state = this.matchToken(state, token, this.context);
            if (token.isEof)
                break;
        }
        this.endRule(this.context);
        if (this.context.errors.length > 0) {
            throw Errors_1.CompositeParserException.create(this.context.errors);
        }
        return this.getResult();
    }
    addError(context, error) {
        if (!context.errors.map(e => { return e.message; }).includes(error.message)) {
            context.errors.push(error);
            if (context.errors.length > 10)
                throw Errors_1.CompositeParserException.create(context.errors);
        }
    }
    startRule(context, ruleType) {
        this.handleAstError(context, () => this.builder.startRule(ruleType));
    }
    endRule(context) {
        this.handleAstError(context, () => this.builder.endRule());
    }
    build(context, token) {
        this.handleAstError(context, () => this.builder.build(token));
    }
    getResult() {
        return this.builder.getResult();
    }
    handleAstError(context, action) {
        this.handleExternalError(context, true, action);
    }
    handleExternalError(context, defaultValue, action) {
        if (this.stopAtFirstError)
            return action();
        try {
            return action();
        }
        catch (e) {
            if (e instanceof Errors_1.CompositeParserException) {
                e.errors.forEach((error) => this.addError(context, error));
            }
            else if (e instanceof Errors_1.ParserException ||
                e instanceof Errors_1.AstBuilderException ||
                e instanceof TokenExceptions_1.UnexpectedTokenException ||
                e instanceof Errors_1.NoSuchLanguageException) {
                this.addError(context, e);
            }
            else {
                throw e;
            }
        }
        return defaultValue;
    }
    readToken(context) {
        return context.tokenQueue.length > 0
            ? context.tokenQueue.shift()
            : context.tokenScanner.read();
    }
    matchToken(state, token, context) {
        switch (state) {
            case 0:
                return this.matchTokenAt_0(token, context);
            case 1:
                return this.matchTokenAt_1(token, context);
            case 2:
                return this.matchTokenAt_2(token, context);
            case 3:
                return this.matchTokenAt_3(token, context);
            case 4:
                return this.matchTokenAt_4(token, context);
            case 5:
                return this.matchTokenAt_5(token, context);
            case 6:
                return this.matchTokenAt_6(token, context);
            case 7:
                return this.matchTokenAt_7(token, context);
            case 8:
                return this.matchTokenAt_8(token, context);
            case 9:
                return this.matchTokenAt_9(token, context);
            case 10:
                return this.matchTokenAt_10(token, context);
            case 11:
                return this.matchTokenAt_11(token, context);
            case 12:
                return this.matchTokenAt_12(token, context);
            case 13:
                return this.matchTokenAt_13(token, context);
            case 14:
                return this.matchTokenAt_14(token, context);
            case 15:
                return this.matchTokenAt_15(token, context);
            case 16:
                return this.matchTokenAt_16(token, context);
            case 17:
                return this.matchTokenAt_17(token, context);
            case 18:
                return this.matchTokenAt_18(token, context);
            case 19:
                return this.matchTokenAt_19(token, context);
            case 20:
                return this.matchTokenAt_20(token, context);
            case 21:
                return this.matchTokenAt_21(token, context);
            case 22:
                return this.matchTokenAt_22(token, context);
            case 23:
                return this.matchTokenAt_23(token, context);
            case 24:
                return this.matchTokenAt_24(token, context);
            case 25:
                return this.matchTokenAt_25(token, context);
            case 26:
                return this.matchTokenAt_26(token, context);
            case 27:
                return this.matchTokenAt_27(token, context);
            case 28:
                return this.matchTokenAt_28(token, context);
            case 29:
                return this.matchTokenAt_29(token, context);
            case 30:
                return this.matchTokenAt_30(token, context);
            case 31:
                return this.matchTokenAt_31(token, context);
            case 32:
                return this.matchTokenAt_32(token, context);
            case 33:
                return this.matchTokenAt_33(token, context);
            case 34:
                return this.matchTokenAt_34(token, context);
            case 35:
                return this.matchTokenAt_35(token, context);
            case 36:
                return this.matchTokenAt_36(token, context);
            case 37:
                return this.matchTokenAt_37(token, context);
            case 38:
                return this.matchTokenAt_38(token, context);
            case 39:
                return this.matchTokenAt_39(token, context);
            case 40:
                return this.matchTokenAt_40(token, context);
            case 41:
                return this.matchTokenAt_41(token, context);
            case 43:
                return this.matchTokenAt_43(token, context);
            case 44:
                return this.matchTokenAt_44(token, context);
            case 45:
                return this.matchTokenAt_45(token, context);
            case 46:
                return this.matchTokenAt_46(token, context);
            case 47:
                return this.matchTokenAt_47(token, context);
            case 48:
                return this.matchTokenAt_48(token, context);
            case 49:
                return this.matchTokenAt_49(token, context);
            case 50:
                return this.matchTokenAt_50(token, context);
            default:
                throw new Error("Unknown state: " + state);
        }
    }
    // Start
    matchTokenAt_0(token, context) {
        if (this.match_EOF(context, token)) {
            this.build(context, token);
            return 42;
        }
        if (this.match_Language(context, token)) {
            this.startRule(context, RuleType.Feature);
            this.startRule(context, RuleType.FeatureHeader);
            this.build(context, token);
            return 1;
        }
        if (this.match_TagLine(context, token)) {
            this.startRule(context, RuleType.Feature);
            this.startRule(context, RuleType.FeatureHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 2;
        }
        if (this.match_FeatureLine(context, token)) {
            this.startRule(context, RuleType.Feature);
            this.startRule(context, RuleType.FeatureHeader);
            this.build(context, token);
            return 3;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 0;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 0;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Language", "#TagLine", "#FeatureLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 0;
    }
    // GherkinDocument:0>Feature:0>FeatureHeader:0>#Language:0
    matchTokenAt_1(token, context) {
        if (this.match_TagLine(context, token)) {
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 2;
        }
        if (this.match_FeatureLine(context, token)) {
            this.build(context, token);
            return 3;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 1;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 1;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#FeatureLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 1;
    }
    // GherkinDocument:0>Feature:0>FeatureHeader:1>Tags:0>#TagLine:0
    matchTokenAt_2(token, context) {
        if (this.match_TagLine(context, token)) {
            this.build(context, token);
            return 2;
        }
        if (this.match_FeatureLine(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 3;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 2;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 2;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#FeatureLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 2;
    }
    // GherkinDocument:0>Feature:0>FeatureHeader:2>#FeatureLine:0
    matchTokenAt_3(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 3;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 5;
        }
        if (this.match_BackgroundLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Background);
            this.build(context, token);
            return 6;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 4;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 3;
    }
    // GherkinDocument:0>Feature:0>FeatureHeader:3>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_4(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 5;
        }
        if (this.match_BackgroundLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Background);
            this.build(context, token);
            return 6;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 4;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 4;
    }
    // GherkinDocument:0>Feature:0>FeatureHeader:3>DescriptionHelper:2>#Comment:0
    matchTokenAt_5(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 5;
        }
        if (this.match_BackgroundLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Background);
            this.build(context, token);
            return 6;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 5;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 5;
    }
    // GherkinDocument:0>Feature:1>Background:0>#BackgroundLine:0
    matchTokenAt_6(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 6;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 8;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 9;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 7;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 6;
    }
    // GherkinDocument:0>Feature:1>Background:1>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_7(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 8;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 9;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 7;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 7;
    }
    // GherkinDocument:0>Feature:1>Background:1>DescriptionHelper:2>#Comment:0
    matchTokenAt_8(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 8;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 9;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 8;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 8;
    }
    // GherkinDocument:0>Feature:1>Background:2>Step:0>#StepLine:0
    matchTokenAt_9(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.DataTable);
            this.build(context, token);
            return 10;
        }
        if (this.match_DocStringSeparator(context, token)) {
            this.startRule(context, RuleType.DocString);
            this.build(context, token);
            return 49;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 9;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 9;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 9;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 9;
    }
    // GherkinDocument:0>Feature:1>Background:2>Step:1>StepArg:0>__alt0:0>DataTable:0>#TableRow:0
    matchTokenAt_10(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.build(context, token);
            return 10;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 9;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 10;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 10;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 10;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:0>Tags:0>#TagLine:0
    matchTokenAt_11(token, context) {
        if (this.match_TagLine(context, token)) {
            this.build(context, token);
            return 11;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 11;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 11;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#ScenarioLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 11;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:0>#ScenarioLine:0
    matchTokenAt_12(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 12;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 14;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 15;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 13;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 12;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:1>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_13(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 14;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 15;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 13;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 13;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:1>DescriptionHelper:2>#Comment:0
    matchTokenAt_14(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 14;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 15;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 14;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 14;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:2>Step:0>#StepLine:0
    matchTokenAt_15(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.DataTable);
            this.build(context, token);
            return 16;
        }
        if (this.match_DocStringSeparator(context, token)) {
            this.startRule(context, RuleType.DocString);
            this.build(context, token);
            return 47;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 15;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 15;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 15;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 15;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:2>Step:1>StepArg:0>__alt0:0>DataTable:0>#TableRow:0
    matchTokenAt_16(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.build(context, token);
            return 16;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 15;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 16;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 16;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 16;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:0>Tags:0>#TagLine:0
    matchTokenAt_17(token, context) {
        if (this.match_TagLine(context, token)) {
            this.build(context, token);
            return 17;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 17;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 17;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#ExamplesLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 17;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:0>#ExamplesLine:0
    matchTokenAt_18(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 18;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 20;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.ExamplesTable);
            this.build(context, token);
            return 21;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 19;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 18;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:1>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_19(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 20;
        }
        if (this.match_TableRow(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesTable);
            this.build(context, token);
            return 21;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 19;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 19;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:1>DescriptionHelper:2>#Comment:0
    matchTokenAt_20(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 20;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.ExamplesTable);
            this.build(context, token);
            return 21;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 20;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 20;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:2>ExamplesTable:0>#TableRow:0
    matchTokenAt_21(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.build(context, token);
            return 21;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 21;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 21;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 21;
    }
    // GherkinDocument:0>Feature:3>Rule:0>RuleHeader:0>Tags:0>#TagLine:0
    matchTokenAt_22(token, context) {
        if (this.match_TagLine(context, token)) {
            this.build(context, token);
            return 22;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 22;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 22;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 22;
    }
    // GherkinDocument:0>Feature:3>Rule:0>RuleHeader:1>#RuleLine:0
    matchTokenAt_23(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 25;
        }
        if (this.match_BackgroundLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Background);
            this.build(context, token);
            return 26;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 24;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 23;
    }
    // GherkinDocument:0>Feature:3>Rule:0>RuleHeader:2>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_24(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 25;
        }
        if (this.match_BackgroundLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Background);
            this.build(context, token);
            return 26;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 24;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 24;
    }
    // GherkinDocument:0>Feature:3>Rule:0>RuleHeader:2>DescriptionHelper:2>#Comment:0
    matchTokenAt_25(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 25;
        }
        if (this.match_BackgroundLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Background);
            this.build(context, token);
            return 26;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 25;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#BackgroundLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 25;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:0>#BackgroundLine:0
    matchTokenAt_26(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 26;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 28;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 29;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 27;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 26;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:1>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_27(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 28;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 29;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 27;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 27;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:1>DescriptionHelper:2>#Comment:0
    matchTokenAt_28(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 28;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 29;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 28;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 28;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:2>Step:0>#StepLine:0
    matchTokenAt_29(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.DataTable);
            this.build(context, token);
            return 30;
        }
        if (this.match_DocStringSeparator(context, token)) {
            this.startRule(context, RuleType.DocString);
            this.build(context, token);
            return 45;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 29;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 29;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 29;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 29;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:2>Step:1>StepArg:0>__alt0:0>DataTable:0>#TableRow:0
    matchTokenAt_30(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.build(context, token);
            return 30;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 29;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 30;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 30;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 30;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:0>Tags:0>#TagLine:0
    matchTokenAt_31(token, context) {
        if (this.match_TagLine(context, token)) {
            this.build(context, token);
            return 31;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 31;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 31;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#ScenarioLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 31;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:0>#ScenarioLine:0
    matchTokenAt_32(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 32;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 34;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 35;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 33;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 32;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:1>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_33(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 34;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 35;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 33;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 33;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:1>DescriptionHelper:2>#Comment:0
    matchTokenAt_34(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 34;
        }
        if (this.match_StepLine(context, token)) {
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 35;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 34;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 34;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:2>Step:0>#StepLine:0
    matchTokenAt_35(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.DataTable);
            this.build(context, token);
            return 36;
        }
        if (this.match_DocStringSeparator(context, token)) {
            this.startRule(context, RuleType.DocString);
            this.build(context, token);
            return 43;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 35;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 35;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 35;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#DocStringSeparator", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 35;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:2>Step:1>StepArg:0>__alt0:0>DataTable:0>#TableRow:0
    matchTokenAt_36(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.build(context, token);
            return 36;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 35;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 36;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 36;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 36;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:0>Tags:0>#TagLine:0
    matchTokenAt_37(token, context) {
        if (this.match_TagLine(context, token)) {
            this.build(context, token);
            return 37;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 37;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 37;
        }
        token.detach();
        const expectedTokens = ["#TagLine", "#ExamplesLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 37;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:0>#ExamplesLine:0
    matchTokenAt_38(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 38;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 40;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.ExamplesTable);
            this.build(context, token);
            return 41;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.startRule(context, RuleType.Description);
            this.build(context, token);
            return 39;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Empty", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 38;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:1>DescriptionHelper:1>Description:0>#Other:0
    matchTokenAt_39(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.endRule(context);
            this.build(context, token);
            return 40;
        }
        if (this.match_TableRow(context, token)) {
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesTable);
            this.build(context, token);
            return 41;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 39;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 39;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:1>DescriptionHelper:2>#Comment:0
    matchTokenAt_40(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 40;
        }
        if (this.match_TableRow(context, token)) {
            this.startRule(context, RuleType.ExamplesTable);
            this.build(context, token);
            return 41;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 40;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#Comment", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 40;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:3>ExamplesDefinition:1>Examples:2>ExamplesTable:0>#TableRow:0
    matchTokenAt_41(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_TableRow(context, token)) {
            this.build(context, token);
            return 41;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 41;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 41;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#TableRow", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 41;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:2>Step:1>StepArg:0>__alt0:1>DocString:0>#DocStringSeparator:0
    matchTokenAt_43(token, context) {
        if (this.match_DocStringSeparator(context, token)) {
            this.build(context, token);
            return 44;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 43;
        }
        token.detach();
        const expectedTokens = ["#DocStringSeparator", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 43;
    }
    // GherkinDocument:0>Feature:3>Rule:2>ScenarioDefinition:1>Scenario:2>Step:1>StepArg:0>__alt0:1>DocString:2>#DocStringSeparator:0
    matchTokenAt_44(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 35;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 37;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 38;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 44;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 44;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 44;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:2>Step:1>StepArg:0>__alt0:1>DocString:0>#DocStringSeparator:0
    matchTokenAt_45(token, context) {
        if (this.match_DocStringSeparator(context, token)) {
            this.build(context, token);
            return 46;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 45;
        }
        token.detach();
        const expectedTokens = ["#DocStringSeparator", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 45;
    }
    // GherkinDocument:0>Feature:3>Rule:1>Background:2>Step:1>StepArg:0>__alt0:1>DocString:2>#DocStringSeparator:0
    matchTokenAt_46(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 29;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 31;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 32;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 46;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 46;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 46;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:2>Step:1>StepArg:0>__alt0:1>DocString:0>#DocStringSeparator:0
    matchTokenAt_47(token, context) {
        if (this.match_DocStringSeparator(context, token)) {
            this.build(context, token);
            return 48;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 47;
        }
        token.detach();
        const expectedTokens = ["#DocStringSeparator", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 47;
    }
    // GherkinDocument:0>Feature:2>ScenarioDefinition:1>Scenario:2>Step:1>StepArg:0>__alt0:1>DocString:2>#DocStringSeparator:0
    matchTokenAt_48(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 15;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_1(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ExamplesDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 17;
            }
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ExamplesLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ExamplesDefinition);
            this.startRule(context, RuleType.Examples);
            this.build(context, token);
            return 18;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 48;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 48;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ExamplesLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 48;
    }
    // GherkinDocument:0>Feature:1>Background:2>Step:1>StepArg:0>__alt0:1>DocString:0>#DocStringSeparator:0
    matchTokenAt_49(token, context) {
        if (this.match_DocStringSeparator(context, token)) {
            this.build(context, token);
            return 50;
        }
        if (this.match_Other(context, token)) {
            this.build(context, token);
            return 49;
        }
        token.detach();
        const expectedTokens = ["#DocStringSeparator", "#Other"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 49;
    }
    // GherkinDocument:0>Feature:1>Background:2>Step:1>StepArg:0>__alt0:1>DocString:2>#DocStringSeparator:0
    matchTokenAt_50(token, context) {
        if (this.match_EOF(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.build(context, token);
            return 42;
        }
        if (this.match_StepLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Step);
            this.build(context, token);
            return 9;
        }
        if (this.match_TagLine(context, token)) {
            if (this.lookahead_0(context, token)) {
                this.endRule(context);
                this.endRule(context);
                this.endRule(context);
                this.startRule(context, RuleType.ScenarioDefinition);
                this.startRule(context, RuleType.Tags);
                this.build(context, token);
                return 11;
            }
        }
        if (this.match_TagLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.startRule(context, RuleType.Tags);
            this.build(context, token);
            return 22;
        }
        if (this.match_ScenarioLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.ScenarioDefinition);
            this.startRule(context, RuleType.Scenario);
            this.build(context, token);
            return 12;
        }
        if (this.match_RuleLine(context, token)) {
            this.endRule(context);
            this.endRule(context);
            this.endRule(context);
            this.startRule(context, RuleType.Rule);
            this.startRule(context, RuleType.RuleHeader);
            this.build(context, token);
            return 23;
        }
        if (this.match_Comment(context, token)) {
            this.build(context, token);
            return 50;
        }
        if (this.match_Empty(context, token)) {
            this.build(context, token);
            return 50;
        }
        token.detach();
        const expectedTokens = ["#EOF", "#StepLine", "#TagLine", "#ScenarioLine", "#RuleLine", "#Comment", "#Empty"];
        const error = token.isEof ?
            TokenExceptions_1.UnexpectedEOFException.create(token, expectedTokens) :
            TokenExceptions_1.UnexpectedTokenException.create(token, expectedTokens);
        if (this.stopAtFirstError)
            throw error;
        this.addError(context, error);
        return 50;
    }
    match_EOF(context, token) {
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_EOF(token));
    }
    match_Empty(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_Empty(token));
    }
    match_Comment(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_Comment(token));
    }
    match_TagLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_TagLine(token));
    }
    match_FeatureLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_FeatureLine(token));
    }
    match_RuleLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_RuleLine(token));
    }
    match_BackgroundLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_BackgroundLine(token));
    }
    match_ScenarioLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_ScenarioLine(token));
    }
    match_ExamplesLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_ExamplesLine(token));
    }
    match_StepLine(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_StepLine(token));
    }
    match_DocStringSeparator(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_DocStringSeparator(token));
    }
    match_TableRow(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_TableRow(token));
    }
    match_Language(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_Language(token));
    }
    match_Other(context, token) {
        if (token.isEof)
            return false;
        return this.handleExternalError(context, false, () => this.tokenMatcher.match_Other(token));
    }
    lookahead_0(context, currentToken) {
        currentToken.detach();
        let token;
        const queue = [];
        let match = false;
        do {
            token = this.readToken(this.context);
            token.detach();
            queue.push(token);
            if (false || this.match_ScenarioLine(context, token)) {
                match = true;
                break;
            }
        } while (false || this.match_Empty(context, token) || this.match_Comment(context, token) || this.match_TagLine(context, token));
        context.tokenQueue = context.tokenQueue.concat(queue);
        return match;
    }
    lookahead_1(context, currentToken) {
        currentToken.detach();
        let token;
        const queue = [];
        let match = false;
        do {
            token = this.readToken(this.context);
            token.detach();
            queue.push(token);
            if (false || this.match_ExamplesLine(context, token)) {
                match = true;
                break;
            }
        } while (false || this.match_Empty(context, token) || this.match_Comment(context, token) || this.match_TagLine(context, token));
        context.tokenQueue = context.tokenQueue.concat(queue);
        return match;
    }
}
exports.default = Parser;
//# sourceMappingURL=Parser.js.map