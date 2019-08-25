(function (window) {
    window.ls.container.get('view').add(
        {
            selector: 'data-ui-modal',
            controller: function(document, element, expression) {
                let name            = element.dataset['name'] || null;
                let buttonText      = expression.parse(element.dataset['buttonText'] || '');
                let buttonClass     = element.dataset['buttonClass'] || 'button-class';
                let buttonIcon      = element.dataset['buttonIcon'] || null;
                let buttonEvent     = element.dataset['buttonEvent'] || '';
                let buttonAlias     = element.dataset['buttonAlias'] || '';
                let buttonElements  = (!buttonAlias) ? [document.createElement('button')] : document.querySelectorAll(buttonAlias);
                let openEvent       = element.dataset['openEvent'] || null; // When event triggers modal will open
                let background      = document.getElementById('modal-bg');

                if(!background) {
                    background = document.createElement('div');
                    background.id = 'modal-bg';
                    background.className = 'modal-bg';

                    document.body.appendChild(background);

                    background.addEventListener('click', function() {
                        document.dispatchEvent(new CustomEvent('modal-close', {
                            bubbles: false,
                            cancelable: true
                        }));
                    });
                }

                if(!buttonAlias) {
                    buttonElements.forEach((button) => {

                        button.innerText = buttonText;
                        button.className = buttonClass;
                        button.type = 'button';

                        if(buttonIcon) {
                            let iconElement = document.createElement('i');
                            iconElement.className  = buttonIcon;

                            button.insertBefore(iconElement, button.firstChild);
                        }
                    });
                }

                if(buttonEvent) {
                    buttonElements.forEach((button) => {
                        button.addEventListener('click', function () {
                            document.dispatchEvent(new CustomEvent(buttonEvent, {
                                bubbles: false,
                                cancelable: true
                            }));
                        });
                    });
                }

                element.classList.add('modal');

                if(!buttonAlias) { // Add to DOM when not alias
                    buttonElements.forEach((button) => {
                        element.parentNode.insertBefore(button, element);
                    });
                }

                let open = function () {
                    document.documentElement.classList.add('modal-open');

                    document.dispatchEvent(new CustomEvent('modal-open', {
                        bubbles: false,
                        cancelable: true
                    }));

                    element.classList.add('open');
                    element.classList.remove('close');
                };

                let close = function () {
                    document.documentElement.classList.remove('modal-open');

                    element.classList.add('close');
                    element.classList.remove('open');
                };

                if(name) {
                    document.querySelectorAll("[data-ui-modal-ref='" + name + "']").forEach(function(elem) {
                        elem.addEventListener('click', open);
                    });
                }

                if(openEvent) {
                    document.addEventListener(openEvent, open);
                }

                buttonElements.forEach((button) => {
                    button.addEventListener('click', open);
                });

                document.addEventListener('keydown', function(event) {
                    if (event.which === 27) {
                        close();
                    }
                });

                element.addEventListener('blur', close);

                let closeButtons = element.querySelectorAll('[data-ui-modal-close]');

                for(let i =0; i < closeButtons.length; i++){
                    closeButtons[i].addEventListener('click', close);
                }

                document.addEventListener('modal-close', close);
                element.addEventListener('submit', close);
            }
        }
    );

})(window);