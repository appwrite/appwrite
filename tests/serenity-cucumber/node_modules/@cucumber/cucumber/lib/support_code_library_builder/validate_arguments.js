"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const optionsValidation = {
    expectedType: 'object or function',
    predicate({ options }) {
        return typeof options === 'object';
    },
};
const optionsTimeoutValidation = {
    identifier: '"options.timeout"',
    expectedType: 'integer',
    predicate({ options }) {
        return options.timeout == null || typeof options.timeout === 'number';
    },
};
const fnValidation = {
    expectedType: 'function',
    predicate({ code }) {
        return typeof code === 'function';
    },
};
const validations = {
    defineTestRunHook: [
        { identifier: 'first argument', ...optionsValidation },
        optionsTimeoutValidation,
        { identifier: 'second argument', ...fnValidation },
    ],
    defineTestCaseHook: [
        { identifier: 'first argument', ...optionsValidation },
        {
            identifier: '"options.tags"',
            expectedType: 'string',
            predicate({ options }) {
                return options.tags == null || typeof options.tags === 'string';
            },
        },
        optionsTimeoutValidation,
        { identifier: 'second argument', ...fnValidation },
    ],
    defineTestStepHook: [
        { identifier: 'first argument', ...optionsValidation },
        {
            identifier: '"options.tags"',
            expectedType: 'string',
            predicate({ options }) {
                return options.tags == null || typeof options.tags === 'string';
            },
        },
        optionsTimeoutValidation,
        { identifier: 'second argument', ...fnValidation },
    ],
    defineStep: [
        {
            identifier: 'first argument',
            expectedType: 'string or regular expression',
            predicate({ pattern }) {
                return pattern instanceof RegExp || typeof pattern === 'string';
            },
        },
        { identifier: 'second argument', ...optionsValidation },
        optionsTimeoutValidation,
        { identifier: 'third argument', ...fnValidation },
    ],
};
function validateArguments({ args, fnName, location, }) {
    validations[fnName].forEach(({ identifier, expectedType, predicate }) => {
        if (!predicate(args)) {
            throw new Error(`${location}: Invalid ${identifier}: should be a ${expectedType}`);
        }
    });
}
exports.default = validateArguments;
//# sourceMappingURL=validate_arguments.js.map