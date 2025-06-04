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
 require('foo').getPolyfill or require('foo/polyfill') is a function that when invoked, will return
 the most compliant and performant function that it can - if a native version is available, and does
 not violate the spec, then the native function will be returned - otherwise, either the implementation,
 or a custom, wrapped version of the native function, will be returned. This is also the result that
 will be used as the default export.
 */
const nativeExec = require("./native");
const implementation = require("./implementation");
function getPolyfill() {
    const re = new RegExp("a");
    const match = nativeExec.call(re, "a");
    if (match.indices) {
        return nativeExec;
    }
    return implementation;
}
module.exports = getPolyfill;
//# sourceMappingURL=polyfill.js.map