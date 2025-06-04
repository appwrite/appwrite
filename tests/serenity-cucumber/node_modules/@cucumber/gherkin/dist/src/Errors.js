"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.NoSuchLanguageException = exports.AstBuilderException = exports.CompositeParserException = exports.ParserException = exports.GherkinException = void 0;
class GherkinException extends Error {
    constructor(message) {
        super(message);
        const actualProto = new.target.prototype;
        // https://stackoverflow.com/questions/41102060/typescript-extending-error-class
        if (Object.setPrototypeOf) {
            Object.setPrototypeOf(this, actualProto);
        }
        else {
            // @ts-ignore
            this.__proto__ = actualProto;
        }
    }
    static _create(message, location) {
        const column = location != null ? location.column || 0 : -1;
        const line = location != null ? location.line || 0 : -1;
        const m = `(${line}:${column}): ${message}`;
        const err = new this(m);
        err.location = location;
        return err;
    }
}
exports.GherkinException = GherkinException;
class ParserException extends GherkinException {
    static create(message, line, column) {
        const err = new this(`(${line}:${column}): ${message}`);
        err.location = { line, column };
        return err;
    }
}
exports.ParserException = ParserException;
class CompositeParserException extends GherkinException {
    static create(errors) {
        const message = 'Parser errors:\n' + errors.map((e) => e.message).join('\n');
        const err = new this(message);
        err.errors = errors;
        return err;
    }
}
exports.CompositeParserException = CompositeParserException;
class AstBuilderException extends GherkinException {
    static create(message, location) {
        return this._create(message, location);
    }
}
exports.AstBuilderException = AstBuilderException;
class NoSuchLanguageException extends GherkinException {
    static create(language, location) {
        const message = 'Language not supported: ' + language;
        return this._create(message, location);
    }
}
exports.NoSuchLanguageException = NoSuchLanguageException;
//# sourceMappingURL=Errors.js.map