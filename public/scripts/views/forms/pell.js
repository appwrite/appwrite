(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-pell",
    controller: function(element, window, document, markdown, rtl) {
      var div = document.createElement("div");

      element.className = "pell hide";
      div.className = "input pell";
      element.parentNode.insertBefore(div, element);
      element.tabIndex = -1;

      var turndownService = new TurndownService();

      turndownService.addRule("underline", {
        filter: ["u"],
        replacement: function(content) {
          return "__" + content + "__";
        }
      });

      var editor = window.pell.init({
        element: div,
        onChange: function onChange(html) {
          alignText();
          element.value = turndownService.turndown(html); // Change HTML to Markdown
        },
        defaultParagraphSeparator: "p",
        actions: [
          {
            name: "bold",
            icon: '<i class="icon-bold"></i>'
          },
          {
            name: "underline",
            icon: '<i class="icon-underline"></i>'
          },
          {
            name: "italic",
            icon: '<i class="icon-italic"></i>'
          },
          {
            name: "olist",
            icon: '<i class="icon-list-numbered"></i>'
          },
          {
            name: "ulist",
            icon: '<i class="icon-list-bullet"></i>'
          },
          {
            name: "link",
            icon: '<i class="icon-link"></i>'
          }
        ]
      });

      var clean = function(e) {
        e.stopPropagation();
        e.preventDefault();

        var clipboardData = e.clipboardData || window.clipboardData;
        console.log(clipboardData.getData("Text"));
        window.pell.exec("insertText", clipboardData.getData("Text"));
        return true;
      };

      var alignText = function () {

        let paragraphs = editor.content.querySelectorAll('p,li');
        let last = '';

        for (let paragraph of paragraphs) {
          var content = paragraph.textContent;

          if(content.trim() === '') {
            content = last.textContent;
          }

          if(rtl.isRTL(content)) {
            paragraph.style.direction = 'rtl';
            paragraph.style.textAlign = 'right';
          }
          else {
            paragraph.style.direction = 'ltr';
            paragraph.style.textAlign = 'left';
          }

          last = paragraph;
        }
      };

      var santize = function (e) {
        clean(e);
        alignText(e);
      };

      element.addEventListener("change", function() {
        editor.content.innerHTML = markdown.render(element.value); // Change Markdown to HTML (mainly for editing purpose)
        alignText();
      });

      editor.content.setAttribute("placeholder", element.placeholder);
      editor.content.innerHTML = markdown.render(element.value);
      editor.content.tabIndex = 0;

      alignText();

      editor.content.onkeydown = function preventTab(event) {
        if (event.which === 9) {
          event.preventDefault();

          // Inspired by: https://stackoverflow.com/a/35173443/2299554
          //add all elements we want to include in our selection
          if (document.activeElement) {
            var focussable = Array.prototype.filter.call(
              document.querySelectorAll(
                'a:not([disabled]), button:not([disabled]), select:not([disabled]), input[type=text]:not([disabled]), input[type=checkbox]:not([disabled]), [tabindex]:not([disabled]):not([tabindex="-1"])'
              ),
              function(element) {
                //check for visibility while always include the current activeElement
                return (
                  element.offsetWidth > 0 ||
                  element.offsetHeight > 0 ||
                  element === document.activeElement
                );
              }
            );

            var index = focussable.indexOf(document.activeElement);

            if (index > -1) {
              if (event.shiftKey) {
                // Move up
                var prevElement =
                  focussable[index - 1] || focussable[focussable.length - 1];
                prevElement.focus();
              } else {
                // Move down
                var nextElement = focussable[index + 1] || focussable[0];
                nextElement.focus();
              }
            }
          }
        }
      };

      div.addEventListener("paste", santize);
      div.addEventListener("drop", santize);
    }
  });
})(window);
