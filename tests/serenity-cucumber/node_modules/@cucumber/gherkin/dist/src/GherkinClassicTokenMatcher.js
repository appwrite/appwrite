"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const gherkin_languages_json_1 = __importDefault(require("./gherkin-languages.json"));
const Errors_1 = require("./Errors");
const messages = __importStar(require("@cucumber/messages"));
const Parser_1 = require("./Parser");
const countSymbols_1 = __importDefault(require("./countSymbols"));
const DIALECT_DICT = gherkin_languages_json_1.default;
const LANGUAGE_PATTERN = /^\s*#\s*language\s*:\s*([a-zA-Z\-_]+)\s*$/;
function addKeywordTypeMappings(h, keywords, keywordType) {
    for (const k of keywords) {
        if (!(k in h)) {
            h[k] = [];
        }
        h[k].push(keywordType);
    }
}
class GherkinClassicTokenMatcher {
    constructor(defaultDialectName = 'en') {
        this.defaultDialectName = defaultDialectName;
        this.reset();
    }
    changeDialect(newDialectName, location) {
        const newDialect = DIALECT_DICT[newDialectName];
        if (!newDialect) {
            throw Errors_1.NoSuchLanguageException.create(newDialectName, location);
        }
        this.dialectName = newDialectName;
        this.dialect = newDialect;
        this.initializeKeywordTypes();
    }
    reset() {
        if (this.dialectName !== this.defaultDialectName) {
            this.changeDialect(this.defaultDialectName);
        }
        this.activeDocStringSeparator = null;
        this.indentToRemove = 0;
    }
    initializeKeywordTypes() {
        this.keywordTypesMap = {};
        addKeywordTypeMappings(this.keywordTypesMap, this.dialect.given, messages.StepKeywordType.CONTEXT);
        addKeywordTypeMappings(this.keywordTypesMap, this.dialect.when, messages.StepKeywordType.ACTION);
        addKeywordTypeMappings(this.keywordTypesMap, this.dialect.then, messages.StepKeywordType.OUTCOME);
        addKeywordTypeMappings(this.keywordTypesMap, [].concat(this.dialect.and).concat(this.dialect.but), messages.StepKeywordType.CONJUNCTION);
    }
    match_TagLine(token) {
        if (token.line.startsWith('@')) {
            this.setTokenMatched(token, Parser_1.TokenType.TagLine, null, null, null, null, this.getTags(token.line));
            return true;
        }
        return false;
    }
    match_FeatureLine(token) {
        return this.matchTitleLine(token, Parser_1.TokenType.FeatureLine, this.dialect.feature);
    }
    match_ScenarioLine(token) {
        return (this.matchTitleLine(token, Parser_1.TokenType.ScenarioLine, this.dialect.scenario) ||
            this.matchTitleLine(token, Parser_1.TokenType.ScenarioLine, this.dialect.scenarioOutline));
    }
    match_BackgroundLine(token) {
        return this.matchTitleLine(token, Parser_1.TokenType.BackgroundLine, this.dialect.background);
    }
    match_ExamplesLine(token) {
        return this.matchTitleLine(token, Parser_1.TokenType.ExamplesLine, this.dialect.examples);
    }
    match_RuleLine(token) {
        return this.matchTitleLine(token, Parser_1.TokenType.RuleLine, this.dialect.rule);
    }
    match_TableRow(token) {
        if (token.line.startsWith('|')) {
            // TODO: indent
            this.setTokenMatched(token, Parser_1.TokenType.TableRow, null, null, null, null, token.line.getTableCells());
            return true;
        }
        return false;
    }
    match_Empty(token) {
        if (token.line.isEmpty) {
            this.setTokenMatched(token, Parser_1.TokenType.Empty, null, null, 0);
            return true;
        }
        return false;
    }
    match_Comment(token) {
        if (token.line.startsWith('#')) {
            const text = token.line.getLineText(0); // take the entire line, including leading space
            this.setTokenMatched(token, Parser_1.TokenType.Comment, text, null, 0);
            return true;
        }
        return false;
    }
    match_Language(token) {
        const match = token.line.trimmedLineText.match(LANGUAGE_PATTERN);
        if (match) {
            const newDialectName = match[1];
            this.setTokenMatched(token, Parser_1.TokenType.Language, newDialectName);
            this.changeDialect(newDialectName, token.location);
            return true;
        }
        return false;
    }
    match_DocStringSeparator(token) {
        return this.activeDocStringSeparator == null
            ? // open
                this._match_DocStringSeparator(token, '"""', true) ||
                    this._match_DocStringSeparator(token, '```', true)
            : // close
                this._match_DocStringSeparator(token, this.activeDocStringSeparator, false);
    }
    _match_DocStringSeparator(token, separator, isOpen) {
        if (token.line.startsWith(separator)) {
            let mediaType = null;
            if (isOpen) {
                mediaType = token.line.getRestTrimmed(separator.length);
                this.activeDocStringSeparator = separator;
                this.indentToRemove = token.line.indent;
            }
            else {
                this.activeDocStringSeparator = null;
                this.indentToRemove = 0;
            }
            this.setTokenMatched(token, Parser_1.TokenType.DocStringSeparator, mediaType, separator);
            return true;
        }
        return false;
    }
    match_EOF(token) {
        if (token.isEof) {
            this.setTokenMatched(token, Parser_1.TokenType.EOF);
            return true;
        }
        return false;
    }
    match_StepLine(token) {
        const keywords = []
            .concat(this.dialect.given)
            .concat(this.dialect.when)
            .concat(this.dialect.then)
            .concat(this.dialect.and)
            .concat(this.dialect.but);
        for (const keyword of keywords) {
            if (token.line.startsWith(keyword)) {
                const title = token.line.getRestTrimmed(keyword.length);
                const keywordTypes = this.keywordTypesMap[keyword];
                let keywordType = keywordTypes[0];
                if (keywordTypes.length > 1) {
                    keywordType = messages.StepKeywordType.UNKNOWN;
                }
                this.setTokenMatched(token, Parser_1.TokenType.StepLine, title, keyword, null, keywordType);
                return true;
            }
        }
        return false;
    }
    match_Other(token) {
        const text = token.line.getLineText(this.indentToRemove); // take the entire line, except removing DocString indents
        this.setTokenMatched(token, Parser_1.TokenType.Other, this.unescapeDocString(text), null, 0);
        return true;
    }
    getTags(line) {
        const uncommentedLine = line.trimmedLineText.split(/\s#/g, 2)[0];
        let column = line.indent + 1;
        const items = uncommentedLine.split('@');
        const tags = [];
        for (let i = 0; i < items.length; i++) {
            const item = items[i].trimRight();
            if (item.length == 0) {
                continue;
            }
            if (!item.match(/^\S+$/)) {
                throw Errors_1.ParserException.create('A tag may not contain whitespace', line.lineNumber, column);
            }
            const span = { column, text: '@' + item };
            tags.push(span);
            column += (0, countSymbols_1.default)(items[i]) + 1;
        }
        return tags;
    }
    matchTitleLine(token, tokenType, keywords) {
        for (const keyword of keywords) {
            if (token.line.startsWithTitleKeyword(keyword)) {
                const title = token.line.getRestTrimmed(keyword.length + ':'.length);
                this.setTokenMatched(token, tokenType, title, keyword);
                return true;
            }
        }
        return false;
    }
    setTokenMatched(token, matchedType, text, keyword, indent, keywordType, items) {
        token.matchedType = matchedType;
        token.matchedText = text;
        token.matchedKeyword = keyword;
        token.matchedKeywordType = keywordType;
        token.matchedIndent =
            typeof indent === 'number' ? indent : token.line == null ? 0 : token.line.indent;
        token.matchedItems = items || [];
        token.location.column = token.matchedIndent + 1;
        token.matchedGherkinDialect = this.dialectName;
    }
    unescapeDocString(text) {
        if (this.activeDocStringSeparator === '"""') {
            return text.replace('\\"\\"\\"', '"""');
        }
        if (this.activeDocStringSeparator === '```') {
            return text.replace('\\`\\`\\`', '```');
        }
        return text;
    }
}
exports.default = GherkinClassicTokenMatcher;
//# sourceMappingURL=GherkinClassicTokenMatcher.js.map