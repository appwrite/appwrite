(function (window) {
  "use strict";

  //TODO: Make this generic

  window.ls.container.get("view").add({
    selector: "data-forms-oauth-microsoft",
    controller: function (element) {
      // element contains the final secret

      // Get all custom input fields by their ID
      let clientSecret = document.getElementById("oauth2MicrosoftClientSecret");
      let tenantId = document.getElementById("oauth2MicrosoftTenantId");

      // Add Change Listeners for element and all custom input fields

      element.addEventListener('change', sync);
      clientSecret.addEventListener('change', update);
      tenantId.addEventListener('change', update);

      // Build the JSON based on input in custom input fields
      function update() {
        let json = {};

        json.clientSecret = clientSecret.value;
        json.tenantId = tenantId.value;

        element.value = JSON.stringify(json);
      }

      function sync() {
        if (!element.value) {
          return;
        }

        let json = {};

        try {
          json = JSON.parse(element.value);
        } catch (error) {
          console.error('Failed to parse secret key');
        }

        clientSecret.value = json.clientSecret || '';
        tenantId.value = json.tenantId || '';
      }
      sync();
    }
  });
})(window);
