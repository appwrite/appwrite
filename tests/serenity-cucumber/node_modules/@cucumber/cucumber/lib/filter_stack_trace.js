"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.filterStackTrace = exports.isFileNameInCucumber = void 0;
const path_1 = __importDefault(require("path"));
const value_checker_1 = require("./value_checker");
const projectRootPath = path_1.default.join(__dirname, '..');
const projectChildDirs = ['src', 'lib', 'node_modules'];
function isFileNameInCucumber(fileName) {
    return projectChildDirs.some((dir) => fileName.startsWith(path_1.default.join(projectRootPath, dir)));
}
exports.isFileNameInCucumber = isFileNameInCucumber;
function filterStackTrace(frames) {
    if (isErrorInCucumber(frames)) {
        return frames;
    }
    const index = frames.findIndex((x) => isFrameInCucumber(x));
    if (index === -1) {
        return frames;
    }
    return frames.slice(0, index);
}
exports.filterStackTrace = filterStackTrace;
function isErrorInCucumber(frames) {
    const filteredFrames = frames.filter((x) => !isFrameInNode(x));
    return filteredFrames.length > 0 && isFrameInCucumber(filteredFrames[0]);
}
function isFrameInCucumber(frame) {
    const fileName = (0, value_checker_1.valueOrDefault)(frame.getFileName(), '');
    return isFileNameInCucumber(fileName);
}
function isFrameInNode(frame) {
    const fileName = (0, value_checker_1.valueOrDefault)(frame.getFileName(), '');
    return !fileName.includes(path_1.default.sep);
}
//# sourceMappingURL=filter_stack_trace.js.map