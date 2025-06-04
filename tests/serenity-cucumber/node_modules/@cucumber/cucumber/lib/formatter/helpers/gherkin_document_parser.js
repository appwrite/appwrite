"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getGherkinScenarioLocationMap = exports.getGherkinExampleRuleMap = exports.getGherkinScenarioMap = exports.getGherkinStepMap = void 0;
const value_checker_1 = require("../../value_checker");
function getGherkinStepMap(gherkinDocument) {
    const result = {};
    gherkinDocument.feature.children
        .map(extractStepContainers)
        .flat()
        .forEach((x) => x.steps.forEach((step) => (result[step.id] = step)));
    return result;
}
exports.getGherkinStepMap = getGherkinStepMap;
function extractStepContainers(child) {
    if ((0, value_checker_1.doesHaveValue)(child.background)) {
        return [child.background];
    }
    else if ((0, value_checker_1.doesHaveValue)(child.rule)) {
        return child.rule.children.map((ruleChild) => (0, value_checker_1.doesHaveValue)(ruleChild.background)
            ? ruleChild.background
            : ruleChild.scenario);
    }
    return [child.scenario];
}
function getGherkinScenarioMap(gherkinDocument) {
    const result = {};
    gherkinDocument.feature.children
        .map((child) => {
        if ((0, value_checker_1.doesHaveValue)(child.rule)) {
            return child.rule.children;
        }
        return [child];
    })
        .flat()
        .forEach((x) => {
        if (x.scenario != null) {
            result[x.scenario.id] = x.scenario;
        }
    });
    return result;
}
exports.getGherkinScenarioMap = getGherkinScenarioMap;
function getGherkinExampleRuleMap(gherkinDocument) {
    const result = {};
    gherkinDocument.feature.children
        .filter((x) => x.rule != null)
        .forEach((x) => x.rule.children
        .filter((child) => (0, value_checker_1.doesHaveValue)(child.scenario))
        .forEach((child) => (result[child.scenario.id] = x.rule)));
    return result;
}
exports.getGherkinExampleRuleMap = getGherkinExampleRuleMap;
function getGherkinScenarioLocationMap(gherkinDocument) {
    const locationMap = {};
    const scenarioMap = getGherkinScenarioMap(gherkinDocument);
    Object.keys(scenarioMap).forEach((id) => {
        const scenario = scenarioMap[id];
        locationMap[id] = scenario.location;
        if ((0, value_checker_1.doesHaveValue)(scenario.examples)) {
            scenario.examples.forEach((x) => x.tableBody.forEach((tableRow) => (locationMap[tableRow.id] = tableRow.location)));
        }
    });
    return locationMap;
}
exports.getGherkinScenarioLocationMap = getGherkinScenarioLocationMap;
//# sourceMappingURL=gherkin_document_parser.js.map