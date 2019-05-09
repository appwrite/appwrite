(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-ls-ui-open',
        repeat: false,
        controller: function(element, window) {
            let def         = (element.classList.contains('open')) ? 'open' : 'close';
            let buttonClass = element.dataset['buttonClass'];
            let buttonText  = element.dataset['buttonText'] || '';
            let buttonIcon  = element.dataset['buttonIcon'] || '';
            let hover       = element.hasAttribute('data-hover');
            let button      = window.document.createElement('button');

            let isTouch = function() {
                return 'ontouchstart' in window        // works on most browsers
                    || navigator.maxTouchPoints;       // works on IE10/11 and Surface
            };

            button.innerText = buttonText;
            button.className = buttonClass;
            button.tabIndex = 1;

            if(buttonIcon) {
                let icon = window.document.createElement('i');

                icon.className = buttonIcon;

                button.insertBefore(icon, button.firstChild);
            }

            if(def === 'close') {
                element.classList.add('close');
                element.classList.remove('open');
            }
            else {
                element.classList.add('open');
                element.classList.remove('close');
            }

            button.addEventListener('click', function() {
                element.classList.toggle('open');
                element.classList.toggle('close');
            });

            if(hover && !isTouch()) {
                element.addEventListener('mouseover', function() {
                    element.classList.add('open');
                    element.classList.remove('close');
                });

                element.addEventListener('mouseout', function() {
                    element.classList.add('close');
                    element.classList.remove('open');
                });
            }

            let close = function() {
                element.classList.add('close');
                element.classList.remove('open');
            };

            let closeDelay = function() {
                window.setTimeout(function() {
                    close();
                }, 150);
            };

            let findParent = function(tagName, el) {
                if ((el.nodeName || el.tagName).toLowerCase() === tagName.toLowerCase()){
                    return el;
                }
                while (el = el.parentNode){
                    if ((el.nodeName || el.tagName).toLowerCase() === tagName.toLowerCase()){
                        return el;
                    }
                }
                return null;
            };

            button.addEventListener('blur', closeDelay);
            element.addEventListener('click', function (event) {
                let target = findParent('a', event.target);

                if(!target) {
                    return false; // no target
                }

                if(!target.href) { // Just a normal click not an href
                    return false;
                }

                closeDelay();
            });

            element.insertBefore(button, element.firstChild);
        }
    });
})(window);