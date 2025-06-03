"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.formatLocation = void 0;
const path_1 = __importDefault(require("path"));
const value_checker_1 = require("../../value_checker");
function formatLocation(obj, cwd) {
    let uri = obj.uri;
    if ((0, value_checker_1.doesHaveValue)(cwd)) {
        uri = path_1.default.relative(cwd, uri);
    }
    return `${uri}:${obj.line.toString()}`;
}
exports.formatLocation = formatLocation;
//# sourceMappingURL=location_helpers.js.map