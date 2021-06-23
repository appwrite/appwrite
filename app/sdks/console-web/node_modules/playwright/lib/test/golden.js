"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
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
exports.compare = void 0;
const safe_1 = __importDefault(require("colors/safe"));
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const jpeg_js_1 = __importDefault(require("jpeg-js"));
const pixelmatch_1 = __importDefault(require("pixelmatch"));
const diff_match_patch_1 = require("../third_party/diff_match_patch");
// Note: we require the pngjs version of pixelmatch to avoid version mismatches.
const { PNG } = require(require.resolve('pngjs', { paths: [require.resolve('pixelmatch')] }));
const extensionToMimeType = {
    'dat': 'application/octet-string',
    'jpeg': 'image/jpeg',
    'jpg': 'image/jpeg',
    'png': 'image/png',
    'txt': 'text/plain',
};
const GoldenComparators = {
    'application/octet-string': compareBuffers,
    'image/png': compareImages,
    'image/jpeg': compareImages,
    'text/plain': compareText,
};
function compareBuffers(actualBuffer, expectedBuffer, mimeType) {
    if (!actualBuffer || !(actualBuffer instanceof Buffer))
        return { errorMessage: 'Actual result should be Buffer.' };
    if (Buffer.compare(actualBuffer, expectedBuffer))
        return { errorMessage: 'Buffers differ' };
    return null;
}
function compareImages(actualBuffer, expectedBuffer, mimeType, options = {}) {
    if (!actualBuffer || !(actualBuffer instanceof Buffer))
        return { errorMessage: 'Actual result should be Buffer.' };
    const actual = mimeType === 'image/png' ? PNG.sync.read(actualBuffer) : jpeg_js_1.default.decode(actualBuffer);
    const expected = mimeType === 'image/png' ? PNG.sync.read(expectedBuffer) : jpeg_js_1.default.decode(expectedBuffer);
    if (expected.width !== actual.width || expected.height !== actual.height) {
        return {
            errorMessage: `Sizes differ; expected image ${expected.width}px X ${expected.height}px, but got ${actual.width}px X ${actual.height}px. `
        };
    }
    const diff = new PNG({ width: expected.width, height: expected.height });
    const count = pixelmatch_1.default(expected.data, actual.data, diff.data, expected.width, expected.height, { threshold: 0.2, ...options });
    return count > 0 ? { diff: PNG.sync.write(diff) } : null;
}
function compareText(actual, expectedBuffer) {
    if (typeof actual !== 'string')
        return { errorMessage: 'Actual result should be string' };
    const expected = expectedBuffer.toString('utf-8');
    if (expected === actual)
        return null;
    const dmp = new diff_match_patch_1.diff_match_patch();
    const d = dmp.diff_main(expected, actual);
    dmp.diff_cleanupSemantic(d);
    return {
        errorMessage: diff_prettyTerminal(d)
    };
}
function compare(actual, name, snapshotPath, outputPath, updateSnapshots, options) {
    const snapshotFile = snapshotPath(name);
    if (!fs_1.default.existsSync(snapshotFile)) {
        const writingActual = updateSnapshots === 'all' || updateSnapshots === 'missing';
        if (writingActual) {
            fs_1.default.mkdirSync(path_1.default.dirname(snapshotFile), { recursive: true });
            fs_1.default.writeFileSync(snapshotFile, actual);
        }
        const message = snapshotFile + ' is missing in snapshots' + (writingActual ? ', writing actual.' : '.');
        if (updateSnapshots === 'all') {
            console.log(message);
            return { pass: true, message };
        }
        return { pass: false, message };
    }
    const expected = fs_1.default.readFileSync(snapshotFile);
    const extension = path_1.default.extname(snapshotFile).substring(1);
    const mimeType = extensionToMimeType[extension] || 'application/octet-string';
    const comparator = GoldenComparators[mimeType];
    if (!comparator) {
        return {
            pass: false,
            message: 'Failed to find comparator with type ' + mimeType + ': ' + snapshotFile,
        };
    }
    const result = comparator(actual, expected, mimeType, options);
    if (!result)
        return { pass: true };
    if (updateSnapshots === 'all') {
        fs_1.default.mkdirSync(path_1.default.dirname(snapshotFile), { recursive: true });
        fs_1.default.writeFileSync(snapshotFile, actual);
        console.log(snapshotFile + ' does not match, writing actual.');
        return {
            pass: true,
            message: snapshotFile + ' running with --update-snapshots, writing actual.'
        };
    }
    const outputFile = outputPath(name);
    const expectedPath = addSuffix(outputFile, '-expected');
    const actualPath = addSuffix(outputFile, '-actual');
    const diffPath = addSuffix(outputFile, '-diff');
    fs_1.default.writeFileSync(expectedPath, expected);
    fs_1.default.writeFileSync(actualPath, actual);
    if (result.diff)
        fs_1.default.writeFileSync(diffPath, result.diff);
    const output = [
        safe_1.default.red(`Snapshot comparison failed:`),
    ];
    if (result.errorMessage) {
        output.push('');
        output.push(indent(result.errorMessage, '  '));
    }
    output.push('');
    output.push(`Expected: ${safe_1.default.yellow(expectedPath)}`);
    output.push(`Received: ${safe_1.default.yellow(actualPath)}`);
    if (result.diff)
        output.push(`    Diff: ${safe_1.default.yellow(diffPath)}`);
    return {
        pass: false,
        message: output.join('\n'),
    };
}
exports.compare = compare;
function indent(lines, tab) {
    return lines.replace(/^(?=.+$)/gm, tab);
}
function addSuffix(filePath, suffix, customExtension) {
    const dirname = path_1.default.dirname(filePath);
    const ext = path_1.default.extname(filePath);
    const name = path_1.default.basename(filePath, ext);
    return path_1.default.join(dirname, name + suffix + (customExtension || ext));
}
function diff_prettyTerminal(diffs) {
    const html = [];
    for (let x = 0; x < diffs.length; x++) {
        const op = diffs[x][0]; // Operation (insert, delete, equal)
        const data = diffs[x][1]; // Text of change.
        const text = data;
        switch (op) {
            case diff_match_patch_1.DIFF_INSERT:
                html[x] = safe_1.default.green(text);
                break;
            case diff_match_patch_1.DIFF_DELETE:
                html[x] = safe_1.default.strikethrough(safe_1.default.red(text));
                break;
            case diff_match_patch_1.DIFF_EQUAL:
                html[x] = text;
                break;
        }
    }
    return html.join('');
}
//# sourceMappingURL=golden.js.map