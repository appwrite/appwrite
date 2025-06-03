"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const child_process_1 = require("child_process");
const path_1 = __importDefault(require("path"));
const helpers_1 = require("../helpers");
const messages = __importStar(require("@cucumber/messages"));
const value_checker_1 = require("../../value_checker");
const stopwatch_1 = require("../stopwatch");
const assemble_test_cases_1 = require("../assemble_test_cases");
const runWorkerPath = path_1.default.resolve(__dirname, 'run_worker.js');
class Coordinator {
    constructor({ cwd, logger, eventBroadcaster, eventDataCollector, pickleIds, options, newId, supportCodeLibrary, requireModules, requirePaths, importPaths, numberOfWorkers, }) {
        this.cwd = cwd;
        this.logger = logger;
        this.eventBroadcaster = eventBroadcaster;
        this.eventDataCollector = eventDataCollector;
        this.stopwatch = (0, stopwatch_1.create)();
        this.options = options;
        this.newId = newId;
        this.supportCodeLibrary = supportCodeLibrary;
        this.requireModules = requireModules;
        this.requirePaths = requirePaths;
        this.importPaths = importPaths;
        this.pickleIds = Array.from(pickleIds);
        this.numberOfWorkers = numberOfWorkers;
        this.success = true;
        this.workers = {};
        this.inProgressPickles = {};
        this.idleInterventions = 0;
    }
    parseWorkerMessage(worker, message) {
        if (message.ready) {
            worker.state = 0 /* WorkerState.idle */;
            this.awakenWorkers(worker);
        }
        else if ((0, value_checker_1.doesHaveValue)(message.jsonEnvelope)) {
            const envelope = messages.parseEnvelope(message.jsonEnvelope);
            this.eventBroadcaster.emit('envelope', envelope);
            if ((0, value_checker_1.doesHaveValue)(envelope.testCaseFinished)) {
                delete this.inProgressPickles[worker.id];
                this.parseTestCaseResult(envelope.testCaseFinished);
            }
        }
        else {
            throw new Error(`Unexpected message from worker: ${JSON.stringify(message)}`);
        }
    }
    awakenWorkers(triggeringWorker) {
        Object.values(this.workers).forEach((worker) => {
            if (worker.state === 0 /* WorkerState.idle */) {
                this.giveWork(worker);
            }
            return worker.state !== 0 /* WorkerState.idle */;
        });
        if (Object.keys(this.inProgressPickles).length == 0 &&
            this.pickleIds.length > 0) {
            this.giveWork(triggeringWorker, true);
            this.idleInterventions++;
        }
    }
    startWorker(id, total) {
        const workerProcess = (0, child_process_1.fork)(runWorkerPath, [], {
            cwd: this.cwd,
            env: {
                ...process.env,
                CUCUMBER_PARALLEL: 'true',
                CUCUMBER_TOTAL_WORKERS: total.toString(),
                CUCUMBER_WORKER_ID: id,
            },
            stdio: ['inherit', 'inherit', 'inherit', 'ipc'],
        });
        const worker = { state: 3 /* WorkerState.new */, process: workerProcess, id };
        this.workers[id] = worker;
        worker.process.on('message', (message) => {
            this.parseWorkerMessage(worker, message);
        });
        worker.process.on('close', (exitCode) => {
            worker.state = 1 /* WorkerState.closed */;
            this.onWorkerProcessClose(exitCode);
        });
        const initializeCommand = {
            initialize: {
                filterStacktraces: this.options.filterStacktraces,
                requireModules: this.requireModules,
                requirePaths: this.requirePaths,
                importPaths: this.importPaths,
                supportCodeIds: {
                    stepDefinitionIds: this.supportCodeLibrary.stepDefinitions.map((s) => s.id),
                    beforeTestCaseHookDefinitionIds: this.supportCodeLibrary.beforeTestCaseHookDefinitions.map((h) => h.id),
                    afterTestCaseHookDefinitionIds: this.supportCodeLibrary.afterTestCaseHookDefinitions.map((h) => h.id),
                },
                options: this.options,
            },
        };
        worker.process.send(initializeCommand);
    }
    onWorkerProcessClose(exitCode) {
        const success = exitCode === 0;
        if (!success) {
            this.success = false;
        }
        if (Object.values(this.workers).every((x) => x.state === 1 /* WorkerState.closed */)) {
            const envelope = {
                testRunFinished: {
                    timestamp: this.stopwatch.timestamp(),
                    success,
                },
            };
            this.eventBroadcaster.emit('envelope', envelope);
            this.onFinish(this.success);
        }
    }
    parseTestCaseResult(testCaseFinished) {
        const { worstTestStepResult } = this.eventDataCollector.getTestCaseAttempt(testCaseFinished.testCaseStartedId);
        if (!testCaseFinished.willBeRetried &&
            (0, helpers_1.shouldCauseFailure)(worstTestStepResult.status, this.options)) {
            this.success = false;
        }
    }
    async start() {
        const envelope = {
            testRunStarted: {
                timestamp: this.stopwatch.timestamp(),
            },
        };
        this.eventBroadcaster.emit('envelope', envelope);
        this.stopwatch.start();
        this.assembledTestCases = await (0, assemble_test_cases_1.assembleTestCases)({
            eventBroadcaster: this.eventBroadcaster,
            newId: this.newId,
            pickles: this.pickleIds.map((pickleId) => this.eventDataCollector.getPickle(pickleId)),
            supportCodeLibrary: this.supportCodeLibrary,
        });
        return await new Promise((resolve) => {
            for (let i = 0; i < this.numberOfWorkers; i++) {
                this.startWorker(i.toString(), this.numberOfWorkers);
            }
            this.onFinish = (status) => {
                if (this.idleInterventions > 0) {
                    this.logger.warn(`WARNING: All workers went idle ${this.idleInterventions} time(s). Consider revising handler passed to setParallelCanAssign.`);
                }
                resolve(status);
            };
        });
    }
    nextPicklePlacement() {
        for (let index = 0; index < this.pickleIds.length; index++) {
            const placement = this.placementAt(index);
            if (this.supportCodeLibrary.parallelCanAssign(placement.pickle, Object.values(this.inProgressPickles))) {
                return placement;
            }
        }
        return null;
    }
    placementAt(index) {
        return {
            index,
            pickle: this.eventDataCollector.getPickle(this.pickleIds[index]),
        };
    }
    giveWork(worker, force = false) {
        if (this.pickleIds.length < 1) {
            const finalizeCommand = { finalize: true };
            worker.state = 2 /* WorkerState.running */;
            worker.process.send(finalizeCommand);
            return;
        }
        const picklePlacement = force
            ? this.placementAt(0)
            : this.nextPicklePlacement();
        if (picklePlacement === null) {
            return;
        }
        const { index: nextPickleIndex, pickle } = picklePlacement;
        this.pickleIds.splice(nextPickleIndex, 1);
        this.inProgressPickles[worker.id] = pickle;
        const testCase = this.assembledTestCases[pickle.id];
        const gherkinDocument = this.eventDataCollector.getGherkinDocument(pickle.uri);
        const retries = (0, helpers_1.retriesForPickle)(pickle, this.options);
        const skip = this.options.dryRun || (this.options.failFast && !this.success);
        const runCommand = {
            run: {
                retries,
                skip,
                elapsed: this.stopwatch.duration(),
                pickle,
                testCase,
                gherkinDocument,
            },
        };
        worker.state = 2 /* WorkerState.running */;
        worker.process.send(runCommand);
    }
}
exports.default = Coordinator;
//# sourceMappingURL=coordinator.js.map