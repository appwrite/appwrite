(function(window) {
    "use strict";
    
    window.ls.view.add({
        selector: 'data-general-copy',
        repeat: false,
        controller: function(document, element, alerts) {
        let button = document.createElement("i");

            button.type = "button";
            button.title = "Copy to Clipboard";
            button.className = element.getAttribute("data-class") || "icon-docs note copy";
            button.style.cursor = "pointer";

            element.parentNode.insertBefore(button, element.nextSibling);

            let copy = function(event) {

                window.getSelection().removeAllRanges();
                let range = document.createRange();
                range.selectNode(element);
                window.getSelection().addRange(range);
                try {
                    document.execCommand("copy");
                    alerts.add({
                        text: "Copied to clipboard",
                        class: ""
                    }, 3000);
                } catch (err) {
                    alerts.add({
                        text: "Failed to copy text ",
                        class: "error"
                    }, 3000);
                }

                window.getSelection().removeAllRanges();
            };

            button.addEventListener("click", copy);
        }
    });

})(window);
    