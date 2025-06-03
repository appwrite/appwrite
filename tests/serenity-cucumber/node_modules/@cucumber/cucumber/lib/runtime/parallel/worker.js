"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const messages_1 = require("@cucumber/messages");
const events_1 = require("events");
const url_1 = require("url");
const support_code_library_builder_1 = __importDefault(require("../../support_code_library_builder"));
const value_checker_1 = require("../../value_checker");
const run_test_run_hooks_1 = require("../run_test_run_hooks");
const stopwatch_1 = require("../stopwatch");
const test_case_runner_1 = __importDefault(require("../test_case_runner"));
const try_require_1 = __importDefault(require("../../try_require"));
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { importer } = require('../../importer');
const { uuid } = messages_1.IdGenerator;
class Worker {
    constructor({ cwd, exit, id, sendMessage, }) {
        this.id = id;
        this.newId = uuid();
        this.cwd = cwd;
        this.exit = exit;
        this.sendMessage = sendMessage;
        this.eventBroadcaster = new events_1.EventEmitter();
        this.eventBroadcaster.on('envelope', (envelope) => {
            // assign `workerId` property only for the `testCaseStarted` message
            if (envelope.testCaseStarted) {
                envelope.testCaseStarted.workerId = this.id;
            }
            this.sendMessage({ jsonEnvelope: JSON.stringify(envelope) });
        });
    }
    async initialize({ filterStacktraces, requireModules, requirePaths, importPaths, supportCodeIds, options, }) {
        support_code_library_builder_1.default.reset(this.cwd, this.newId, {
            requireModules,
            requirePaths,
            importPaths,
        });
        requireModules.map((module) => (0, try_require_1.default)(module));
        requirePaths.map((module) => (0, try_require_1.default)(module));
        for (const path of importPaths) {
            await importer((0, url_1.pathToFileURL)(path));
        }
        this.supportCodeLibrary = support_code_library_builder_1.default.finalize(supportCodeIds);
        this.worldParameters = options.worldParameters;
        this.filterStacktraces = filterStacktraces;
        this.runTestRunHooks = (0, run_test_run_hooks_1.makeRunTestRunHooks)(options.dryRun, this.supportCodeLibrary.defaultTimeout, (name, location) => `${name} hook errored on worker ${this.id}, process exiting: ${location}`);
        await this.runTestRunHooks(this.supportCodeLibrary.beforeTestRunHookDefinitions, 'a BeforeAll');
        this.sendMessage({ ready: true });
    }
    async finalize() {
        await this.runTestRunHooks(this.supportCodeLibrary.afterTestRunHookDefinitions, 'an AfterAll');
        this.exit(0);
    }
    async receiveMessage(message) {
        if ((0, value_checker_1.doesHaveValue)(message.initialize)) {
            await this.initialize(message.initialize);
        }
        else if (message.finalize) {
            await this.finalize();
        }
        else if ((0, value_checker_1.doesHaveValue)(message.run)) {
            await this.runTestCase(message.run);
        }
    }
    async runTestCase({ gherkinDocument, pickle, testCase, elapsed, retries, skip, }) {
        const stopwatch = (0, stopwatch_1.create)(elapsed);
        const testCaseRunner = new test_case_runner_1.default({
            eventBroadcaster: this.eventBroadcaster,
            stopwatch,
            gherkinDocument,
            newId: this.newId,
            pickle,
            testCase,
            retries,
            skip,
            filterStackTraces: this.filterStacktraces,
            supportCodeLibrary: this.supportCodeLibrary,
            worldParameters: this.worldParameters,
        });
        await testCaseRunner.run();
        this.sendMessage({ ready: true });
    }
}
exports.default = Worker;
//# sourceMappingURL=worker.js.map