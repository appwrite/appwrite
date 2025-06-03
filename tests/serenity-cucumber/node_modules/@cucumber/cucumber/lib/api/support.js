"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.getSupportCodeLibrary = void 0;
const support_code_library_builder_1 = __importDefault(require("../support_code_library_builder"));
const url_1 = require("url");
const try_require_1 = __importDefault(require("../try_require"));
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { importer } = require('../importer');
async function getSupportCodeLibrary({ cwd, newId, requireModules, requirePaths, importPaths, }) {
    support_code_library_builder_1.default.reset(cwd, newId, {
        requireModules,
        requirePaths,
        importPaths,
    });
    requireModules.map((module) => (0, try_require_1.default)(module));
    requirePaths.map((path) => (0, try_require_1.default)(path));
    for (const path of importPaths) {
        await importer((0, url_1.pathToFileURL)(path));
    }
    return support_code_library_builder_1.default.finalize();
}
exports.getSupportCodeLibrary = getSupportCodeLibrary;
//# sourceMappingURL=support.js.map