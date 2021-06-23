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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const empty_1 = __importDefault(require("./empty"));
const util_1 = require("../util");
const base_1 = require("./base");
class JUnitReporter extends empty_1.default {
    constructor(options = {}) {
        super();
        this.totalTests = 0;
        this.totalFailures = 0;
        this.totalSkipped = 0;
        this.stripANSIControlSequences = false;
        this.outputFile = options.outputFile;
        this.stripANSIControlSequences = options.stripANSIControlSequences || false;
    }
    onBegin(config, suite) {
        this.config = config;
        this.suite = suite;
        this.timestamp = Date.now();
        this.startTime = util_1.monotonicTime();
    }
    onEnd() {
        const duration = util_1.monotonicTime() - this.startTime;
        const children = [];
        for (const suite of this.suite.suites)
            children.push(this._buildTestSuite(suite));
        const tokens = [];
        const self = this;
        const root = {
            name: 'testsuites',
            attributes: {
                id: process.env[`PLAYWRIGHT_JUNIT_SUITE_ID`] || '',
                name: process.env[`PLAYWRIGHT_JUNIT_SUITE_NAME`] || '',
                tests: self.totalTests,
                failures: self.totalFailures,
                skipped: self.totalSkipped,
                errors: 0,
                time: duration / 1000
            },
            children
        };
        serializeXML(root, tokens, this.stripANSIControlSequences);
        const reportString = tokens.join('\n');
        const outputFile = this.outputFile || process.env[`PLAYWRIGHT_JUNIT_OUTPUT_NAME`];
        if (outputFile) {
            fs_1.default.mkdirSync(path_1.default.dirname(outputFile), { recursive: true });
            fs_1.default.writeFileSync(outputFile, reportString);
        }
        else {
            console.log(reportString);
        }
    }
    _buildTestSuite(suite) {
        let tests = 0;
        let skipped = 0;
        let failures = 0;
        let duration = 0;
        const children = [];
        suite.findTest(test => {
            ++tests;
            if (test.skipped)
                ++skipped;
            if (!test.ok())
                ++failures;
            for (const result of test.results)
                duration += result.duration;
            this._addTestCase(test, children);
        });
        this.totalTests += tests;
        this.totalSkipped += skipped;
        this.totalFailures += failures;
        const entry = {
            name: 'testsuite',
            attributes: {
                name: path_1.default.relative(this.config.rootDir, suite.file),
                timestamp: this.timestamp,
                hostname: '',
                tests,
                failures,
                skipped,
                time: duration / 1000,
                errors: 0,
            },
            children
        };
        return entry;
    }
    _addTestCase(test, entries) {
        const entry = {
            name: 'testcase',
            attributes: {
                name: test.spec.fullTitle(),
                classname: base_1.formatTestTitle(this.config, test),
                time: (test.results.reduce((acc, value) => acc + value.duration, 0)) / 1000
            },
            children: []
        };
        entries.push(entry);
        if (test.skipped) {
            entry.children.push({ name: 'skipped' });
            return;
        }
        if (!test.ok()) {
            entry.children.push({
                name: 'failure',
                attributes: {
                    message: `${path_1.default.basename(test.spec.file)}:${test.spec.line}:${test.spec.column} ${test.spec.title}`,
                    type: 'FAILURE',
                },
                text: base_1.stripAscii(base_1.formatFailure(this.config, test))
            });
        }
        for (const result of test.results) {
            for (const stdout of result.stdout) {
                entries.push({
                    name: 'system-out',
                    text: stdout.toString()
                });
            }
            for (const stderr of result.stderr) {
                entries.push({
                    name: 'system-err',
                    text: stderr.toString()
                });
            }
        }
    }
}
function serializeXML(entry, tokens, stripANSIControlSequences) {
    const attrs = [];
    for (const [name, value] of Object.entries(entry.attributes || {}))
        attrs.push(`${name}="${escape(String(value), stripANSIControlSequences, false)}"`);
    tokens.push(`<${entry.name}${attrs.length ? ' ' : ''}${attrs.join(' ')}>`);
    for (const child of entry.children || [])
        serializeXML(child, tokens, stripANSIControlSequences);
    if (entry.text)
        tokens.push(escape(entry.text, stripANSIControlSequences, true));
    tokens.push(`</${entry.name}>`);
}
// See https://en.wikipedia.org/wiki/Valid_characters_in_XML
const discouragedXMLCharacters = /[\u0001-\u0008\u000b-\u000c\u000e-\u001f\u007f-\u0084\u0086-\u009f]/g;
const ansiControlSequence = new RegExp('[\\u001B\\u009B][[\\]()#;?]*(?:(?:(?:[a-zA-Z\\d]*(?:;[-a-zA-Z\\d\\/#&.:=?%@~_]*)*)?\\u0007)|(?:(?:\\d{1,4}(?:;\\d{0,4})*)?[\\dA-PR-TZcf-ntqry=><~]))', 'g');
function escape(text, stripANSIControlSequences, isCharacterData) {
    if (stripANSIControlSequences)
        text = text.replace(ansiControlSequence, '');
    const escapeRe = isCharacterData ? /[&<]/g : /[&"<>]/g;
    text = text.replace(escapeRe, c => ({ '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' }[c]));
    if (isCharacterData)
        text = text.replace(/]]>/g, ']]&gt;');
    text = text.replace(discouragedXMLCharacters, '');
    return text;
}
exports.default = JUnitReporter;
//# sourceMappingURL=junit.js.map