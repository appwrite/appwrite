"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const get_color_fns_1 = __importDefault(require("./get_color_fns"));
const javascript_snippet_syntax_1 = __importDefault(require("./step_definition_snippet_builder/javascript_snippet_syntax"));
const path_1 = __importDefault(require("path"));
const step_definition_snippet_builder_1 = __importDefault(require("./step_definition_snippet_builder"));
const value_checker_1 = require("../value_checker");
const snippet_syntax_1 = require("./step_definition_snippet_builder/snippet_syntax");
const url_1 = require("url");
const formatters_1 = __importDefault(require("./helpers/formatters"));
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { importer } = require('../importer');
const FormatterBuilder = {
    async build(type, options) {
        const FormatterConstructor = await FormatterBuilder.getConstructorByType(type, options.cwd);
        const colorFns = (0, get_color_fns_1.default)(options.stream, options.env, options.parsedArgvOptions.colorsEnabled);
        const snippetBuilder = await FormatterBuilder.getStepDefinitionSnippetBuilder({
            cwd: options.cwd,
            snippetInterface: options.parsedArgvOptions.snippetInterface,
            snippetSyntax: options.parsedArgvOptions.snippetSyntax,
            supportCodeLibrary: options.supportCodeLibrary,
        });
        return new FormatterConstructor({
            colorFns,
            snippetBuilder,
            ...options,
        });
    },
    async getConstructorByType(type, cwd) {
        const formatters = formatters_1.default.getFormatters();
        return formatters[type]
            ? formatters[type]
            : await FormatterBuilder.loadCustomClass('formatter', type, cwd);
    },
    async getStepDefinitionSnippetBuilder({ cwd, snippetInterface, snippetSyntax, supportCodeLibrary, }) {
        if ((0, value_checker_1.doesNotHaveValue)(snippetInterface)) {
            snippetInterface = snippet_syntax_1.SnippetInterface.Synchronous;
        }
        let Syntax = javascript_snippet_syntax_1.default;
        if ((0, value_checker_1.doesHaveValue)(snippetSyntax)) {
            Syntax = await FormatterBuilder.loadCustomClass('syntax', snippetSyntax, cwd);
        }
        return new step_definition_snippet_builder_1.default({
            snippetSyntax: new Syntax(snippetInterface),
            parameterTypeRegistry: supportCodeLibrary.parameterTypeRegistry,
        });
    },
    async loadCustomClass(type, descriptor, cwd) {
        let normalized = descriptor;
        if (descriptor.startsWith('.')) {
            normalized = (0, url_1.pathToFileURL)(path_1.default.resolve(cwd, descriptor));
        }
        else if (descriptor.startsWith('file://')) {
            normalized = new URL(descriptor);
        }
        let CustomClass = await FormatterBuilder.loadFile(normalized);
        CustomClass = FormatterBuilder.resolveConstructor(CustomClass);
        if ((0, value_checker_1.doesHaveValue)(CustomClass)) {
            return CustomClass;
        }
        else {
            throw new Error(`Custom ${type} (${descriptor}) does not export a function/class`);
        }
    },
    async loadFile(urlOrName) {
        let result;
        try {
            // eslint-disable-next-line @typescript-eslint/no-var-requires
            result = require(typeof urlOrName === 'string'
                ? urlOrName
                : (0, url_1.fileURLToPath)(urlOrName));
        }
        catch (error) {
            if (error.code === 'ERR_REQUIRE_ESM') {
                result = await importer(urlOrName);
            }
            else {
                throw error;
            }
        }
        return result;
    },
    resolveConstructor(ImportedCode) {
        if ((0, value_checker_1.doesNotHaveValue)(ImportedCode)) {
            return null;
        }
        if (typeof ImportedCode === 'function') {
            return ImportedCode;
        }
        else if (typeof ImportedCode === 'object' &&
            typeof ImportedCode.default === 'function') {
            return ImportedCode.default;
        }
        return null;
    },
};
exports.default = FormatterBuilder;
//# sourceMappingURL=builder.js.map