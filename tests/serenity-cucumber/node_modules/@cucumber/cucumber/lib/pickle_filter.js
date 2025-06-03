"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PickleTagFilter = exports.PickleNameFilter = exports.PickleLineFilter = void 0;
const path_1 = __importDefault(require("path"));
const tag_expressions_1 = __importDefault(require("@cucumber/tag-expressions"));
const gherkin_document_parser_1 = require("./formatter/helpers/gherkin_document_parser");
const value_checker_1 = require("./value_checker");
const FEATURE_LINENUM_REGEXP = /^(.*?)((?::[\d]+)+)?$/;
class PickleFilter {
    constructor({ cwd, featurePaths, names, tagExpression, }) {
        this.lineFilter = new PickleLineFilter(cwd, featurePaths);
        this.nameFilter = new PickleNameFilter(names);
        this.tagFilter = new PickleTagFilter(tagExpression);
    }
    matches({ gherkinDocument, pickle, }) {
        return (this.lineFilter.matchesAnyLine({ gherkinDocument, pickle }) &&
            this.nameFilter.matchesAnyName(pickle) &&
            this.tagFilter.matchesAllTagExpressions(pickle));
    }
}
exports.default = PickleFilter;
class PickleLineFilter {
    constructor(cwd, featurePaths = []) {
        this.featureUriToLinesMapping = this.getFeatureUriToLinesMapping({
            cwd,
            featurePaths,
        });
    }
    getFeatureUriToLinesMapping({ cwd, featurePaths, }) {
        const mapping = {};
        featurePaths.forEach((featurePath) => {
            const match = FEATURE_LINENUM_REGEXP.exec(featurePath);
            if ((0, value_checker_1.doesHaveValue)(match)) {
                let uri = match[1];
                if (path_1.default.isAbsolute(uri)) {
                    uri = path_1.default.relative(cwd, uri);
                }
                else {
                    uri = path_1.default.normalize(uri);
                }
                const linesExpression = match[2];
                if ((0, value_checker_1.doesHaveValue)(linesExpression)) {
                    if ((0, value_checker_1.doesNotHaveValue)(mapping[uri])) {
                        mapping[uri] = [];
                    }
                    linesExpression
                        .slice(1)
                        .split(':')
                        .forEach((line) => {
                        mapping[uri].push(parseInt(line));
                    });
                }
            }
        });
        return mapping;
    }
    matchesAnyLine({ gherkinDocument, pickle }) {
        const uri = path_1.default.normalize(pickle.uri);
        const linesToMatch = this.featureUriToLinesMapping[uri];
        if ((0, value_checker_1.doesHaveValue)(linesToMatch)) {
            const gherkinScenarioLocationMap = (0, gherkin_document_parser_1.getGherkinScenarioLocationMap)(gherkinDocument);
            const pickleLines = new Set(pickle.astNodeIds.map((sourceId) => gherkinScenarioLocationMap[sourceId].line));
            const linesIntersection = linesToMatch.filter((x) => pickleLines.has(x));
            return linesIntersection.length > 0;
        }
        return true;
    }
}
exports.PickleLineFilter = PickleLineFilter;
class PickleNameFilter {
    constructor(names = []) {
        this.names = names;
    }
    matchesAnyName(pickle) {
        if (this.names.length === 0) {
            return true;
        }
        return this.names.some((name) => pickle.name.match(name));
    }
}
exports.PickleNameFilter = PickleNameFilter;
class PickleTagFilter {
    constructor(tagExpression) {
        if ((0, value_checker_1.doesHaveValue)(tagExpression) && tagExpression !== '') {
            this.tagExpressionNode = (0, tag_expressions_1.default)(tagExpression);
        }
    }
    matchesAllTagExpressions(pickle) {
        if ((0, value_checker_1.doesNotHaveValue)(this.tagExpressionNode)) {
            return true;
        }
        return this.tagExpressionNode.evaluate(pickle.tags.map((x) => x.name));
    }
}
exports.PickleTagFilter = PickleTagFilter;
//# sourceMappingURL=pickle_filter.js.map