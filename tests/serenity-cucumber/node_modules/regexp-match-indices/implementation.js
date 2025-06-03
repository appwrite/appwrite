"use strict";
/*!
Copyright 2019 Ron Buckton

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/
/*
 require('foo').implementation or require('foo/implementation') is a spec-compliant JS function,
 that will depend on a receiver (a “this” value) as the spec requires.
 */
const config = require("./config");
const nativeExec = require("./native");
const regexp_tree_1 = require("regexp-tree");
const weakMeasurementRegExp = new WeakMap();
function exec(string) {
    return config.mode === "spec-compliant"
        ? execSpecCompliant(this, string)
        : execLazy(this, string);
}
function execLazy(regexp, string) {
    const index = regexp.lastIndex;
    const result = nativeExec.call(regexp, string);
    if (result === null)
        return null;
    // For performance reasons, we defer computing the indices until later. This isn't spec compliant,
    // but once we compute the indices we convert the result to a data-property.
    let indicesArray;
    Object.defineProperty(result, "indices", {
        enumerable: true,
        configurable: true,
        get() {
            if (indicesArray === undefined) {
                const { measurementRegExp, groupInfos } = getMeasurementRegExp(regexp);
                measurementRegExp.lastIndex = index;
                const measuredResult = nativeExec.call(measurementRegExp, string);
                if (measuredResult === null)
                    throw new TypeError();
                makeDataProperty(result, "indices", indicesArray = makeIndicesArray(measuredResult, groupInfos));
            }
            return indicesArray;
        },
        set(value) {
            makeDataProperty(result, "indices", value);
        }
    });
    return result;
}
function execSpecCompliant(regexp, string) {
    const { measurementRegExp, groupInfos } = getMeasurementRegExp(regexp);
    measurementRegExp.lastIndex = regexp.lastIndex;
    const measuredResult = nativeExec.call(measurementRegExp, string);
    if (measuredResult === null)
        return null;
    regexp.lastIndex = measurementRegExp.lastIndex;
    const result = [];
    makeDataProperty(result, 0, measuredResult[0]);
    for (const groupInfo of groupInfos) {
        makeDataProperty(result, groupInfo.oldGroupNumber, measuredResult[groupInfo.newGroupNumber]);
    }
    makeDataProperty(result, "index", measuredResult.index);
    makeDataProperty(result, "input", measuredResult.input);
    makeDataProperty(result, "groups", measuredResult.groups);
    makeDataProperty(result, "indices", makeIndicesArray(measuredResult, groupInfos));
    return result;
}
function getMeasurementRegExp(regexp) {
    let transformed = weakMeasurementRegExp.get(regexp);
    if (!transformed) {
        transformed = transformMeasurementGroups(regexp_tree_1.parse(`/${regexp.source}/${regexp.flags}`));
        weakMeasurementRegExp.set(regexp, transformed);
    }
    const groupInfos = transformed.getExtra();
    const measurementRegExp = transformed.toRegExp();
    return { measurementRegExp, groupInfos };
}
function makeIndicesArray(measuredResult, groupInfos) {
    const matchStart = measuredResult.index;
    const matchEnd = matchStart + measuredResult[0].length;
    const hasGroups = !!measuredResult.groups;
    const indicesArray = [];
    const groups = hasGroups ? Object.create(null) : undefined;
    makeDataProperty(indicesArray, 0, [matchStart, matchEnd]);
    for (const groupInfo of groupInfos) {
        let indices;
        if (measuredResult[groupInfo.newGroupNumber] !== undefined) {
            let startIndex = matchStart;
            if (groupInfo.measurementGroups) {
                for (const measurementGroup of groupInfo.measurementGroups) {
                    startIndex += measuredResult[measurementGroup].length;
                }
            }
            const endIndex = startIndex + measuredResult[groupInfo.newGroupNumber].length;
            indices = [startIndex, endIndex];
        }
        makeDataProperty(indicesArray, groupInfo.oldGroupNumber, indices);
        if (groups && groupInfo.groupName !== undefined) {
            makeDataProperty(groups, groupInfo.groupName, indices);
        }
    }
    makeDataProperty(indicesArray, "groups", groups);
    return indicesArray;
}
function makeDataProperty(result, key, value) {
    const existingDesc = Object.getOwnPropertyDescriptor(result, key);
    if (existingDesc ? existingDesc.configurable : Object.isExtensible(result)) {
        const newDesc = {
            enumerable: existingDesc ? existingDesc.enumerable : true,
            configurable: existingDesc ? existingDesc.configurable : true,
            writable: true,
            value
        };
        Object.defineProperty(result, key, newDesc);
    }
}
let groupRenumbers;
let hasBackreferences = false;
let nodesContainingCapturingGroup = new Set();
let containsCapturingGroupStack = [];
let containsCapturingGroup = false;
let nextNewGroupNumber = 1;
let measurementGroupStack = [];
let measurementGroupsForGroup = new Map();
let newGroupNumberForGroup = new Map();
const handlers = {
    init() {
        hasBackreferences = false;
        nodesContainingCapturingGroup.clear();
        containsCapturingGroupStack.length = 0;
        containsCapturingGroup = false;
        nextNewGroupNumber = 1;
        measurementGroupStack.length = 0;
        measurementGroupsForGroup.clear();
        newGroupNumberForGroup.clear();
        groupRenumbers = [];
    },
    RegExp(path) {
        regexp_tree_1.traverse(path.node, visitor);
        if (nodesContainingCapturingGroup.size > 0) {
            regexp_tree_1.transform(path.node, builder);
            regexp_tree_1.transform(path.node, groupRenumberer);
            if (hasBackreferences) {
                regexp_tree_1.transform(path.node, backreferenceRenumberer);
            }
        }
        return false;
    }
};
const nodeCallbacks = {
    pre(path) {
        containsCapturingGroupStack.push(containsCapturingGroup);
        containsCapturingGroup = path.node.type === "Group" && path.node.capturing;
    },
    post(path) {
        if (containsCapturingGroup) {
            nodesContainingCapturingGroup.add(path.node);
        }
        containsCapturingGroup = containsCapturingGroupStack.pop() || containsCapturingGroup;
    }
};
const visitor = {
    Alternative: nodeCallbacks,
    Disjunction: nodeCallbacks,
    Assertion: nodeCallbacks,
    Group: nodeCallbacks,
    Repetition: nodeCallbacks,
    Backreference(path) { hasBackreferences = true; }
};
const builder = {
    Alternative(path) {
        if (nodesContainingCapturingGroup.has(path.node)) {
            // aa(b)c       -> (aa)(b)c
            // aa(b)c(d)    -> (aa)(b)(c)(d)
            // aa(b)+c(d)   -> (aa)((b)+)(c)(d);
            let lastMeasurementIndex = 0;
            let pendingTerms = [];
            const measurementGroups = [];
            const terms = [];
            for (let i = 0; i < path.node.expressions.length; i++) {
                const term = path.node.expressions[i];
                if (nodesContainingCapturingGroup.has(term)) {
                    if (i > lastMeasurementIndex) {
                        const measurementGroup = {
                            type: "Group",
                            capturing: true,
                            number: -1,
                            expression: pendingTerms.length > 1 ? { type: "Alternative", expressions: pendingTerms } :
                                pendingTerms.length === 1 ? pendingTerms[0] :
                                    null
                        };
                        terms.push(measurementGroup);
                        measurementGroups.push(measurementGroup);
                        lastMeasurementIndex = i;
                        pendingTerms = [];
                    }
                    measurementGroupStack.push(measurementGroups);
                    regexp_tree_1.transform(term, builder);
                    measurementGroupStack.pop();
                    pendingTerms.push(term);
                    continue;
                }
                pendingTerms.push(term);
            }
            path.update({ expressions: terms.concat(pendingTerms) });
        }
        return false;
    },
    Group(path) {
        if (!path.node.capturing)
            return;
        measurementGroupsForGroup.set(path.node, getMeasurementGroups());
    }
};
const groupRenumberer = {
    Group(path) {
        if (!groupRenumbers)
            throw new Error("Not initialized.");
        if (!path.node.capturing)
            return;
        const oldGroupNumber = path.node.number;
        const newGroupNumber = nextNewGroupNumber++;
        const measurementGroups = measurementGroupsForGroup.get(path.node);
        if (oldGroupNumber !== -1) {
            groupRenumbers.push({
                oldGroupNumber,
                newGroupNumber,
                measurementGroups: measurementGroups && measurementGroups.map(group => group.number),
                groupName: path.node.name
            });
            newGroupNumberForGroup.set(oldGroupNumber, newGroupNumber);
        }
        path.update({ number: newGroupNumber });
    }
};
const backreferenceRenumberer = {
    Backreference(path) {
        const newGroupNumber = newGroupNumberForGroup.get(path.node.number);
        if (newGroupNumber) {
            if (path.node.kind === "number") {
                path.update({
                    number: newGroupNumber,
                    reference: newGroupNumber
                });
            }
            else {
                path.update({
                    number: newGroupNumber
                });
            }
        }
    }
};
function getMeasurementGroups() {
    const measurementGroups = [];
    for (const array of measurementGroupStack) {
        for (const item of array) {
            measurementGroups.push(item);
        }
    }
    return measurementGroups;
}
function transformMeasurementGroups(ast) {
    const result = regexp_tree_1.transform(ast, handlers);
    return new regexp_tree_1.TransformResult(result.getAST(), groupRenumbers);
}
module.exports = exec;
//# sourceMappingURL=implementation.js.map