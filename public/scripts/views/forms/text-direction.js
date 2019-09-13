(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-text-direction",
    controller: function(element) {
      var rtlStock =
        "^ا^ب^ت^ث^ج^ح^خ^د^ذ^ر^ز^س^ش^ص^ض^ط^ظ^ع^غ^ف^ق^ك^ل^م^ن^ه^و^ي^א^ב^ג^ד^ה^ו^ז^ח^ט^י^כ^ך^ל^מ^ם^נ^ן^ס^ע^פ^ף^צ^ץ^ק^ר^ש^ת^";
      var special = [
        "\n",
        " ",
        "״",
        '"',
        "_",
        "'",
        "!",
        "@",
        "#",
        "$",
        "^",
        "&",
        "%",
        "*",
        "(",
        ")",
        "+",
        "=",
        "-",
        "[",
        "]",
        "\\",
        "/",
        "{",
        "}",
        "|",
        ":",
        "<",
        ">",
        "?",
        ",",
        ".",
        "0",
        "1",
        "2",
        "3",
        "4",
        "5",
        "6",
        "7",
        "8",
        "9"
      ];

      var setDirection = function() {
        var value = element.value[0] ? element.value : "";
        var direction = "ltr";
        var align = "left";

        for (var i = 0; i < value.length; i++) {
          if (-1 === special.indexOf(value[i])) {
            var firstChar = value[i];
            break;
          }
        }

        if (-1 < rtlStock.indexOf("^" + firstChar + "^")) {
          direction = "rtl";
          align = "right";
        }

        element.style.direction = direction;
        element.style.textAlign = align;
      };

      element.addEventListener("keyup", setDirection);
      element.addEventListener("change", setDirection);
      element.addEventListener("cut", setDirection);
      element.addEventListener("paste", setDirection);
      element.addEventListener("drop", setDirection);

      setDirection();
    }
  });
})(window);
