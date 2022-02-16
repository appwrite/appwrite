(function (window) {
  "use strict";

  //TODO: Make this generic

  window.ls.container.get("view").add({
    selector: "data-forms-oauth-custom",
    controller: function (element) {
      // provider configuration for custom forms. Keys will be property names in JSON, values the elementIDs for the according inputs
      let providers = {
        "Microsoft": {
          "clientSecret": "oauth2MicrosoftClientSecret",
          "tenantId": "oauth2MicrosoftTenantId"
        },
        "Apple": {
          "keyId": "oauth2AppleKeyId",
          "teamId": "oauth2AppleTeamId",
          "p8": "oauth2AppleP8"
        }
      }
      let provider = element.getAttribute("data-forms-oauth-custom");
      if (!provider || !providers.hasOwnProperty(provider)) { console.error("Provider for custom form not set or unkown") }
      let config = providers[provider];

      // Add Change Listeners for element
      element.addEventListener('change', sync);

      // Get all inputs by id and register change event listener
      let elements = {};
      for (const key in config) {
        if (Object.hasOwnProperty.call(config, key)) {
          elements[key] = document.getElementById(config[key]);
          elements[key].addEventListener('change', update);
        }
      }


      // Build the JSON based on input in custom input fields
      function update() {
        let json = {};
        for (const key in elements) {
          if (Object.hasOwnProperty.call(elements, key)) {
            json[key] = elements[key].value
          }
        }

        element.value = JSON.stringify(json);
      }

      // When the JSON changes (on load) change values in custom input fields
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

        for (const key in elements) {
          if (Object.hasOwnProperty.call(elements, key)) {
            elements[key].value = json[key] || '';
          }
        }
      }
      sync();
    }
  });
})(window);
