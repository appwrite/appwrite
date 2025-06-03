"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.locateFile = void 0;
const fs_1 = __importDefault(require("mz/fs"));
const path_1 = __importDefault(require("path"));
const DEFAULT_FILENAMES = [
    'cucumber.js',
    'cucumber.cjs',
    'cucumber.mjs',
    'cucumber.json',
    'cucumber.yaml',
    'cucumber.yml',
];
function locateFile(cwd) {
    return DEFAULT_FILENAMES.find((filename) => fs_1.default.existsSync(path_1.default.join(cwd, filename)));
}
exports.locateFile = locateFile;
//# sourceMappingURL=locate_file.js.map