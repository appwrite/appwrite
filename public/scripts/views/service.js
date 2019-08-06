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
                    console.log(result);

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