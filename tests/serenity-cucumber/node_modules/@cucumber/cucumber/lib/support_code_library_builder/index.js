"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.SupportCodeLibraryBuilder = void 0;
const build_parameter_type_1 = require("./build_parameter_type");
const get_definition_line_and_uri_1 = require("./get_definition_line_and_uri");
const test_case_hook_definition_1 = __importDefault(require("../models/test_case_hook_definition"));
const test_step_hook_definition_1 = __importDefault(require("../models/test_step_hook_definition"));
const test_run_hook_definition_1 = __importDefault(require("../models/test_run_hook_definition"));
const step_definition_1 = __importDefault(require("../models/step_definition"));
const helpers_1 = require("../formatter/helpers");
const validate_arguments_1 = __importDefault(require("./validate_arguments"));
const util_arity_1 = __importDefault(require("util-arity"));
const cucumber_expressions_1 = require("@cucumber/cucumber-expressions");
const value_checker_1 = require("../value_checker");
const world_1 = __importDefault(require("./world"));
const sourced_parameter_type_registry_1 = require("./sourced_parameter_type_registry");
class SupportCodeLibraryBuilder {
    constructor() {
        const methods = {
            After: this.defineTestCaseHook(() => this.afterTestCaseHookDefinitionConfigs),
            AfterAll: this.defineTestRunHook(() => this.afterTestRunHookDefinitionConfigs),
            AfterStep: this.defineTestStepHook(() => this.afterTestStepHookDefinitionConfigs),
            Before: this.defineTestCaseHook(() => this.beforeTestCaseHookDefinitionConfigs),
            BeforeAll: this.defineTestRunHook(() => this.beforeTestRunHookDefinitionConfigs),
            BeforeStep: this.defineTestStepHook(() => this.beforeTestStepHookDefinitionConfigs),
            defineParameterType: this.defineParameterType.bind(this),
            defineStep: this.defineStep('Unknown', () => this.stepDefinitionConfigs),
            Given: this.defineStep('Given', () => this.stepDefinitionConfigs),
            setDefaultTimeout: (milliseconds) => {
                this.defaultTimeout = milliseconds;
            },
            setDefinitionFunctionWrapper: (fn) => {
                this.definitionFunctionWrapper = fn;
            },
            setWorldConstructor: (fn) => {
                this.World = fn;
            },
            setParallelCanAssign: (fn) => {
                this.parallelCanAssign = fn;
            },
            Then: this.defineStep('Then', () => this.stepDefinitionConfigs),
            When: this.defineStep('When', () => this.stepDefinitionConfigs),
        };
        const checkInstall = (method) => {
            if ((0, value_checker_1.doesNotHaveValue)(this.cwd)) {
                throw new Error(`
          You're calling functions (e.g. "${method}") on an instance of Cucumber that isn't running.
          This means you have an invalid installation, mostly likely due to:
          - Cucumber being installed globally
          - A project structure where your support code is depending on a different instance of Cucumber
          Either way, you'll need to address this in order for Cucumber to work.
          See https://github.com/cucumber/cucumber-js/blob/main/docs/installation.md#invalid-installations
          `);
            }
        };
        this.methods = new Proxy(methods, {
            get(target, method) {
                return (...args) => {
                    checkInstall(method);
                    // @ts-expect-error difficult to type this correctly
                    return target[method](...args);
                };
            },
        });
    }
    defineParameterType(options) {
        const parameterType = (0, build_parameter_type_1.buildParameterType)(options);
        const source = (0, get_definition_line_and_uri_1.getDefinitionLineAndUri)(this.cwd);
        this.parameterTypeRegistry.defineSourcedParameterType(parameterType, source);
    }
    defineStep(keyword, getCollection) {
        return (pattern, options, code) => {
            if (typeof options === 'function') {
                code = options;
                options = {};
            }
            const { line, uri } = (0, get_definition_line_and_uri_1.getDefinitionLineAndUri)(this.cwd);
            (0, validate_arguments_1.default)({
                args: { code, pattern, options },
                fnName: 'defineStep',
                location: (0, helpers_1.formatLocation)({ line, uri }),
            });
            getCollection().push({
                code,
                line,
                options,
                keyword,
                pattern,
                uri,
            });
        };
    }
    defineTestCaseHook(getCollection) {
        return (options, code) => {
            if (typeof options === 'string') {
                options = { tags: options };
            }
            else if (typeof options === 'function') {
                code = options;
                options = {};
            }
            const { line, uri } = (0, get_definition_line_and_uri_1.getDefinitionLineAndUri)(this.cwd);
            (0, validate_arguments_1.default)({
                args: { code, options },
                fnName: 'defineTestCaseHook',
                location: (0, helpers_1.formatLocation)({ line, uri }),
            });
            getCollection().push({
                code,
                line,
                options,
                uri,
            });
        };
    }
    defineTestStepHook(getCollection) {
        return (options, code) => {
            if (typeof options === 'string') {
                options = { tags: options };
            }
            else if (typeof options === 'function') {
                code = options;
                options = {};
            }
            const { line, uri } = (0, get_definition_line_and_uri_1.getDefinitionLineAndUri)(this.cwd);
            (0, validate_arguments_1.default)({
                args: { code, options },
                fnName: 'defineTestStepHook',
                location: (0, helpers_1.formatLocation)({ line, uri }),
            });
            getCollection().push({
                code,
                line,
                options,
                uri,
            });
        };
    }
    defineTestRunHook(getCollection) {
        return (options, code) => {
            if (typeof options === 'function') {
                code = options;
                options = {};
            }
            const { line, uri } = (0, get_definition_line_and_uri_1.getDefinitionLineAndUri)(this.cwd);
            (0, validate_arguments_1.default)({
                args: { code, options },
                fnName: 'defineTestRunHook',
                location: (0, helpers_1.formatLocation)({ line, uri }),
            });
            getCollection().push({
                code,
                line,
                options,
                uri,
            });
        };
    }
    wrapCode({ code, wrapperOptions, }) {
        if ((0, value_checker_1.doesHaveValue)(this.definitionFunctionWrapper)) {
            const codeLength = code.length;
            const wrappedCode = this.definitionFunctionWrapper(code, wrapperOptions);
            if (wrappedCode !== code) {
                return (0, util_arity_1.default)(codeLength, wrappedCode);
            }
            return wrappedCode;
        }
        return code;
    }
    buildTestCaseHookDefinitions(configs, canonicalIds) {
        return configs.map(({ code, line, options, uri }, index) => {
            const wrappedCode = this.wrapCode({
                code,
                wrapperOptions: options.wrapperOptions,
            });
            return new test_case_hook_definition_1.default({
                code: wrappedCode,
                id: canonicalIds ? canonicalIds[index] : this.newId(),
                line,
                options,
                unwrappedCode: code,
                uri,
            });
        });
    }
    buildTestStepHookDefinitions(configs) {
        return configs.map(({ code, line, options, uri }) => {
            const wrappedCode = this.wrapCode({
                code,
                wrapperOptions: options.wrapperOptions,
            });
            return new test_step_hook_definition_1.default({
                code: wrappedCode,
                id: this.newId(),
                line,
                options,
                unwrappedCode: code,
                uri,
            });
        });
    }
    buildTestRunHookDefinitions(configs) {
        return configs.map(({ code, line, options, uri }) => {
            const wrappedCode = this.wrapCode({
                code,
                wrapperOptions: options.wrapperOptions,
            });
            return new test_run_hook_definition_1.default({
                code: wrappedCode,
                id: this.newId(),
                line,
                options,
                unwrappedCode: code,
                uri,
            });
        });
    }
    buildStepDefinitions(canonicalIds) {
        const stepDefinitions = [];
        const undefinedParameterTypes = [];
        this.stepDefinitionConfigs.forEach(({ code, line, options, keyword, pattern, uri }, index) => {
            let expression;
            if (typeof pattern === 'string') {
                try {
                    expression = new cucumber_expressions_1.CucumberExpression(pattern, this.parameterTypeRegistry);
                }
                catch (e) {
                    if ((0, value_checker_1.doesHaveValue)(e.undefinedParameterTypeName)) {
                        undefinedParameterTypes.push({
                            name: e.undefinedParameterTypeName,
                            expression: pattern,
                        });
                        return;
                    }
                    throw e;
                }
            }
            else {
                expression = new cucumber_expressions_1.RegularExpression(pattern, this.parameterTypeRegistry);
            }
            const wrappedCode = this.wrapCode({
                code,
                wrapperOptions: options.wrapperOptions,
            });
            stepDefinitions.push(new step_definition_1.default({
                code: wrappedCode,
                expression,
                id: canonicalIds ? canonicalIds[index] : this.newId(),
                line,
                options,
                keyword,
                pattern,
                unwrappedCode: code,
                uri,
            }));
        });
        return { stepDefinitions, undefinedParameterTypes };
    }
    finalize(canonicalIds) {
        const stepDefinitionsResult = this.buildStepDefinitions(canonicalIds === null || canonicalIds === void 0 ? void 0 : canonicalIds.stepDefinitionIds);
        return {
            originalCoordinates: this.originalCoordinates,
            afterTestCaseHookDefinitions: this.buildTestCaseHookDefinitions(this.afterTestCaseHookDefinitionConfigs, canonicalIds === null || canonicalIds === void 0 ? void 0 : canonicalIds.afterTestCaseHookDefinitionIds),
            afterTestRunHookDefinitions: this.buildTestRunHookDefinitions(this.afterTestRunHookDefinitionConfigs),
            afterTestStepHookDefinitions: this.buildTestStepHookDefinitions(this.afterTestStepHookDefinitionConfigs),
            beforeTestCaseHookDefinitions: this.buildTestCaseHookDefinitions(this.beforeTestCaseHookDefinitionConfigs, canonicalIds === null || canonicalIds === void 0 ? void 0 : canonicalIds.beforeTestCaseHookDefinitionIds),
            beforeTestRunHookDefinitions: this.buildTestRunHookDefinitions(this.beforeTestRunHookDefinitionConfigs),
            beforeTestStepHookDefinitions: this.buildTestStepHookDefinitions(this.beforeTestStepHookDefinitionConfigs),
            defaultTimeout: this.defaultTimeout,
            parameterTypeRegistry: this.parameterTypeRegistry,
            undefinedParameterTypes: stepDefinitionsResult.undefinedParameterTypes,
            stepDefinitions: stepDefinitionsResult.stepDefinitions,
            World: this.World,
            parallelCanAssign: this.parallelCanAssign,
        };
    }
    reset(cwd, newId, originalCoordinates = {
        requireModules: [],
        requirePaths: [],
        importPaths: [],
    }) {
        this.cwd = cwd;
        this.newId = newId;
        this.originalCoordinates = originalCoordinates;
        this.afterTestCaseHookDefinitionConfigs = [];
        this.afterTestRunHookDefinitionConfigs = [];
        this.afterTestStepHookDefinitionConfigs = [];
        this.beforeTestCaseHookDefinitionConfigs = [];
        this.beforeTestRunHookDefinitionConfigs = [];
        this.beforeTestStepHookDefinitionConfigs = [];
        this.definitionFunctionWrapper = null;
        this.defaultTimeout = 5000;
        this.parameterTypeRegistry = new sourced_parameter_type_registry_1.SourcedParameterTypeRegistry();
        this.stepDefinitionConfigs = [];
        this.parallelCanAssign = () => true;
        this.World = world_1.default;
    }
}
exports.SupportCodeLibraryBuilder = SupportCodeLibraryBuilder;
exports.default = new SupportCodeLibraryBuilder();
//# sourceMappingURL=index.js.map