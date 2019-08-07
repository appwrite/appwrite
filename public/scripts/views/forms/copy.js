(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-copy',
            controller: function(element, alerts, document, window) {
                var button      = window.document.createElement('i');

                button.type = 'button';
                button.className = 'icon-docs note copy';
                button.style.cursor = 'pointer';

                element.parentNode.insertBefore(button, element.nextSibling);

                var copy = function(event) {
                    window.getSelection().removeAllRanges();

                    var range = document.createRange();

                    range.selectNode(element);

                    window.getSelection().addRange(range);

                    try {
                        document.execCommand('copy');
                        alerts.add({text: 'Copied to clipboard', class: ''}, 3000);
                    } catch (err) {
                        alerts.add({text: "Failed to copy text ", class: 'error'}, 3000);
                    }

                    window.getSelection().removeAllRanges();
                };

                button.addEventListener('click', copy);
            }
        }
    );

})(window);