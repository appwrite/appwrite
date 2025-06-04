"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.getFilteredPicklesAndErrors = void 0;
const gherkin_streams_1 = require("@cucumber/gherkin-streams");
const gherkin_utils_1 = require("@cucumber/gherkin-utils");
const pickle_filter_1 = __importDefault(require("../pickle_filter"));
const helpers_1 = require("../cli/helpers");
async function getFilteredPicklesAndErrors({ newId, cwd, logger, unexpandedFeaturePaths, featurePaths, coordinates, onEnvelope, }) {
    const gherkinQuery = new gherkin_utils_1.Query();
    const parseErrors = [];
    await gherkinFromPaths(featurePaths, {
        newId,
        relativeTo: cwd,
        defaultDialect: coordinates.defaultDialect,
    }, (envelope) => {
        gherkinQuery.update(envelope);
        if (envelope.parseError) {
            parseErrors.push(envelope.parseError);
        }
        onEnvelope === null || onEnvelope === void 0 ? void 0 : onEnvelope(envelope);
    });
    const pickleFilter = new pickle_filter_1.default({
        cwd,
        featurePaths: unexpandedFeaturePaths,
        names: coordinates.names,
        tagExpression: coordinates.tagExpression,
    });
    const filteredPickles = gherkinQuery
        .getPickles()
        .filter((pickle) => {
        const gherkinDocument = gherkinQuery
            .getGherkinDocuments()
            .find((doc) => doc.uri === pickle.uri);
        return pickleFilter.matches({ gherkinDocument, pickle });
    })
        .map((pickle) => {
        const gherkinDocument = gherkinQuery
            .getGherkinDocuments()
            .find((doc) => doc.uri === pickle.uri);
        const location = gherkinQuery.getLocation(pickle.astNodeIds[pickle.astNodeIds.length - 1]);
        return {
            gherkinDocument,
            location,
            pickle,
        };
    });
    (0, helpers_1.orderPickles)(filteredPickles, coordinates.order, logger);
    return {
        filteredPickles,
        parseErrors,
    };
}
exports.getFilteredPicklesAndErrors = getFilteredPicklesAndErrors;
async function gherkinFromPaths(paths, options, onEnvelope) {
    return new Promise((resolve, reject) => {
        const gherkinMessageStream = gherkin_streams_1.GherkinStreams.fromPaths(paths, options);
        gherkinMessageStream.on('data', onEnvelope);
        gherkinMessageStream.on('end', resolve);
        gherkinMessageStream.on('error', reject);
    });
}
//# sourceMappingURL=gherkin.js.map