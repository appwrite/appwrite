"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getPickleLocation = exports.getPickleStepMap = exports.getStepKeyword = exports.getScenarioDescription = void 0;
const gherkin_document_parser_1 = require("./gherkin_document_parser");
function getScenarioDescription({ pickle, gherkinScenarioMap, }) {
    return pickle.astNodeIds
        .map((id) => gherkinScenarioMap[id])
        .filter((x) => x != null)[0].description;
}
exports.getScenarioDescription = getScenarioDescription;
function getStepKeyword({ pickleStep, gherkinStepMap, }) {
    return pickleStep.astNodeIds
        .map((id) => gherkinStepMap[id])
        .filter((x) => x != null)[0].keyword;
}
exports.getStepKeyword = getStepKeyword;
function getPickleStepMap(pickle) {
    const result = {};
    pickle.steps.forEach((pickleStep) => (result[pickleStep.id] = pickleStep));
    return result;
}
exports.getPickleStepMap = getPickleStepMap;
function getPickleLocation({ gherkinDocument, pickle, }) {
    const gherkinScenarioLocationMap = (0, gherkin_document_parser_1.getGherkinScenarioLocationMap)(gherkinDocument);
    return gherkinScenarioLocationMap[pickle.astNodeIds[pickle.astNodeIds.length - 1]];
}
exports.getPickleLocation = getPickleLocation;
//# sourceMappingURL=pickle_parser.js.map