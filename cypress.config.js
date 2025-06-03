const { defineConfig } = require("cypress");

module.exports = defineConfig({
  e2e: {
    baseUrl: 'http://localhost', // Ajusta el puerto si Appwrite está en otro (ej: http://localhost:80 o :8080)
    specPattern: 'cypress/e2e/**/*.cy.{js,jsx,ts,tsx}',

    experimentalStudio: true,
    

    setupNodeEvents(on, config) {
      // puedes dejar esto así si no tienes eventos por ahora
    },
  },
});
