(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-appwrite',
            repeat: true,
            controller: function(element, view, container, form, alerts, expression, window, di, state) {
                let action      = element.dataset['appwrite'];
                let singleton   = element.dataset['singleton'];
                let loaded      = element.dataset['loaded'];
                let service     = element.dataset['service'] || action;
                let event       = element.dataset['event'];   // load, click, change, submit
                let success     = element.dataset['success'] || ''; // render, alert, redirect
                let error       = element.dataset['error'] || '';  // alert, redirect, page
                let confirm     = element.dataset['confirm'] || ''; // Free text
                let loading     = element.dataset['loading'] || ''; // Free text
                let loaderId    = null;
                let scope       = element.dataset['scope'] || 'sdk'; // Free text
                let debug       = !!(element.dataset['debug']); // Free text

                if (debug) console.log('%c[service init]: ' + action + ' (' + service + ')', 'color:red');

                if(loaded) {
                    return true; // Instance already created
                }

                success = success.trim().split(',');
                error   = error.trim().split(',');

                let resolve = function(target) {
                    let FN_ARGS = /^function\s*[^\(]*\(\s*([^\)]*)\)/m;
                    let text = target.toString();
                    let args = text.match(FN_ARGS)[1].split(',');
                    let data = ('FORM' === element.tagName) ? form.toJson(element) : {};

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

                let successes = {
                    /**
                     *
                     */
                    none: function () {

                    },

                    'save': function () {
                        return function(data) {
                            container.set(service.replace('.', '-'), JSON.parse(data), true, true);
                        };
                    },

                    /**
                     *
                     * @param view
                     * @returns {Function}
                     */
                    'render': function (view) {
                        return function(data) {
                            try {
                                container.set(service.replace('.', '-'), JSON.parse(data), true, true);
                            } catch (e) {
                                container.set(service.replace('.', '-'), {}, true, true);
                            }

                            view.render(element);

                            let rerender = element.dataset['successRerender'] || '';

                            element.setAttribute('data-success-rerender', '');

                            rerender = rerender.trim().split(',');

                            for (let i = 0; i < rerender.length; i++) {
                                if('' === rerender[i]) {
                                    continue;
                                }

                                document.addEventListener(rerender[i], function (event) {
                                    console.log(event.type + ' triggered rendering');

                                    if(element && element.parentNode) {
                                        console.log(event.type + ' triggered rendering 2');
                                        view.render(element.parentNode);
                                    }
                                })
                            }
                        }
                    },
                    'reset': function () {
                        return function() {
                            if('FORM' === element.tagName) {
                                return element.reset();
                            }

                            throw new Error('This callback is only valid for forms');
                        }
                    },

                    'empty': function () {
                        return function() {
                            container.set(service.replace('.', '-'), {}, true);
                            view.render(element);
                        }
                    },

                    /**
                     *
                     * @param alerts
                     * @returns {Function}
                     */
                    'alert': function (alerts) {
                        return function() {
                            let alert = element.dataset['successAlert'] || 'Success';
                            alerts.send({text: alert, class: 'success'}, 3000);
                        }
                    },

                    /**
                     *
                     * @returns {Function}
                     */
                    'update': function () {
                        return function(data) {
                            let service     = element.dataset['successUpdate'] || null;

                            if(service) {
                                container.set(service, JSON.parse(data), true);
                            }

                            //let idElement   = element.elements.id;
                            //if(idElement) {
                            //    idElement = (idElement.length > 1) ? idElement[0] : idElement;
                            //    idElement.value = JSON.parse(data).id;
                            //}
                        }
                    },

                    /**
                     *
                     * @param state
                     * @returns {Function}
                     */
                    'redirect': function (state) {
                        return function() {
                            let url = expression.parse(element.dataset['successRedirectUrl']) || '/';

                            state.change(url);
                        }
                    },

                    /**
                     *
                     * @param document
                     * @returns {Function}
                     */
                    'trigger': function (document) {
                        return function() {
                            let triggers = element.dataset['successTriggers'] || '';

                            triggers = triggers.trim().split(',');

                            for (let i = 0; i < triggers.length; i++) {
                                if('' === triggers[i]) {
                                    continue;
                                }
                                if (debug) console.log('%c[event triggered]: ' + triggers[i], 'color:green');

                                di.report(triggers[i]);

                                document.dispatchEvent(new CustomEvent(triggers[i]));
                            }
                        }
                    }
                };

                let errors = {
                    /**
                     *
                     */
                    none: function () {

                    },

                    /**
                     *
                     * @param alerts
                     * @returns {Function}
                     */
                    'alert': function (alerts) {
                        return function() {
                            let alert = element.dataset['errorAlert'] || 'Failure (' + action + ')';
                            alerts.send({text: alert, class: 'error'}, 3000);
                        }
                    },

                    /**
                     *
                     * @param state
                     * @returns {Function}
                     */
                    'redirect': function (state) {
                        return function() {
                            let url = expression.parse(element.dataset['errorRedirectUrl']) || '/';

                            state.change(url);
                        }
                    },

                    /**
                     *
                     * @param view
                     * @returns {Function}
                     */
                    'render': function (view) {
                        return function() {
                            container.set(service.replace('.', '-'), {}, true, true);

                            view.render(element);

                            let rerender = element.getAttribute('data-error-rerender') || '';

                            element.setAttribute('data-error-rerender', '');

                            rerender = rerender.trim().split(',');

                            for (let i = 0; i < rerender.length; i++) {
                                if('' === rerender[i]) {
                                    continue;
                                }

                                document.addEventListener(rerender[i], function () {
                                    if(element && element.parentNode) {
                                        view.render(element.parentNode);
                                    }
                                })
                            }

                        }
                    },

                    /**
                     *
                     * @param document
                     * @returns {Function}
                     */
                    'trigger': function (document) {
                        return function() {
                            let triggers = element.dataset['errorTriggers'] || '';

                            triggers = triggers.trim().split(',');

                            for (let i = 0; i < triggers.length; i++) {
                                if('' === triggers[i]) {
                                    continue;
                                }

                                di.report(triggers[i]);

                                document.dispatchEvent(new CustomEvent(triggers[i]));
                            }
                        }
                    }
                };

                let exec = function(event) {
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

                    if(element.$lsLock) {
                        // console.warn('Execution of ' + scope + '.' + action + ' is locked, wait for previous execution to finish', element);
                        return;
                    }

                    element.$lsLock = true;

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

                            for (let i = 0; i < success.length; i++) { // Trigger success callbacks
                                container.resolve(successes[success[i]])(data);
                            }

                            element.$lsLock = false; // Release lock
                        }, function (exception) {
                            if(loaderId !== null) { // Remove loader if needed
                                alerts.remove(loaderId);
                            }

                            if(!element) {
                                return;
                            }

                            for (let i = 0; i < error.length; i++) { // Trigger failure callbacks
                                container.resolve(errors[error[i]])(exception);
                            }

                            element.$lsLock = false; // Release lock
                        });
                };

                let events = event.trim().split(',');

                for (let y = 0; y < events.length; y++) {
                    if ('' === events[y]) {
                        continue;
                    }

                    switch (events[y].trim()) {
                        case 'empty':
                            for (let i = 0; i < success.length; i++) {
                                container.resolve(successes[success[i]])('{}');
                            }
                            break;
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
                            element.setAttribute('data-event', 'none'); // Avoid re-attaching event
                            break;
                        default:
                            //document.addEventListener(events[y], exec);
                            di.listen(events[y], exec);
                            element.setAttribute('data-event', 'none'); // Avoid re-attaching event
                    }

                    if (debug) console.log('%cregistered: "' + events[y].trim() + '" (' + service + ')', 'color:blue');
                }

                if(singleton) {
                    element.dataset.loaded = 'true';
                }
            }
        }
    );
})(window);