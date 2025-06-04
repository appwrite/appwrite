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
const implementation = require("./implementation");
const native = require("./native");
const getPolyfill = require("./polyfill");
const shim = require("./shim");
const config = require("./config");
const polyfill = getPolyfill();
function exec(regexp, string) {
    return polyfill.call(regexp, string);
}
exec.implementation = implementation;
exec.native = native;
exec.getPolyfill = getPolyfill;
exec.shim = shim;
exec.config = config;
(function (exec) {
})(exec || (exec = {}));
module.exports = exec;
//# sourceMappingURL=index.js.map