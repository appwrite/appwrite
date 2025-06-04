"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.fromFile = void 0;
const string_argv_1 = __importDefault(require("string-argv"));
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const yaml_1 = __importDefault(require("yaml"));
const util_1 = require("util");
const url_1 = require("url");
const merge_configurations_1 = require("./merge_configurations");
const argv_parser_1 = __importDefault(require("./argv_parser"));
const check_schema_1 = require("./check_schema");
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { importer } = require('../importer');
async function fromFile(logger, cwd, file, profiles = []) {
    const definitions = await loadFile(cwd, file);
    if (!definitions.default) {
        logger.debug('No default profile defined in configuration file');
        definitions.default = {};
    }
    if (profiles.length < 1) {
        logger.debug('No profiles specified; using default profile');
        profiles = ['default'];
    }
    const definedKeys = Object.keys(definitions);
    profiles.forEach((profileKey) => {
        if (!definedKeys.includes(profileKey)) {
            throw new Error(`Requested profile "${profileKey}" doesn't exist`);
        }
    });
    return (0, merge_configurations_1.mergeConfigurations)({}, ...profiles.map((profileKey) => extractConfiguration(logger, profileKey, definitions[profileKey])));
}
exports.fromFile = fromFile;
async function loadFile(cwd, file) {
    const filePath = path_1.default.join(cwd, file);
    const extension = path_1.default.extname(filePath);
    let definitions;
    switch (extension) {
        case '.json':
            definitions = JSON.parse(await (0, util_1.promisify)(fs_1.default.readFile)(filePath, { encoding: 'utf-8' }));
            break;
        case '.yaml':
        case '.yml':
            definitions = yaml_1.default.parse(await (0, util_1.promisify)(fs_1.default.readFile)(filePath, { encoding: 'utf-8' }));
            break;
        default:
            try {
                // eslint-disable-next-line @typescript-eslint/no-var-requires
                definitions = require(filePath);
            }
            catch (error) {
                if (error.code === 'ERR_REQUIRE_ESM') {
                    definitions = await importer((0, url_1.pathToFileURL)(filePath));
                }
                else {
                    throw error;
                }
            }
    }
    if (typeof definitions !== 'object') {
        throw new Error(`Configuration file ${filePath} does not export an object`);
    }
    return definitions;
}
function extractConfiguration(logger, name, definition) {
    if (typeof definition === 'string') {
        logger.debug(`Profile "${name}" value is a string; parsing as argv`);
        const { configuration } = argv_parser_1.default.parse([
            'node',
            'cucumber-js',
            ...(0, string_argv_1.default)(definition),
        ]);
        return configuration;
    }
    try {
        return (0, check_schema_1.checkSchema)(definition);
    }
    catch (error) {
        throw new Error(`Requested profile "${name}" failed schema validation: ${error.errors.join(' ')}`);
    }
}
//# sourceMappingURL=from_file.js.map