(function (window) {
    "use strict";

    window.ls.container.set('rtl', function () {
        var rtlStock = "^ا^ب^ت^ث^ج^ح^خ^د^ذ^ر^ز^س^ش^ص^ض^ط^ظ^ع^غ^ف^ق^ك^ل^م^ن^ه^و^ي^א^ב^ג^ד^ה^ו^ז^ח^ט^י^כ^ך^ל^מ^ם^נ^ן^ס^ע^פ^ף^צ^ץ^ק^ר^ש^ת^";
        var special = ["\n", " ", " ", "״", '"', "_", "'", "!", "@", "#", "$", "^", "&", "%", "*", "(", ")", "+", "=", "-", "[", "]", "\\", "/", "{", "}", "|", ":", "<", ">", "?", ",", ".", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];

        var isRTL = function(value) {
            for (var i = 0; i < value.length; i++) {
                if(/\s/g.test(value[i])) {
                    continue;
                }

                if (-1 === special.indexOf(value[i])) {
                    var firstChar = value[i];
                    break;
                }
            }

            if (-1 < rtlStock.indexOf("^" + firstChar + "^")) {
                return true;
            }

            return false;
        };
        return {
            isRTL: isRTL,
        };
    }, true);

})(window);