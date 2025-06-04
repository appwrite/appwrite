"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.validateNodeEngineVersion = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const semver_1 = __importDefault(require("semver"));
const readActualPackageJSON = () => JSON.parse(fs_1.default
    .readFileSync(path_1.default.resolve(__dirname, '..', '..', 'package.json'))
    .toString());
function validateNodeEngineVersion(currentVersion, onError, onWarning, readPackageJSON = readActualPackageJSON) {
    const requiredVersions = readPackageJSON().engines.node;
    const testedVersions = readPackageJSON().enginesTested.node;
    if (!semver_1.default.satisfies(currentVersion, requiredVersions)) {
        onError(`Cucumber can only run on Node.js versions ${requiredVersions}. This Node.js version is ${currentVersion}`);
    }
    else if (!semver_1.default.satisfies(currentVersion, testedVersions)) {
        onWarning(`This Node.js version (${currentVersion}) has not been tested with this version of Cucumber; it should work normally, but please raise an issue if you see anything unexpected.`);
    }
}
exports.validateNodeEngineVersion = validateNodeEngineVersion;
//# sourceMappingURL=validate_node_engine_version.js.map