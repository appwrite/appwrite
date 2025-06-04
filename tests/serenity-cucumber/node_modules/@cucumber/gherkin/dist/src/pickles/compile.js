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
Object.defineProperty(exports, "__esModule", { value: true });
const messages = __importStar(require("@cucumber/messages"));
const pickleStepTypeFromKeyword = {
    [messages.StepKeywordType.UNKNOWN]: messages.PickleStepType.UNKNOWN,
    [messages.StepKeywordType.CONTEXT]: messages.PickleStepType.CONTEXT,
    [messages.StepKeywordType.ACTION]: messages.PickleStepType.ACTION,
    [messages.StepKeywordType.OUTCOME]: messages.PickleStepType.OUTCOME,
    [messages.StepKeywordType.CONJUNCTION]: null
};
function compile(gherkinDocument, uri, newId) {
    const pickles = [];
    if (gherkinDocument.feature == null) {
        return pickles;
    }
    const feature = gherkinDocument.feature;
    const language = feature.language;
    const featureTags = feature.tags;
    let featureBackgroundSteps = [];
    feature.children.forEach((stepsContainer) => {
        if (stepsContainer.background) {
            featureBackgroundSteps = [].concat(stepsContainer.background.steps);
        }
        else if (stepsContainer.rule) {
            compileRule(featureTags, featureBackgroundSteps, stepsContainer.rule, language, pickles, uri, newId);
        }
        else if (stepsContainer.scenario.examples.length === 0) {
            compileScenario(featureTags, featureBackgroundSteps, stepsContainer.scenario, language, pickles, uri, newId);
        }
        else {
            compileScenarioOutline(featureTags, featureBackgroundSteps, stepsContainer.scenario, language, pickles, uri, newId);
        }
    });
    return pickles;
}
exports.default = compile;
function compileRule(featureTags, featureBackgroundSteps, rule, language, pickles, uri, newId) {
    let ruleBackgroundSteps = [].concat(featureBackgroundSteps);
    const tags = [].concat(featureTags).concat(rule.tags);
    rule.children.forEach((stepsContainer) => {
        if (stepsContainer.background) {
            ruleBackgroundSteps = ruleBackgroundSteps.concat(stepsContainer.background.steps);
        }
        else if (stepsContainer.scenario.examples.length === 0) {
            compileScenario(tags, ruleBackgroundSteps, stepsContainer.scenario, language, pickles, uri, newId);
        }
        else {
            compileScenarioOutline(tags, ruleBackgroundSteps, stepsContainer.scenario, language, pickles, uri, newId);
        }
    });
}
function compileScenario(inheritedTags, backgroundSteps, scenario, language, pickles, uri, newId) {
    let lastKeywordType = messages.StepKeywordType.UNKNOWN;
    const steps = [];
    if (scenario.steps.length !== 0) {
        backgroundSteps.forEach((step) => {
            lastKeywordType = (step.keywordType === messages.StepKeywordType.CONJUNCTION) ?
                lastKeywordType : step.keywordType;
            steps.push(pickleStep(step, [], null, newId, lastKeywordType));
        });
    }
    const tags = [].concat(inheritedTags).concat(scenario.tags);
    scenario.steps.forEach((step) => {
        lastKeywordType = (step.keywordType === messages.StepKeywordType.CONJUNCTION) ?
            lastKeywordType : step.keywordType;
        steps.push(pickleStep(step, [], null, newId, lastKeywordType));
    });
    const pickle = {
        id: newId(),
        uri,
        astNodeIds: [scenario.id],
        tags: pickleTags(tags),
        name: scenario.name,
        language,
        steps,
    };
    pickles.push(pickle);
}
function compileScenarioOutline(inheritedTags, backgroundSteps, scenario, language, pickles, uri, newId) {
    scenario.examples
        .filter((e) => e.tableHeader)
        .forEach((examples) => {
        const variableCells = examples.tableHeader.cells;
        examples.tableBody.forEach((valuesRow) => {
            let lastKeywordType = messages.StepKeywordType.UNKNOWN;
            const steps = [];
            if (scenario.steps.length !== 0) {
                backgroundSteps.forEach((step) => {
                    lastKeywordType = (step.keywordType === messages.StepKeywordType.CONJUNCTION) ?
                        lastKeywordType : step.keywordType;
                    steps.push(pickleStep(step, [], null, newId, lastKeywordType));
                });
            }
            scenario.steps.forEach((scenarioOutlineStep) => {
                lastKeywordType = (scenarioOutlineStep.keywordType === messages.StepKeywordType.CONJUNCTION) ?
                    lastKeywordType : scenarioOutlineStep.keywordType;
                const step = pickleStep(scenarioOutlineStep, variableCells, valuesRow, newId, lastKeywordType);
                steps.push(step);
            });
            const id = newId();
            const tags = pickleTags([].concat(inheritedTags).concat(scenario.tags).concat(examples.tags));
            pickles.push({
                id,
                uri,
                astNodeIds: [scenario.id, valuesRow.id],
                name: interpolate(scenario.name, variableCells, valuesRow.cells),
                language,
                steps,
                tags,
            });
        });
    });
}
function createPickleArguments(step, variableCells, valueCells) {
    if (step.dataTable) {
        const argument = step.dataTable;
        const table = {
            rows: argument.rows.map((row) => {
                return {
                    cells: row.cells.map((cell) => {
                        return {
                            value: interpolate(cell.value, variableCells, valueCells),
                        };
                    }),
                };
            }),
        };
        return { dataTable: table };
    }
    else if (step.docString) {
        const argument = step.docString;
        const docString = {
            content: interpolate(argument.content, variableCells, valueCells),
        };
        if (argument.mediaType) {
            docString.mediaType = interpolate(argument.mediaType, variableCells, valueCells);
        }
        return { docString };
    }
}
function interpolate(name, variableCells, valueCells) {
    variableCells.forEach((variableCell, n) => {
        const valueCell = valueCells[n];
        const valuePattern = '<' + variableCell.value + '>';
        const escapedPattern = valuePattern.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&');
        const regexp = new RegExp(escapedPattern, 'g');
        // JS Specific - dollar sign needs to be escaped with another dollar sign
        // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace#Specifying_a_string_as_a_parameter
        const replacement = valueCell.value.replace(new RegExp('\\$', 'g'), '$$$$');
        name = name.replace(regexp, replacement);
    });
    return name;
}
function pickleStep(step, variableCells, valuesRow, newId, keywordType) {
    const astNodeIds = [step.id];
    if (valuesRow) {
        astNodeIds.push(valuesRow.id);
    }
    const valueCells = valuesRow ? valuesRow.cells : [];
    return {
        id: newId(),
        text: interpolate(step.text, variableCells, valueCells),
        type: pickleStepTypeFromKeyword[keywordType],
        argument: createPickleArguments(step, variableCells, valueCells),
        astNodeIds: astNodeIds,
    };
}
function pickleTags(tags) {
    return tags.map(pickleTag);
}
function pickleTag(tag) {
    return {
        name: tag.name,
        astNodeId: tag.id,
    };
}
//# sourceMappingURL=compile.js.map