"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
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
Object.defineProperty(exports, "__esModule", { value: true });
exports.RecorderSupplement = void 0;
const fs = __importStar(require("fs"));
const codeGenerator_1 = require("./recorder/codeGenerator");
const utils_1 = require("./recorder/utils");
const page_1 = require("../page");
const frames_1 = require("../frames");
const browserContext_1 = require("../browserContext");
const java_1 = require("./recorder/java");
const javascript_1 = require("./recorder/javascript");
const csharp_1 = require("./recorder/csharp");
const python_1 = require("./recorder/python");
const recorderSource = __importStar(require("../../generated/recorderSource"));
const consoleApiSource = __importStar(require("../../generated/consoleApiSource"));
const recorderApp_1 = require("./recorder/recorderApp");
const instrumentation_1 = require("../instrumentation");
const utils_2 = require("../../utils/utils");
const recorderUtils_1 = require("./recorder/recorderUtils");
const debugger_1 = require("./debugger");
const symbol = Symbol('RecorderSupplement');
class RecorderSupplement {
    constructor(context, params) {
        this._pageAliases = new Map();
        this._lastPopupOrdinal = 0;
        this._lastDialogOrdinal = 0;
        this._lastDownloadOrdinal = 0;
        this._timers = new Set();
        this._highlightedSelector = '';
        this._recorderApp = null;
        this._currentCallsMetadata = new Map();
        this._userSources = new Map();
        this._allMetadatas = new Map();
        this._context = context;
        this._debugger = debugger_1.Debugger.lookup(context);
        context.instrumentation.addListener(this);
        this._params = params;
        this._mode = params.startRecording ? 'recording' : 'none';
        const language = params.language || context._options.sdkLanguage;
        const languages = new Set([
            new java_1.JavaLanguageGenerator(),
            new javascript_1.JavaScriptLanguageGenerator(false),
            new javascript_1.JavaScriptLanguageGenerator(true),
            new python_1.PythonLanguageGenerator(false),
            new python_1.PythonLanguageGenerator(true),
            new csharp_1.CSharpLanguageGenerator(),
        ]);
        const primaryLanguage = [...languages].find(l => l.id === language);
        if (!primaryLanguage)
            throw new Error(`\n===============================\nUnsupported language: '${language}'\n===============================\n`);
        languages.delete(primaryLanguage);
        const orderedLanguages = [primaryLanguage, ...languages];
        this._recorderSources = [];
        const generator = new codeGenerator_1.CodeGenerator(context._browser.options.name, !!params.startRecording, params.launchOptions || {}, params.contextOptions || {}, params.device, params.saveStorage);
        let text = '';
        generator.on('change', () => {
            var _a;
            this._recorderSources = [];
            for (const languageGenerator of orderedLanguages) {
                const source = {
                    file: languageGenerator.fileName,
                    text: generator.generateText(languageGenerator),
                    language: languageGenerator.highlighter,
                    highlight: []
                };
                source.revealLine = source.text.split('\n').length - 1;
                this._recorderSources.push(source);
                if (languageGenerator === orderedLanguages[0])
                    text = source.text;
            }
            this._pushAllSources();
            (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setFile(primaryLanguage.fileName);
        });
        if (params.outputFile) {
            context.on(browserContext_1.BrowserContext.Events.BeforeClose, () => {
                fs.writeFileSync(params.outputFile, text);
                text = '';
            });
            process.on('exit', () => {
                if (text)
                    fs.writeFileSync(params.outputFile, text);
            });
        }
        this._generator = generator;
    }
    static showInspector(context) {
        RecorderSupplement.show(context, {}).catch(() => { });
    }
    static show(context, params = {}) {
        let recorderPromise = context[symbol];
        if (!recorderPromise) {
            const recorder = new RecorderSupplement(context, params);
            recorderPromise = recorder.install().then(() => recorder);
            context[symbol] = recorderPromise;
        }
        return recorderPromise;
    }
    async install() {
        const recorderApp = await recorderApp_1.RecorderApp.open(this._context);
        this._recorderApp = recorderApp;
        recorderApp.once('close', () => {
            this._debugger.resume(false);
            this._recorderApp = null;
        });
        recorderApp.on('event', (data) => {
            if (data.event === 'setMode') {
                this._setMode(data.params.mode);
                this._refreshOverlay();
                return;
            }
            if (data.event === 'selectorUpdated') {
                this._highlightedSelector = data.params.selector;
                this._refreshOverlay();
                return;
            }
            if (data.event === 'step') {
                this._debugger.resume(true);
                return;
            }
            if (data.event === 'resume') {
                this._debugger.resume(false);
                return;
            }
            if (data.event === 'pause') {
                this._debugger.pauseOnNextStatement();
                return;
            }
            if (data.event === 'clear') {
                this._clearScript();
                return;
            }
        });
        await Promise.all([
            recorderApp.setMode(this._mode),
            recorderApp.setPaused(this._debugger.isPaused()),
            this._pushAllSources()
        ]);
        this._context.on(browserContext_1.BrowserContext.Events.Page, page => this._onPage(page));
        for (const page of this._context.pages())
            this._onPage(page);
        this._context.once(browserContext_1.BrowserContext.Events.Close, () => {
            for (const timer of this._timers)
                clearTimeout(timer);
            this._timers.clear();
            recorderApp.close().catch(() => { });
        });
        // Input actions that potentially lead to navigation are intercepted on the page and are
        // performed by the Playwright.
        await this._context.exposeBinding('_playwrightRecorderPerformAction', false, (source, action) => this._performAction(source.frame, action), 'utility');
        // Other non-essential actions are simply being recorded.
        await this._context.exposeBinding('_playwrightRecorderRecordAction', false, (source, action) => this._recordAction(source.frame, action), 'utility');
        await this._context.exposeBinding('_playwrightRecorderState', false, source => {
            let actionSelector = this._highlightedSelector;
            let actionPoint;
            for (const [metadata, sdkObject] of this._currentCallsMetadata) {
                if (source.page === sdkObject.attribution.page) {
                    actionPoint = metadata.point || actionPoint;
                    actionSelector = actionSelector || metadata.params.selector;
                }
            }
            const uiState = {
                mode: this._mode,
                actionPoint,
                actionSelector,
            };
            return uiState;
        }, 'utility');
        await this._context.exposeBinding('_playwrightRecorderSetSelector', false, async (_, selector) => {
            var _a, _b;
            this._setMode('none');
            await ((_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setSelector(selector, true));
            await ((_b = this._recorderApp) === null || _b === void 0 ? void 0 : _b.bringToFront());
        }, 'utility');
        await this._context.exposeBinding('_playwrightResume', false, () => {
            this._debugger.resume(false);
        }, 'main');
        await this._context.extendInjectedScript('utility', recorderSource.source, { isUnderTest: utils_2.isUnderTest() });
        await this._context.extendInjectedScript('main', consoleApiSource.source);
        if (this._debugger.isPaused())
            this._pausedStateChanged();
        this._debugger.on(debugger_1.Debugger.Events.PausedStateChanged, () => this._pausedStateChanged());
        this._context.recorderAppForTest = recorderApp;
    }
    _pausedStateChanged() {
        var _a;
        // If we are called upon page.pause, we don't have metadatas, populate them.
        for (const { metadata, sdkObject } of this._debugger.pausedDetails()) {
            if (!this._currentCallsMetadata.has(metadata))
                this.onBeforeCall(sdkObject, metadata);
        }
        (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setPaused(this._debugger.isPaused());
        this._updateUserSources();
        this.updateCallLog([...this._currentCallsMetadata.keys()]);
    }
    _setMode(mode) {
        var _a;
        this._mode = mode;
        (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setMode(this._mode);
        this._generator.setEnabled(this._mode === 'recording');
        debugger_1.Debugger.lookup(this._context).setMuted(this._mode === 'recording');
        if (this._mode !== 'none')
            this._context.pages()[0].bringToFront().catch(() => { });
    }
    _refreshOverlay() {
        for (const page of this._context.pages())
            page.mainFrame().evaluateExpression('window._playwrightRefreshOverlay()', false, undefined, 'main').catch(() => { });
    }
    async _onPage(page) {
        // First page is called page, others are called popup1, popup2, etc.
        const frame = page.mainFrame();
        page.on('close', () => {
            this._pageAliases.delete(page);
            this._generator.addAction({
                pageAlias,
                ...utils_1.describeFrame(page.mainFrame()),
                committed: true,
                action: {
                    name: 'closePage',
                    signals: [],
                }
            });
        });
        frame.on(frames_1.Frame.Events.Navigation, () => this._onFrameNavigated(frame, page));
        page.on(page_1.Page.Events.Download, () => this._onDownload(page));
        page.on(page_1.Page.Events.Dialog, () => this._onDialog(page));
        const suffix = this._pageAliases.size ? String(++this._lastPopupOrdinal) : '';
        const pageAlias = 'page' + suffix;
        this._pageAliases.set(page, pageAlias);
        if (page.opener()) {
            this._onPopup(page.opener(), page);
        }
        else {
            this._generator.addAction({
                pageAlias,
                ...utils_1.describeFrame(page.mainFrame()),
                committed: true,
                action: {
                    name: 'openPage',
                    url: page.mainFrame().url(),
                    signals: [],
                }
            });
        }
    }
    _clearScript() {
        this._generator.restart();
        if (!!this._params.startRecording) {
            for (const page of this._context.pages())
                this._onFrameNavigated(page.mainFrame(), page);
        }
    }
    async _performAction(frame, action) {
        // Commit last action so that no further signals are added to it.
        this._generator.commitLastAction();
        const page = frame._page;
        const actionInContext = {
            pageAlias: this._pageAliases.get(page),
            ...utils_1.describeFrame(frame),
            action
        };
        this._generator.willPerformAction(actionInContext);
        const noCallMetadata = instrumentation_1.internalCallMetadata();
        try {
            const kActionTimeout = 5000;
            if (action.name === 'click') {
                const { options } = utils_1.toClickOptions(action);
                await frame.click(noCallMetadata, action.selector, { ...options, timeout: kActionTimeout });
            }
            if (action.name === 'press') {
                const modifiers = utils_1.toModifiers(action.modifiers);
                const shortcut = [...modifiers, action.key].join('+');
                await frame.press(noCallMetadata, action.selector, shortcut, { timeout: kActionTimeout });
            }
            if (action.name === 'check')
                await frame.check(noCallMetadata, action.selector, { timeout: kActionTimeout });
            if (action.name === 'uncheck')
                await frame.uncheck(noCallMetadata, action.selector, { timeout: kActionTimeout });
            if (action.name === 'select')
                await frame.selectOption(noCallMetadata, action.selector, [], action.options.map(value => ({ value })), { timeout: kActionTimeout });
        }
        catch (e) {
            this._generator.performedActionFailed(actionInContext);
            return;
        }
        const timer = setTimeout(() => {
            // Commit the action after 5 seconds so that no further signals are added to it.
            actionInContext.committed = true;
            this._timers.delete(timer);
        }, 5000);
        this._generator.didPerformAction(actionInContext);
        this._timers.add(timer);
    }
    async _recordAction(frame, action) {
        // Commit last action so that no further signals are added to it.
        this._generator.commitLastAction();
        this._generator.addAction({
            pageAlias: this._pageAliases.get(frame._page),
            ...utils_1.describeFrame(frame),
            action
        });
    }
    _onFrameNavigated(frame, page) {
        const pageAlias = this._pageAliases.get(page);
        this._generator.signal(pageAlias, frame, { name: 'navigation', url: frame.url() });
    }
    _onPopup(page, popup) {
        const pageAlias = this._pageAliases.get(page);
        const popupAlias = this._pageAliases.get(popup);
        this._generator.signal(pageAlias, page.mainFrame(), { name: 'popup', popupAlias });
    }
    _onDownload(page) {
        const pageAlias = this._pageAliases.get(page);
        this._generator.signal(pageAlias, page.mainFrame(), { name: 'download', downloadAlias: String(++this._lastDownloadOrdinal) });
    }
    _onDialog(page) {
        const pageAlias = this._pageAliases.get(page);
        this._generator.signal(pageAlias, page.mainFrame(), { name: 'dialog', dialogAlias: String(++this._lastDialogOrdinal) });
    }
    async onBeforeCall(sdkObject, metadata) {
        var _a;
        if (this._mode === 'recording')
            return;
        this._currentCallsMetadata.set(metadata, sdkObject);
        this._allMetadatas.set(metadata.id, metadata);
        this._updateUserSources();
        this.updateCallLog([metadata]);
        if (metadata.params && metadata.params.selector) {
            this._highlightedSelector = metadata.params.selector;
            (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setSelector(this._highlightedSelector).catch(() => { });
        }
    }
    async onAfterCall(sdkObject, metadata) {
        if (this._mode === 'recording')
            return;
        if (!metadata.error)
            this._currentCallsMetadata.delete(metadata);
        this._updateUserSources();
        this.updateCallLog([metadata]);
    }
    _updateUserSources() {
        var _a;
        // Remove old decorations.
        for (const source of this._userSources.values()) {
            source.highlight = [];
            source.revealLine = undefined;
        }
        // Apply new decorations.
        let fileToSelect = undefined;
        for (const metadata of this._currentCallsMetadata.keys()) {
            if (!metadata.stack || !metadata.stack[0])
                continue;
            const { file, line } = metadata.stack[0];
            let source = this._userSources.get(file);
            if (!source) {
                source = { file, text: this._readSource(file), highlight: [], language: languageForFile(file) };
                this._userSources.set(file, source);
            }
            if (line) {
                const paused = this._debugger.isPaused(metadata);
                source.highlight.push({ line, type: metadata.error ? 'error' : (paused ? 'paused' : 'running') });
                source.revealLine = line;
                fileToSelect = source.file;
            }
        }
        this._pushAllSources();
        if (fileToSelect)
            (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setFile(fileToSelect);
    }
    _pushAllSources() {
        var _a;
        (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.setSources([...this._recorderSources, ...this._userSources.values()]);
    }
    async onBeforeInputAction(sdkObject, metadata) {
    }
    async onCallLog(logName, message, sdkObject, metadata) {
        this.updateCallLog([metadata]);
    }
    updateCallLog(metadatas) {
        var _a;
        if (this._mode === 'recording')
            return;
        const logs = [];
        for (const metadata of metadatas) {
            if (!metadata.method)
                continue;
            let status = 'done';
            if (this._currentCallsMetadata.has(metadata))
                status = 'in-progress';
            if (this._debugger.isPaused(metadata))
                status = 'paused';
            logs.push(recorderUtils_1.metadataToCallLog(metadata, status));
        }
        (_a = this._recorderApp) === null || _a === void 0 ? void 0 : _a.updateCallLogs(logs);
    }
    _readSource(fileName) {
        try {
            return fs.readFileSync(fileName, 'utf-8');
        }
        catch (e) {
            return '// No source available';
        }
    }
}
exports.RecorderSupplement = RecorderSupplement;
function languageForFile(file) {
    if (file.endsWith('.py'))
        return 'python';
    if (file.endsWith('.java'))
        return 'java';
    if (file.endsWith('.cs'))
        return 'csharp';
    return 'javascript';
}
//# sourceMappingURL=recorderSupplement.js.map