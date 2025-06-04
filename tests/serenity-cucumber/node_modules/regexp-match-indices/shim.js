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
 require('foo').shim or require('foo/shim') is a function that when invoked, will call getPolyfill,
 and if the polyfill doesn’t match the built-in value, will install it into the global environment.
 */
const getPolyfill = require("./polyfill");
function shim() {
    const polyfill = getPolyfill();
    if (RegExp.prototype.exec !== polyfill) {
        RegExp.prototype.exec = polyfill;
    }
}
module.exports = shim;
//# sourceMappingURL=shim.js.map