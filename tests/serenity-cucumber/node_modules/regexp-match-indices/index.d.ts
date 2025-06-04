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
import * as types from "./types";
declare function exec(regexp: RegExp, string: string): types.RegExpExecArray | null;
declare namespace exec {
    var implementation: (this: RegExp, string: string) => RegExpExecArray | null;
    var native: (this: RegExp, string: string) => RegExpExecArray | null;
    var getPolyfill: () => (this: RegExp, string: string) => RegExpExecArray | null;
    var shim: () => void;
    var config: {
        mode: "lazy" | "spec-compliant";
    };
}
declare namespace exec {
    export import RegExpExecArray = types.RegExpExecArray;
    export import RegExpExecIndicesArray = types.RegExpExecIndicesArray;
}
export = exec;
