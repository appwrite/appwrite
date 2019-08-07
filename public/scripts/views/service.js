(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-service',
            repeat: false,
            controller: function(element, view, container, form, alerts, expression, window) {
                let action      = element.dataset['service'];
                let service     = element.dataset['name'] || action;
                let event       = element.dataset['event'];   // load, click, change, submit
                let confirm     = element.dataset['confirm'] || ''; // Free text
                let loading     = element.dataset['loading'] || ''; // Free text
                let loaderId    = null;
                let scope       = element.dataset['scope'] || 'sdk'; // Free text
                let debug       = !!(element.dataset['debug']); // Free text

                if (debug) console.log('%c[service init]: ' + action + ' (' + service + ')', 'color:red');

                let resolve = function(target) {
                    let data = ('FORM' === element.tagName) ? form.toJson(element) : {};

                    const REGEX_COMMENTS = /((\/\/.*$)|(\/\*[\s\S]*?\*\/))/mg;
                    const REGEX_FUNCTION_PARAMS = /(?:\s*(?:function\s*[^(]*)?\s*)((?:[^'"]|(?:(?:(['"])(?:(?:.*?[^\\]\2)|\2))))*?)\s*(?=(?:=>)|{)/m;
                    const REGEX_PARAMETERS_VALUES = /\s*([\w\\$]+)\s*(?:=\s*((?:(?:(['"])(?:\3|(?:.*?[^\\]\3)))((\s*\+\s*)(?:(?:(['"])(?:\6|(?:.*?[^\\]\6)))|(?:[\w$]*)))*)|.*?))?\s*(?:,|$)/gm;

                    /**
                     * Original Solution From:
                     * @see https://stackoverflow.com/a/41322698/2299554
                     *  Notice: this version add support for $ sign in arg name.
                     *
                     * Retrieve a function's parameter names and default values
                     * Notes:
                     *  - parameters with default values will not show up in transpiler code (Babel) because the parameter is removed from the function.
                     *  - does NOT support inline arrow functions as default values
                     *      to clarify: ( name = "string", add = defaultAddFunction )   - is ok
                     *                  ( name = "string", add = ( a )=> a + 1 )        - is NOT ok
                     *  - does NOT support default string value that are appended with a non-standard ( word characters or $ ) variable name
                     *      to clarify: ( name = "string" + b )         - is ok
                     *                  ( name = "string" + $b )        - is ok
                     *                  ( name = "string" + b + "!" )   - is ok
                     *                  ( name = "string" + λ )         - is NOT ok
                     * @param {function} func
                     * @returns {Array} - An array of the given function's parameter [key, default value] pairs.
                     */
                    function getParams(func) {
                        let functionAsString = func.toString();
                        let params = [];
                        let match;

                        functionAsString = functionAsString.replace(REGEX_COMMENTS, '');
                        functionAsString = functionAsString.match(REGEX_FUNCTION_PARAMS)[1];

                        if (functionAsString.charAt(0) === '(') {
                            functionAsString = functionAsString.slice(1, -1);
                        }

                        while (match = REGEX_PARAMETERS_VALUES.exec(functionAsString)) {
                            //params.push([match[1], match[2]]); // with default values
                            params.push(match[1]); // only with arg name
                        }

                        return params;
                    }

                    let args = getParams(target);

                    if (debug) console.log('%c[form data]: ', 'color:green', data);

                    return target.apply(target, args.map(function(value) {
                        let result = null;

                        if(!value) {
                            return null;
                        }

                        /**
                         * 1. Get from element data-param-* (expression supported)
                         * 2. Get from element data-param-state-*
                         * 3. Get from element form object-*
                         */

                        if(element.dataset['param' + value.charAt(0).toUpperCase() + value.slice(1)]) {
                            result = expression.parse(element.dataset['param' + value.charAt(0).toUpperCase() + value.slice(1)]);
                        }

                        if(data[value]) {
                            result = data[value];
                        }

                        if(!result) {
                            result = '';
                        }

                        if (debug) console.log('%c[param resolved]: (' + service + ') ' + value + '=' + result, 'color:#808080');

                        return result;
                    }));
                };

                let exec = function(event) {
                    element.$lsSkip = true;

                    if (debug) console.log('%c[executed]: ' + scope + '.' + action, 'color:yellow', event, element, document.body.contains(element));

                    if(!document.body.contains(element)) {
                        element = undefined;
                        return false;
                    }

                    if(event) {
                        event.preventDefault();
                    }

                    if(confirm) {
                        if (window.confirm(confirm) !== true) {
                            return false;
                        }
                    }

                    if(loading) {
                        loaderId = alerts.send({text: loading, class: ''}, 0);
                    }

                    let method = container.path(scope + '.' + action);

                    if(!method) {
                        throw new Error('Method "' + scope + '.' + action + '" not found');
                    }

                    let result = resolve(method);

                    if(!result) {
                        return;
                    }

                    result
                        .then(function (data) {
                            if(loaderId !== null) { // Remove loader if needed
                                alerts.remove(loaderId);
                            }
                            
                            if(!element) {
                                return;
                            }
                            
                            try {
                                container.set(service.replace('.', '-'), data, true, true);
                                if (debug) console.log('%cservice ready: "' + service.replace('.', '-') + '"', 'color:green');
                                if (debug) console.log('%cservice:', 'color:blue', container.get(service.replace('.', '-')));
                            } catch (e) {
                                container.set(service.replace('.', '-'), {}, true);
                            }

                            element.$lsSkip = false;

                            view.render(element);
                        }, function (exception) {
                            if(loaderId !== null) { // Remove loader if needed
                                alerts.remove(loaderId);
                            }

                            if(!element) {
                                return;
                            }
                        });
                };

                let events = event.trim().split(',');

                for (let y = 0; y < events.length; y++) {
                    if ('' === events[y]) {
                        continue;
                    }

                    switch (events[y].trim()) {
                        case 'load':
                            exec();
                            break;
                        case 'none':
                            break;
                        case 'click':
                        case 'change':
                        case 'keypress':
                        case 'keydown':
                        case 'keyup':
                        case 'input':
                        case 'submit':
                            element.addEventListener(events[y], exec);
                            break;
                        default:
                            document.addEventListener(events[y], exec);
                    }

                    if (debug) console.log('%cregistered: "' + events[y].trim() + '" (' + service + ')', 'color:blue');
                }
            }
        }
    );
})(window);