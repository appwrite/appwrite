"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.atMostOnePicklePerTag = void 0;
function hasTag(pickle, tagName) {
    return pickle.tags.some((t) => t.name == tagName);
}
function atMostOnePicklePerTag(tagNames) {
    return (inQuestion, inProgress) => {
        return tagNames.every((tagName) => {
            return (!hasTag(inQuestion, tagName) ||
                inProgress.every((p) => !hasTag(p, tagName)));
        });
    };
}
exports.atMostOnePicklePerTag = atMostOnePicklePerTag;
//# sourceMappingURL=parallel_can_assign_helpers.js.map