"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.assembleTestCases = void 0;
const value_checker_1 = require("../value_checker");
async function assembleTestCases({ eventBroadcaster, newId, pickles, supportCodeLibrary, }) {
    const result = {};
    for (const pickle of pickles) {
        const { id: pickleId } = pickle;
        const testCaseId = newId();
        const fromBeforeHooks = makeBeforeHookSteps({
            supportCodeLibrary,
            pickle,
            newId,
        });
        const fromStepDefinitions = makeSteps({
            pickle,
            supportCodeLibrary,
            newId,
        });
        const fromAfterHooks = makeAfterHookSteps({
            supportCodeLibrary,
            pickle,
            newId,
        });
        const testCase = {
            pickleId,
            id: testCaseId,
            testSteps: [
                ...fromBeforeHooks,
                ...fromStepDefinitions,
                ...fromAfterHooks,
            ],
        };
        eventBroadcaster.emit('envelope', { testCase });
        result[pickleId] = testCase;
    }
    return result;
}
exports.assembleTestCases = assembleTestCases;
function makeAfterHookSteps({ supportCodeLibrary, pickle, newId, }) {
    return supportCodeLibrary.afterTestCaseHookDefinitions
        .slice(0)
        .reverse()
        .filter((hookDefinition) => hookDefinition.appliesToTestCase(pickle))
        .map((hookDefinition) => ({
        id: newId(),
        hookId: hookDefinition.id,
    }));
}
function makeBeforeHookSteps({ supportCodeLibrary, pickle, newId, }) {
    return supportCodeLibrary.beforeTestCaseHookDefinitions
        .filter((hookDefinition) => hookDefinition.appliesToTestCase(pickle))
        .map((hookDefinition) => ({
        id: newId(),
        hookId: hookDefinition.id,
    }));
}
function makeSteps({ pickle, supportCodeLibrary, newId, }) {
    return pickle.steps.map((pickleStep) => {
        const stepDefinitions = supportCodeLibrary.stepDefinitions.filter((stepDefinition) => stepDefinition.matchesStepName(pickleStep.text));
        return {
            id: newId(),
            pickleStepId: pickleStep.id,
            stepDefinitionIds: stepDefinitions.map((stepDefinition) => stepDefinition.id),
            stepMatchArgumentsLists: stepDefinitions.map((stepDefinition) => {
                const result = stepDefinition.expression.match(pickleStep.text);
                return {
                    stepMatchArguments: result.map((arg) => {
                        return {
                            group: mapArgumentGroup(arg.group),
                            parameterTypeName: arg.parameterType.name,
                        };
                    }),
                };
            }),
        };
    });
}
function mapArgumentGroup(group) {
    return {
        start: group.start,
        value: group.value,
        children: (0, value_checker_1.doesHaveValue)(group.children)
            ? group.children.map((child) => mapArgumentGroup(child))
            : undefined,
    };
}
//# sourceMappingURL=assemble_test_cases.js.map