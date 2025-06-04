"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
/* eslint-disable no-console */
/* This is one rare place where we're fine to use process/console directly,
 * but other code abstracts those to remain composable and testable. */
const _1 = __importDefault(require("./"));
const verror_1 = __importDefault(require("verror"));
const validate_node_engine_version_1 = require("./validate_node_engine_version");
function logErrorMessageAndExit(message) {
    console.error(message);
    process.exit(1);
}
async function run() {
    (0, validate_node_engine_version_1.validateNodeEngineVersion)(process.version, (error) => {
        console.error(error);
        process.exit(1);
    }, console.warn);
    const cli = new _1.default({
        argv: process.argv,
        cwd: process.cwd(),
        stdout: process.stdout,
        stderr: process.stderr,
        env: process.env,
    });
    let result;
    try {
        result = await cli.run();
    }
    catch (error) {
        logErrorMessageAndExit(verror_1.default.fullStack(error));
    }
    const exitCode = result.success ? 0 : 1;
    if (result.shouldExitImmediately) {
        process.exit(exitCode);
    }
    else {
        process.exitCode = exitCode;
    }
}
exports.default = run;
//# sourceMappingURL=run.js.map