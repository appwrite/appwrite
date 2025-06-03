"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const multimaps_1 = require("@teppeis/multimaps");
class Query {
    constructor() {
        this.sources = [];
        this.sourceByUri = new Map();
        this.gherkinDocuments = [];
        this.pickles = [];
        this.locationByAstNodeId = new Map();
        this.gherkinStepByAstNodeId = new Map();
        this.pickleIdsMapByUri = new Map();
        this.pickleIdsByAstNodeId = new Map();
        this.pickleStepIdsByAstNodeId = new Map();
        // AST nodes
        this.featureByUriLine = new Map();
        this.backgroundByUriLine = new Map();
        this.ruleByUriLine = new Map();
        this.scenarioByUriLine = new Map();
        this.examplesByUriLine = new Map();
        this.stepByUriLine = new Map();
    }
    /**
     * Gets the location (line and column) of an AST node.
     * @param astNodeId
     */
    getLocation(astNodeId) {
        return this.locationByAstNodeId.get(astNodeId);
    }
    getSources() {
        return this.sources;
    }
    getGherkinDocuments() {
        return this.gherkinDocuments;
    }
    getPickles() {
        return this.pickles;
    }
    getSource(uri) {
        return this.sourceByUri.get(uri);
    }
    getFeature(uri, line) {
        return getAstNode(this.featureByUriLine, uri, line);
    }
    getBackground(uri, line) {
        return getAstNode(this.backgroundByUriLine, uri, line);
    }
    getRule(uri, line) {
        return getAstNode(this.ruleByUriLine, uri, line);
    }
    getScenario(uri, line) {
        return getAstNode(this.scenarioByUriLine, uri, line);
    }
    getExamples(uri, line) {
        return getAstNode(this.examplesByUriLine, uri, line);
    }
    getStep(uri, line) {
        return getAstNode(this.stepByUriLine, uri, line);
    }
    /**
     * Gets all the pickle IDs
     * @param uri - the URI of the document
     * @param astNodeId - optionally restrict results to a particular AST node
     */
    getPickleIds(uri, astNodeId) {
        const pickleIdsByAstNodeId = this.pickleIdsMapByUri.get(uri);
        if (!pickleIdsByAstNodeId) {
            throw new Error(`No pickleIds for uri=${uri}`);
        }
        return astNodeId === undefined
            ? Array.from(new Set(pickleIdsByAstNodeId.values()))
            : pickleIdsByAstNodeId.get(astNodeId);
    }
    getPickleStepIds(astNodeId) {
        return this.pickleStepIdsByAstNodeId.get(astNodeId) || [];
    }
    update(message) {
        if (message.source) {
            this.sources.push(message.source);
            this.sourceByUri.set(message.source.uri, message.source);
        }
        if (message.gherkinDocument) {
            this.gherkinDocuments.push(message.gherkinDocument);
            if (message.gherkinDocument.feature) {
                this.updateGherkinFeature(message.gherkinDocument.uri, message.gherkinDocument.feature);
            }
        }
        if (message.pickle) {
            const pickle = message.pickle;
            this.updatePickle(pickle);
        }
        return this;
    }
    updateGherkinFeature(uri, feature) {
        setAstNode(this.featureByUriLine, uri, feature);
        this.pickleIdsMapByUri.set(uri, new multimaps_1.ArrayMultimap());
        for (const featureChild of feature.children) {
            if (featureChild.background) {
                this.updateGherkinBackground(uri, featureChild.background);
            }
            if (featureChild.scenario) {
                this.updateGherkinScenario(uri, featureChild.scenario);
            }
            if (featureChild.rule) {
                this.updateGherkinRule(uri, featureChild.rule);
            }
        }
    }
    updateGherkinBackground(uri, background) {
        setAstNode(this.backgroundByUriLine, uri, background);
        for (const step of background.steps) {
            this.updateGherkinStep(uri, step);
        }
    }
    updateGherkinRule(uri, rule) {
        setAstNode(this.ruleByUriLine, uri, rule);
        for (const ruleChild of rule.children) {
            if (ruleChild.background) {
                this.updateGherkinBackground(uri, ruleChild.background);
            }
            if (ruleChild.scenario) {
                this.updateGherkinScenario(uri, ruleChild.scenario);
            }
        }
    }
    updateGherkinScenario(uri, scenario) {
        setAstNode(this.scenarioByUriLine, uri, scenario);
        this.locationByAstNodeId.set(scenario.id, scenario.location);
        for (const step of scenario.steps) {
            this.updateGherkinStep(uri, step);
        }
        for (const examples of scenario.examples) {
            this.updateGherkinExamples(uri, examples);
        }
    }
    updateGherkinExamples(uri, examples) {
        setAstNode(this.examplesByUriLine, uri, examples);
        for (const tableRow of examples.tableBody || []) {
            this.locationByAstNodeId.set(tableRow.id, tableRow.location);
        }
    }
    updateGherkinStep(uri, step) {
        setAstNode(this.stepByUriLine, uri, step);
        this.locationByAstNodeId.set(step.id, step.location);
        this.gherkinStepByAstNodeId.set(step.id, step);
    }
    updatePickle(pickle) {
        const pickleIdsByLineNumber = this.pickleIdsMapByUri.get(pickle.uri);
        for (const astNodeId of pickle.astNodeIds) {
            pickleIdsByLineNumber.put(astNodeId, pickle.id);
        }
        this.updatePickleSteps(pickle);
        this.pickles.push(pickle);
        for (const astNodeId of pickle.astNodeIds) {
            if (!this.pickleIdsByAstNodeId.has(astNodeId)) {
                this.pickleIdsByAstNodeId.set(astNodeId, []);
            }
            this.pickleIdsByAstNodeId.get(astNodeId).push(pickle.id);
        }
    }
    updatePickleSteps(pickle) {
        const pickleSteps = pickle.steps;
        for (const pickleStep of pickleSteps) {
            for (const astNodeId of pickleStep.astNodeIds) {
                if (!this.pickleStepIdsByAstNodeId.has(astNodeId)) {
                    this.pickleStepIdsByAstNodeId.set(astNodeId, []);
                }
                this.pickleStepIdsByAstNodeId.get(astNodeId).push(pickleStep.id);
            }
        }
    }
}
exports.default = Query;
function setAstNode(map, uri, astNode) {
    const line = astNode.location.line;
    const uriLine = [uri, line].join(':');
    map.set(uriLine, astNode);
}
function getAstNode(map, uri, line) {
    const uriLine = [uri, line].join(':');
    return map.get(uriLine);
}
//# sourceMappingURL=Query.js.map