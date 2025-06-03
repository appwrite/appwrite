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
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var __asyncValues = (this && this.__asyncValues) || function (o) {
    if (!Symbol.asyncIterator) throw new TypeError("Symbol.asyncIterator is not defined.");
    var m = o[Symbol.asyncIterator], i;
    return m ? m.call(o) : (o = typeof __values === "function" ? __values(o) : o[Symbol.iterator](), i = {}, verb("next"), verb("throw"), verb("return"), i[Symbol.asyncIterator] = function () { return this; }, i);
    function verb(n) { i[n] = o[n] && function (v) { return new Promise(function (resolve, reject) { v = o[n](v), settle(resolve, reject, v.done, v.value); }); }; }
    function settle(resolve, reject, d, v) { Promise.resolve(v).then(function(v) { resolve({ value: v, done: d }); }, reject); }
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.formatCommand = void 0;
const fs_1 = __importStar(require("fs"));
const path_1 = __importDefault(require("path"));
const messages = __importStar(require("@cucumber/messages"));
const gherkin_1 = require("@cucumber/gherkin");
const pretty_1 = __importDefault(require("../pretty"));
const util_1 = require("util");
const unlink = (0, util_1.promisify)(fs_1.unlink);
function formatCommand(files, stdin, stdout, options) {
    return __awaiter(this, void 0, void 0, function* () {
        const fileFormats = files.map(file => {
            const toFile = syntaxPath(file, options.toSyntax);
            return {
                readableSyntax: syntaxFromPath(file, options.fromSyntax),
                writableSyntax: syntaxFromPath(toFile, options.toSyntax),
                readable: () => fs_1.default.createReadStream(file),
                writable: () => fs_1.default.createWriteStream(toFile),
                afterWrite: file !== toFile ? () => unlink(file) : () => Promise.resolve()
            };
        });
        if (stdin && stdout) {
            fileFormats.push({
                readableSyntax: options.fromSyntax || 'gherkin',
                writableSyntax: options.toSyntax || 'gherkin',
                readable: () => stdin,
                writable: () => stdout,
                afterWrite: () => Promise.resolve()
            });
        }
        for (const fileFormat of fileFormats) {
            yield convert(fileFormat, options.language);
        }
    });
}
exports.formatCommand = formatCommand;
function convert(fileFormat, language) {
    var _a;
    return __awaiter(this, void 0, void 0, function* () {
        const source = yield read(fileFormat.readable());
        const gherkinDocument = parse(source, fileFormat.readableSyntax, language);
        const output = (0, pretty_1.default)(gherkinDocument, fileFormat.writableSyntax);
        try {
            // Sanity check that what we generated is OK.
            parse(output, fileFormat.writableSyntax, (_a = gherkinDocument.feature) === null || _a === void 0 ? void 0 : _a.language);
        }
        catch (err) {
            err.message += `The generated output is not parseable. This is a bug.
Please report a bug at https://github.com/cucumber/common/issues/new

--- Generated ${fileFormat.writableSyntax} source ---
${output}
------
`;
            throw err;
        }
        const writable = fileFormat.writable();
        writable.write(output);
        writable.end();
        yield new Promise((resolve) => writable.once('finish', resolve));
        yield fileFormat.afterWrite();
    });
}
function parse(source, syntax, language) {
    if (!syntax)
        throw new Error('No syntax');
    const fromParser = new gherkin_1.Parser(new gherkin_1.AstBuilder(messages.IdGenerator.uuid()), syntax === 'gherkin'
        ? new gherkin_1.GherkinClassicTokenMatcher(language)
        : new gherkin_1.GherkinInMarkdownTokenMatcher(language));
    return fromParser.parse(source);
}
function read(readable) {
    var _a, readable_1, readable_1_1;
    var _b, e_1, _c, _d;
    return __awaiter(this, void 0, void 0, function* () {
        const chunks = [];
        try {
            for (_a = true, readable_1 = __asyncValues(readable); readable_1_1 = yield readable_1.next(), _b = readable_1_1.done, !_b;) {
                _d = readable_1_1.value;
                _a = false;
                try {
                    const chunk = _d;
                    chunks.push(chunk);
                }
                finally {
                    _a = true;
                }
            }
        }
        catch (e_1_1) { e_1 = { error: e_1_1 }; }
        finally {
            try {
                if (!_a && !_b && (_c = readable_1.return)) yield _c.call(readable_1);
            }
            finally { if (e_1) throw e_1.error; }
        }
        return Buffer.concat(chunks).toString('utf-8');
    });
}
function syntaxPath(file, syntax) {
    if (syntax === 'markdown') {
        if (syntaxFromPath(file) === 'markdown')
            return file;
        return file + '.md';
    }
    if (syntax === 'gherkin') {
        if (syntaxFromPath(file) === 'gherkin')
            return file;
        return file.replace(/\.feature\.md/, '.feature');
    }
    return file;
}
function syntaxFromPath(file, explicitSyntax) {
    let syntax;
    if (path_1.default.extname(file) === '.feature')
        syntax = 'gherkin';
    if (path_1.default.extname(file) === '.md')
        syntax = 'markdown';
    if (!syntax)
        throw new Error(`Cannot determine syntax from path ${file}`);
    if (explicitSyntax && explicitSyntax !== syntax)
        throw new Error(`Cannot treat ${file} as ${explicitSyntax}`);
    return syntax;
}
//# sourceMappingURL=formatCommand.js.map