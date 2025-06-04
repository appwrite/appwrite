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
const Parser_1 = __importDefault(require("./Parser"));
const GherkinClassicTokenMatcher_1 = __importDefault(require("./GherkinClassicTokenMatcher"));
const messages = __importStar(require("@cucumber/messages"));
const compile_1 = __importDefault(require("./pickles/compile"));
const AstBuilder_1 = __importDefault(require("./AstBuilder"));
const makeSourceEnvelope_1 = __importDefault(require("./makeSourceEnvelope"));
const GherkinInMarkdownTokenMatcher_1 = __importDefault(require("./GherkinInMarkdownTokenMatcher"));
function generateMessages(data, uri, mediaType, options) {
    let tokenMatcher;
    switch (mediaType) {
        case messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN:
            tokenMatcher = new GherkinClassicTokenMatcher_1.default(options.defaultDialect);
            break;
        case messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_MARKDOWN:
            tokenMatcher = new GherkinInMarkdownTokenMatcher_1.default(options.defaultDialect);
            break;
        default:
            throw new Error(`Unsupported media type: ${mediaType}`);
    }
    const result = [];
    try {
        if (options.includeSource) {
            result.push((0, makeSourceEnvelope_1.default)(data, uri));
        }
        if (!options.includeGherkinDocument && !options.includePickles) {
            return result;
        }
        const parser = new Parser_1.default(new AstBuilder_1.default(options.newId), tokenMatcher);
        parser.stopAtFirstError = false;
        const gherkinDocument = parser.parse(data);
        if (options.includeGherkinDocument) {
            result.push({
                gherkinDocument: { ...gherkinDocument, uri },
            });
        }
        if (options.includePickles) {
            const pickles = (0, compile_1.default)(gherkinDocument, uri, options.newId);
            for (const pickle of pickles) {
                result.push({
                    pickle,
                });
            }
        }
    }
    catch (err) {
        const errors = err.errors || [err];
        for (const error of errors) {
            if (!error.location) {
                // It wasn't a parser error - throw it (this is unexpected)
                throw error;
            }
            result.push({
                parseError: {
                    source: {
                        uri,
                        location: {
                            line: error.location.line,
                            column: error.location.column,
                        },
                    },
                    message: error.message,
                },
            });
        }
    }
    return result;
}
exports.default = generateMessages;
//# sourceMappingURL=generateMessages.js.map