"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.parseEnvelope = parseEnvelope;
var messages_js_1 = require("./messages.js");
var class_transformer_1 = require("class-transformer");
/**
 * Parses JSON into an Envelope object. The difference from JSON.parse
 * is that the resulting objects will have default values (defined in the JSON Schema)
 * for properties that are absent from the JSON.
 */
function parseEnvelope(json) {
    var plain = JSON.parse(json);
    return (0, class_transformer_1.plainToClass)(messages_js_1.Envelope, plain);
}
//# sourceMappingURL=parseEnvelope.js.map