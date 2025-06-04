"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.walkGherkinDocument = void 0;
/**
 * Walks a Gherkin Document, visiting each node depth first (in the order they appear in the source)
 *
 * @param gherkinDocument
 * @param initialValue the initial value of the traversal
 * @param handlers handlers for each node type, which may return a new value
 * @return result the final value
 */
function walkGherkinDocument(gherkinDocument, initialValue, handlers) {
    let acc = initialValue;
    const h = Object.assign(Object.assign({}, makeDefaultHandlers()), handlers);
    const feature = gherkinDocument.feature;
    if (!feature)
        return acc;
    acc = walkTags(feature.tags || [], acc);
    acc = h.feature(feature, acc);
    for (const child of feature.children) {
        if (child.background) {
            acc = walkStepContainer(child.background, acc);
        }
        else if (child.scenario) {
            acc = walkStepContainer(child.scenario, acc);
        }
        else if (child.rule) {
            acc = walkTags(child.rule.tags || [], acc);
            acc = h.rule(child.rule, acc);
            for (const ruleChild of child.rule.children) {
                if (ruleChild.background) {
                    acc = walkStepContainer(ruleChild.background, acc);
                }
                else if (ruleChild.scenario) {
                    acc = walkStepContainer(ruleChild.scenario, acc);
                }
            }
        }
    }
    return acc;
    function walkTags(tags, acc) {
        return tags.reduce((acc, tag) => h.tag(tag, acc), acc);
    }
    function walkSteps(steps, acc) {
        return steps.reduce((acc, step) => walkStep(step, acc), acc);
    }
    function walkStep(step, acc) {
        acc = h.step(step, acc);
        if (step.docString) {
            acc = h.docString(step.docString, acc);
        }
        if (step.dataTable) {
            acc = h.dataTable(step.dataTable, acc);
            acc = walkTableRows(step.dataTable.rows, acc);
        }
        return acc;
    }
    function walkTableRows(tableRows, acc) {
        return tableRows.reduce((acc, tableRow) => walkTableRow(tableRow, acc), acc);
    }
    function walkTableRow(tableRow, acc) {
        acc = h.tableRow(tableRow, acc);
        return tableRow.cells.reduce((acc, tableCell) => h.tableCell(tableCell, acc), acc);
    }
    function walkStepContainer(stepContainer, acc) {
        const scenario = 'tags' in stepContainer ? stepContainer : null;
        acc = walkTags((scenario === null || scenario === void 0 ? void 0 : scenario.tags) || [], acc);
        acc = scenario
            ? h.scenario(scenario, acc)
            : h.background(stepContainer, acc);
        acc = walkSteps(stepContainer.steps, acc);
        if (scenario) {
            for (const examples of scenario.examples || []) {
                acc = walkTags(examples.tags || [], acc);
                acc = h.examples(examples, acc);
                if (examples.tableHeader) {
                    acc = walkTableRow(examples.tableHeader, acc);
                    acc = walkTableRows(examples.tableBody || [], acc);
                }
            }
        }
        return acc;
    }
}
exports.walkGherkinDocument = walkGherkinDocument;
function makeDefaultHandlers() {
    const defaultHandlers = {
        feature(feature, acc) {
            return acc;
        },
        background(background, acc) {
            return acc;
        },
        rule(rule, acc) {
            return acc;
        },
        scenario(scenario, acc) {
            return acc;
        },
        step(step, acc) {
            return acc;
        },
        examples(examples, acc) {
            return acc;
        },
        tag(tag, acc) {
            return acc;
        },
        comment(comment, acc) {
            return acc;
        },
        dataTable(dataTable, acc) {
            return acc;
        },
        tableRow(tableRow, acc) {
            return acc;
        },
        tableCell(tableCell, acc) {
            return acc;
        },
        docString(docString, acc) {
            return acc;
        },
    };
    return defaultHandlers;
}
//# sourceMappingURL=walkGherkinDocument.js.map