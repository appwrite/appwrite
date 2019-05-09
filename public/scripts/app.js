window.Litespeed.container.get('state')
    .add('/', {
        template: '/',
        scope: 'home'
    })
    .add('/soon', {
        template: '/soon',
        scope: 'soon'
    })
    .add('/products/:product', {
        template: function (window) {
            return window.location.pathname;
        },
        scope: 'home'
    })
    .add('/pricing', {
        template: '/pricing',
        scope: 'home'
    })
    .add('/docs', {
        template: '/docs',
        scope: 'home'
    })
    .add('/docs/:page', {
        template: function (window) {
            return window.location.pathname;
        },
        scope: 'home'
    })
    .add('/support', {
        template: '/support',
        scope: 'home'
    })
    .add('/contact', {
        template: '/contact',
        scope: 'home'
    })
    .add('/contact/thank-you', {
        template: '/contact/thank-you',
        scope: 'home'
    })
    .add('/auth/signin', {
        template: '/auth/signin',
        scope: 'home'
    })
    .add('/auth/signup', {
        template: '/auth/signup',
        scope: 'home'
    })
    .add('/auth/recovery', {
        template: '/auth/recovery',
        scope: 'home'
    })
    .add('/auth/recovery/reset', {
        template: '/auth/recovery/reset',
        scope: 'home'
    })
    .add('/auth/confirm', {
        template: '/auth/confirm',
        scope: 'home'
    })
    .add('/auth/join', {
        template: '/auth/join',
        scope: 'home'
    })
    .add('/company/about', {
        template: '/company/about',
        scope: 'home'
    })
    .add('/policy/security', {
        template: '/policy/security',
        scope: 'home'
    })
    .add('/policy/terms', {
        template: '/policy/terms',
        scope: 'home'
    })
    .add('/policy/privacy', {
        template: '/policy/privacy',
        scope: 'home'
    })
    .add('/policy/sub-processors', {
        template: '/policy/sub-processors',
        scope: 'home'
    })
    .add('/policy/cookies', {
        template: '/policy/cookies',
        scope: 'home'
    })
    .add('/console', {
        template: '/console',
        scope: 'console'
    })
    .add('/console/account', {
        template: '/console/account',
        scope: 'console'
    })
    .add('/console/account/:tab', {
        template: '/console/account',
        scope: 'console'
    })
    .add('/console/home', {
        template: '/console/home',
        scope: 'console',
        project: true
    })
    .add('/console/home/:tab', {
        template: '/console/home',
        scope: 'console',
        project: true
    })
    .add('/console/platforms/:platform', {
        template: function (window) {
            return window.location.pathname;
        },
        scope: 'console',
        project: true
    })
    .add('/console/notifications', {
        template: '/console/notifications',
        scope: 'console'
    })
    .add('/console/settings', {
        template: '/console/settings',
        scope: 'console',
        project: true
    })
    .add('/console/settings/:tab', {
        template: '/console/settings',
        scope: 'console',
        project: true
    })
    .add('/console/database', {
        template: '/console/database',
        scope: 'console',
        project: true
    })
    .add('/console/database/:tab', {
        template: '/console/database',
        scope: 'console',
        project: true
    })
    .add('/console/storage', {
        template: '/console/storage',
        scope: 'console',
        project: true
    })
    .add('/console/storage/:tab', {
        template: '/console/storage',
        scope: 'console',
        project: true
    })
    .add('/console/users', {
        template: '/console/users',
        scope: 'console',
        project: true
    })
    .add('/console/users/view', {
        template: '/console/users/view',
        scope: 'console',
        project: true
    })
    .add('/console/users/view/:tab', {
        template: '/console/users/view',
        scope: 'console',
        project: true
    })
    .add('/console/users/:tab', {
        template: '/console/users',
        scope: 'console',
        project: true
    })
;

// Filters

let filter      = window.Litespeed.container.get('filter');
let date        = window.Litespeed.container.get('date');
let timezone    = window.Litespeed.container.get('timezone');
let markdown    = window.Litespeed.container.get('markdown');

filter.add('lowerCase', function (value) {
    return value.toLowerCase();
});

filter.add('date', function (value, options) {
    return date.format('Y-m-d', value);
});

filter.add('date-time', function (value, options) {
    return date.format('Y-m-d H:i', value);
});

filter.add('date-text', function (value, options) {
    return date.format('d M Y', value);
});

filter.add('date-long', function (value, options) {
    return date.format('l, j F, H:i', value);
});

filter.add('min2hum', function (value, options) {

    if(value >= 60) {
        if(value % 60 === 0) {
            return Math.ceil(value / 60) + ' hours';
        }
        else {
            return Math.ceil(value / 60) + ' hours and ' + (value % 60) + ' minutes';
        }
    }

    return value + ' minutes';
});

filter.add('ms2hum', function (value, options) {
    let temp = value;
    const years = Math.floor( temp / 31536000 ),
        days = Math.floor( ( temp %= 31536000 ) / 86400 ),
        hours = Math.floor( ( temp %= 86400 ) / 3600 ),
        minutes = Math.floor( ( temp %= 3600 ) / 60 ),
        seconds = temp % 60;

    if ( days || hours || seconds || minutes ) {
        return ( years ? years + "y " : "" ) +
            ( days ? days + "d " : "" ) +
            ( hours ? hours + "h " : ""  ) +
            ( minutes ? minutes + "m " : "" ) +
            Number.parseFloat( seconds ).toFixed(0) + "s";
    }

    return "< 1s";
});

filter.add('nl2p', function (value, options) {
    let result = "<p>" + value + "</p>";
    result = result.replace(/\r\n\r\n/g, "</p><p>").replace(/\n\n/g, "</p><p>");
    result = result.replace(/\r\n/g, "<br />").replace(/\n/g, "<br />");

    return result;
});

filter.add('markdown', function (value, options) {
    return markdown.render(value);
});

filter.add('id2name', function (value, options) {
    let members = container.get('members');

    if(members === null) {
        return '';
    }

    for (let y = 0; y < members.length; y++) {
        if(members[y]['$uid'] === value) {
            value = members[y].name;
        }
    }

    return value;
});

filter.add('id2role', function (value, options) {
    if(APP_ENV.ROLES[value]) {
        return APP_ENV.ROLES[value];
    }

    return '';
});

filter.add('humanFileSize', function (bytes) {
    if(!bytes) {
        return 0;
    }

    let thresh = 1000;

    if(Math.abs(bytes) < thresh) {
        return bytes + ' B';
    }

    let units = ['kB','MB','GB','TB','PB','EB','ZB','YB'];
    let u = -1;

    do {
        bytes /= thresh;
        ++u;
    } while(Math.abs(bytes) >= thresh && u < units.length - 1);

    return bytes.toFixed(1) + '<span class="text-size-small unit">' + units[u] + '</span>';
});

filter.add('statsTotal', function (value) {
    if(!value) {
        return 0;
    }

    value = abbreviate(value, 1, false, false);

    return (value === '0') ? 'N/A' : value;
});

function abbreviate(number, maxPlaces, forcePlaces, forceLetter) {
    number = Number(number);
    forceLetter = forceLetter || false;
    if(forceLetter !== false) {
        return annotate(number, maxPlaces, forcePlaces, forceLetter);
    }
    let abbr;
    if(number >= 1e12) {
        abbr = 'T';
    }
    else if(number >= 1e9) {
        abbr = 'B';
    }
    else if(number >= 1e6) {
        abbr = 'M';
    }
    else if(number >= 1e3) {
        abbr = 'K';
    }
    else {
        abbr = '';
    }
    return annotate(number, maxPlaces, forcePlaces, abbr);
}

function annotate(number, maxPlaces, forcePlaces, abbr) {
    // set places to false to not round
    let rounded = 0;
    switch(abbr) {
        case 'T':
            rounded = number / 1e12;
            break;
        case 'B':
            rounded = number / 1e9;
            break;
        case 'M':
            rounded = number / 1e6;
            break;
        case 'K':
            rounded = number / 1e3;
            break;
        case '':
            rounded = number;
            break
    }
    if(maxPlaces !== false) {
        let test = new RegExp('\\.\\d{' + (maxPlaces + 1) + ',}$')
        if(test.test(('' + rounded))) {
            rounded = rounded.toFixed(maxPlaces)
        }
    }
    if(forcePlaces !== false) {
        rounded = Number(rounded).toFixed(forcePlaces)
    }
    return rounded + abbr
}

// Views

window.Litespeed.container.get('view')
    .add({
        selector: 'data-acl',
        controller: function(element, document, state, alerts) {
            document.body.classList.remove('console');
            document.body.classList.remove('home');
            document.body.classList.add(state.getCurrent().view.scope);

            if(!state.getCurrent().view.project) {
                document.body.classList.add('hide-nav');
                document.body.classList.remove('show-nav');
            }
            else {
                document.body.classList.add('show-nav');
                document.body.classList.remove('hide-nav');
            }

            // Special case for console index page

            if('/console' === state.getCurrent().path) {
                document.body.classList.add('index');
            }
            else {
                document.body.classList.remove('index');
            }
        }
    })
    .add({
        selector: 'data-cookie-policy',
        repeat: false,
        controller: function(element, alerts, cookie) {
            if(!cookie.get('cp-cookie-alert')) {
                let text = element.dataset['cookiePolicy'] || '';

                alerts.send({text: text, class: 'cookie-alert', link: '/policy/cookies', remove: function () {
                        cookie.set('cp-cookie-alert', 'true', 365 * 10); // 10 years
                    }}, 0);
            }
        }
    })
    .add({
        selector: 'data-login-fb',
        controller: function(element, console, expression) {
            let success = expression.parse(element.dataset['success'] || '');
            let failure = expression.parse(element.dataset['failure'] || '');
            element.href = console.auth.loginWithFacebook(success, failure);
        }
    })
    .add({
        selector: 'data-login-linkedin',
        controller: function(element, console, expression) {
            let success = expression.parse(element.dataset['success'] || '');
            let failure = expression.parse(element.dataset['failure'] || '');
            element.href = console.auth.loginWithLinkedIn(success, failure);
        }
    })
    .add({
        selector: 'data-login-github',
        controller: function(element, console, expression) {
            let success = expression.parse(element.dataset['success'] || '');
            let failure = expression.parse(element.dataset['failure'] || '');
            element.href = console.auth.loginWithGithub(success, failure);
        }
    })
    .add({
        selector: 'data-billing-invoice-print',
        controller: function(element, expression, sdk) {
            let id = expression.parse(element.dataset['billingInvoicePrint'] || '');
            element.href = sdk.billing.invoices.getForPrint(id);
        }
    })
    .add({
        selector: 'data-billing-invoice-download',
        controller: function(element, expression, sdk) {
            let id = expression.parse(element.dataset['billingInvoiceDownload'] || '');
            element.href = sdk.billing.invoices.getForDownload(id);
        }
    })
    .add({
        selector: 'data-auto-cc-master',
        controller: function(element, expression, document) {
            let price = parseInt(expression.parse(element.dataset['autoCcMaster'] || '0'));
            let check = function () {
                if(element.checked && 0 === price) {
                    document.body.classList.add('free-plan');

                    document.dispatchEvent(new CustomEvent('set-free-plan', {
                        bubbles: false,
                        cancelable: true
                    }));
                }
                else if(element.checked) {
                    document.body.classList.remove('free-plan');

                    document.dispatchEvent(new CustomEvent('unset-free-plan', {
                        bubbles: false,
                        cancelable: true
                    }));
                }
            };

            element.addEventListener('change', check);

            check();
        }
    })
    .add({
        selector: 'data-auto-cc-slave',
        controller: function(element) {
            document.addEventListener('set-free-plan', function () {
                element.checked = true;
            });

            document.addEventListener('unset-free-plan', function () {
                element.checked = false;
            });
        }
    })
    .add({
        selector: 'data-ls-ui-alerts',
        controller: function(element, window, view) {
            window.document.addEventListener('alerted', function() {
                view.render(element);
            }, true);
        }
    })
    .add({
        selector: 'data-ls-ui-alerts-delete',
        controller: function(document, element, alerts, expression) {
            let message = expression.parse(element.dataset['message']);

            let remove = function () {
                alerts.remove(message);
            };

            element.addEventListener('click', remove);
        }
    })
    .add({
        selector: 'data-ls-ui-chart-line',
        repeat: true,
        controller: function(element, document, expression) {
            new Chartist.Line(element, {
                labels: ['16.05', '17.05', '18.05', '19.05', '20.05'],
                series: [
                    [12, 9, 7, 8, 5],
                    [2, 1, 3.5, 7, 3],
                    [1, 3, 4, 5, 6],
                    [2, 6, 10, 5, 9],
                    [3, 1, 1, 8, 3]
                ]
            }, {
                height: '300px',
                width: '100%',
                fullWidth: true,
                showArea: true,
                //showPoint: false,
                chartPadding: {
                    right: 30
                }
            });

        }
    })
    .add({
        selector: 'data-ls-ui-chart-pie',
        repeat: true,
        controller: function(element, document, expression) {
            new Chartist.Pie(element, {
                series: [20, 10, 30, 40]
            }, {
                donut: true,
                donutSolid: true,
                showLabel: true
            });

        }
    })
    .add({
        selector: 'data-forms-headers',
        repeat: false,
        controller: function(element) {
            let key      = document.createElement('input');
            let value    = document.createElement('input');
            let wrap     = document.createElement('div');
            let cell1     = document.createElement('div');
            let cell2     = document.createElement('div');

            key.type = 'text';
            key.className = 'margin-bottom-no';
            key.placeholder = 'Key';
            value.type = 'text';
            value.className = 'margin-bottom-no';
            value.placeholder = 'Value';

            wrap.className = 'row thin margin-bottom-small';
            cell1.className = 'col span-6';
            cell2.className = 'col span-6';

            element.parentNode.insertBefore(wrap, element);
            cell1.appendChild(key);
            cell2.appendChild(value);
            wrap.appendChild(cell1);
            wrap.appendChild(cell2);

            key.addEventListener('input', function () {
                syncA();
            });

            value.addEventListener('input', function () {
                syncA();
            });

            element.addEventListener('change', function () {
                syncB();
            });

            let syncA = function () {
                element.value = key.value.toLowerCase() + ':' + value.value.toLowerCase();
            };

            let syncB = function () {
                let split = element.value.toLowerCase().split(':');
                key.value = split[0] || '';
                value.value = split[1] || '';

                key.value = key.value.trim();
                value.value = value.value.trim();
            };

            syncB();
        }
    })
    .add({
        selector: 'data-prism',
        repeat: false,
        controller: function(window, document, element, alerts) {
            Prism.highlightElement(element);

            let copy = document.createElement('i');

            copy.className = 'icon-docs copy';
            copy.title = 'Copy to Clipboard';

            copy.addEventListener('click', function () {
                window.getSelection().removeAllRanges();

                let range = document.createRange();

                range.selectNode(element);

                window.getSelection().addRange(range);

                try {
                    document.execCommand('copy');
                    alerts.send({text: 'Copied to clipboard', class: ''}, 3000);
                } catch (err) {
                    alerts.send({text: "Failed to copy text ", class: 'error'}, 3000);
                }

                window.getSelection().removeAllRanges();
            });

            element.parentNode.parentNode.appendChild(copy);
        }
    })
    .add({
        selector: 'data-code-example',
        repeat: false,
        controller: function(window, document, element, cookie) {
            let prefix = element.dataset['codeExample'] || 'unknown';

            element.addEventListener('change', function () {
                select(element.value);
            });

            let select = function (value) {
                for (let i=0; i< element.length; i++){
                    document.body.classList.remove(prefix + '-' + element.options[i].value);
                }

                document.body.classList.add(prefix + '-' + value);

                cookie.set('language-' + prefix, value, 365);

                document.dispatchEvent(new CustomEvent('updated-language-' + prefix));
            };

            document.addEventListener('updated-language-' + prefix, function () {
                element.value = cookie.get('language-' + prefix);
            });

            let def = cookie.get('language-' + prefix) || element.options[0].value;

            select(def);

            element.value = def;
        }
    })
    .add({
        selector: 'data-ls-ui-chart',
        repeat: false,
        controller: function(element, container, date, document) {
            let child = document.createElement('canvas');

            child.width = 500;
            child.height = 175;

            let stats = container.get('usage');

            if(!stats || !stats['requests'] || !stats['requests']['data']) {
                return;
            }

            let config = {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Requests',
                        backgroundColor: 'rgba(230, 248, 253, 0.3)',
                        borderColor: '#29b5d9',
                        borderWidth: 2,
                        data: [
                            0,
                            0,
                            0,
                            0,
                            0,
                            0,
                            0
                        ],
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    title: {
                        display: false,
                        text: 'Stats'
                    },
                    legend: {
                        display: false
                    },
                    tooltips: {
                        mode: 'index',
                        intersect: false,
                        caretPadding: 0
                    },
                    hover: {
                        mode: 'nearest',
                        intersect: true
                    },
                    scales: {
                        xAxes: [{
                            display: false
                        }],
                        yAxes: [{
                            display: false,
                        }]
                    }
                }
            };

            for (let i = 0; i < stats['requests']['data'].length; i++) {
                config.data.datasets[0].data[i] = stats['requests']['data'][i].value;
                config.data.labels[i] = date.format('d F Y', stats['requests']['data'][i].date);
            }

            let chart = container.get('chart');

            if(chart) {
                //if(JSON.stringify(chart.data.datasets[0].data) === JSON.stringify(config.data.datasets[0].data) && element.dataset['canvas']) {
                //    return;
                //}

                //chart.destroy();
            }

            element.innerHTML = '';

            element.appendChild(child);

            container.set('chart', new Chart(child.getContext('2d'), config), true);

            element.dataset['canvas'] = true;
        }
    })
;