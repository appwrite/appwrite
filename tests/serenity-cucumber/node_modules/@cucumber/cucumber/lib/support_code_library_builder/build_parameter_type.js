"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.buildParameterType = void 0;
const cucumber_expressions_1 = require("@cucumber/cucumber-expressions");
function buildParameterType({ name, regexp, transformer, useForSnippets, preferForRegexpMatch, }) {
    if (typeof useForSnippets !== 'boolean')
        useForSnippets = true;
    if (typeof preferForRegexpMatch !== 'boolean')
        preferForRegexpMatch = false;
    return new cucumber_expressions_1.ParameterType(name, regexp, null, transformer, useForSnippets, preferForRegexpMatch);
}
exports.buildParameterType = buildParameterType;
//# sourceMappingURL=build_parameter_type.js.map