"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.rejectAllFilters = void 0;
const defaultFilters = {
    acceptScenario: () => true,
    acceptStep: () => true,
    acceptBackground: () => true,
    acceptRule: () => true,
    acceptFeature: () => true,
};
exports.rejectAllFilters = {
    acceptScenario: () => false,
    acceptStep: () => false,
    acceptBackground: () => false,
    acceptRule: () => false,
    acceptFeature: () => false,
};
const defaultHandlers = {
    handleStep: () => null,
    handleScenario: () => null,
    handleBackground: () => null,
    handleRule: () => null,
    handleFeature: () => null,
};
class GherkinDocumentWalker {
    constructor(filters, handlers) {
        this.filters = Object.assign(Object.assign({}, defaultFilters), filters);
        this.handlers = Object.assign(Object.assign({}, defaultHandlers), handlers);
    }
    walkGherkinDocument(gherkinDocument) {
        if (!gherkinDocument.feature) {
            return null;
        }
        const feature = this.walkFeature(gherkinDocument.feature);
        if (!feature) {
            return null;
        }
        return {
            feature,
            comments: gherkinDocument.comments,
            uri: gherkinDocument.uri,
        };
    }
    walkFeature(feature) {
        const keptChildren = this.walkFeatureChildren(feature.children);
        this.handlers.handleFeature(feature);
        const backgroundKept = keptChildren.find((child) => child.background);
        if (this.filters.acceptFeature(feature) || backgroundKept) {
            return this.copyFeature(feature, feature.children.map((child) => {
                if (child.background) {
                    return {
                        background: this.copyBackground(child.background),
                    };
                }
                if (child.scenario) {
                    return {
                        scenario: this.copyScenario(child.scenario),
                    };
                }
                if (child.rule) {
                    return {
                        rule: this.copyRule(child.rule, child.rule.children),
                    };
                }
            }));
        }
        if (keptChildren.find((child) => child !== null)) {
            return this.copyFeature(feature, keptChildren);
        }
    }
    copyFeature(feature, children) {
        return {
            children: this.filterFeatureChildren(feature, children),
            location: feature.location,
            language: feature.language,
            keyword: feature.keyword,
            name: feature.name,
            description: feature.description,
            tags: this.copyTags(feature.tags),
        };
    }
    copyTags(tags) {
        return tags.map((tag) => ({
            name: tag.name,
            id: tag.id,
            location: tag.location,
        }));
    }
    filterFeatureChildren(feature, children) {
        const copyChildren = [];
        const scenariosKeptById = new Map(children.filter((child) => child.scenario).map((child) => [child.scenario.id, child]));
        const ruleKeptById = new Map(children.filter((child) => child.rule).map((child) => [child.rule.id, child]));
        for (const child of feature.children) {
            if (child.background) {
                copyChildren.push({
                    background: this.copyBackground(child.background),
                });
            }
            if (child.scenario) {
                const scenarioCopy = scenariosKeptById.get(child.scenario.id);
                if (scenarioCopy) {
                    copyChildren.push(scenarioCopy);
                }
            }
            if (child.rule) {
                const ruleCopy = ruleKeptById.get(child.rule.id);
                if (ruleCopy) {
                    copyChildren.push(ruleCopy);
                }
            }
        }
        return copyChildren;
    }
    walkFeatureChildren(children) {
        const childrenCopy = [];
        for (const child of children) {
            let backgroundCopy = null;
            let scenarioCopy = null;
            let ruleCopy = null;
            if (child.background) {
                backgroundCopy = this.walkBackground(child.background);
            }
            if (child.scenario) {
                scenarioCopy = this.walkScenario(child.scenario);
            }
            if (child.rule) {
                ruleCopy = this.walkRule(child.rule);
            }
            if (backgroundCopy || scenarioCopy || ruleCopy) {
                childrenCopy.push({
                    background: backgroundCopy,
                    scenario: scenarioCopy,
                    rule: ruleCopy,
                });
            }
        }
        return childrenCopy;
    }
    walkRule(rule) {
        const children = this.walkRuleChildren(rule.children);
        this.handlers.handleRule(rule);
        const backgroundKept = children.find((child) => child !== null && child.background !== null);
        const scenariosKept = children.filter((child) => child !== null && child.scenario !== null);
        if (this.filters.acceptRule(rule) || backgroundKept) {
            return this.copyRule(rule, rule.children);
        }
        if (scenariosKept.length > 0) {
            return this.copyRule(rule, scenariosKept);
        }
    }
    copyRule(rule, children) {
        return {
            id: rule.id,
            name: rule.name,
            description: rule.description,
            location: rule.location,
            keyword: rule.keyword,
            children: this.filterRuleChildren(rule.children, children),
            tags: this.copyTags(rule.tags),
        };
    }
    filterRuleChildren(children, childrenKept) {
        const childrenCopy = [];
        const scenariosKeptIds = childrenKept
            .filter((child) => child.scenario)
            .map((child) => child.scenario.id);
        for (const child of children) {
            if (child.background) {
                childrenCopy.push({
                    background: this.copyBackground(child.background),
                });
            }
            if (child.scenario && scenariosKeptIds.includes(child.scenario.id)) {
                childrenCopy.push({
                    scenario: this.copyScenario(child.scenario),
                });
            }
        }
        return childrenCopy;
    }
    walkRuleChildren(children) {
        const childrenCopy = [];
        for (const child of children) {
            if (child.background) {
                childrenCopy.push({
                    background: this.walkBackground(child.background),
                });
            }
            if (child.scenario) {
                childrenCopy.push({
                    scenario: this.walkScenario(child.scenario),
                });
            }
        }
        return childrenCopy;
    }
    walkBackground(background) {
        const steps = this.walkAllSteps(background.steps);
        this.handlers.handleBackground(background);
        if (this.filters.acceptBackground(background) || steps.find((step) => step !== null)) {
            return this.copyBackground(background);
        }
    }
    copyBackground(background) {
        return {
            id: background.id,
            name: background.name,
            location: background.location,
            keyword: background.keyword,
            steps: background.steps.map((step) => this.copyStep(step)),
            description: background.description,
        };
    }
    walkScenario(scenario) {
        const steps = this.walkAllSteps(scenario.steps);
        this.handlers.handleScenario(scenario);
        if (this.filters.acceptScenario(scenario) || steps.find((step) => step !== null)) {
            return this.copyScenario(scenario);
        }
    }
    copyScenario(scenario) {
        return {
            id: scenario.id,
            name: scenario.name,
            description: scenario.description,
            location: scenario.location,
            keyword: scenario.keyword,
            examples: scenario.examples,
            steps: scenario.steps.map((step) => this.copyStep(step)),
            tags: this.copyTags(scenario.tags),
        };
    }
    walkAllSteps(steps) {
        return steps.map((step) => this.walkStep(step));
    }
    walkStep(step) {
        this.handlers.handleStep(step);
        if (!this.filters.acceptStep(step)) {
            return null;
        }
        return this.copyStep(step);
    }
    copyStep(step) {
        return {
            id: step.id,
            keyword: step.keyword,
            keywordType: step.keywordType,
            location: step.location,
            text: step.text,
            dataTable: step.dataTable,
            docString: step.docString,
        };
    }
}
exports.default = GherkinDocumentWalker;
//# sourceMappingURL=GherkinDocumentWalker.js.map