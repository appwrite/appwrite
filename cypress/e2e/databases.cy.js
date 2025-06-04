
describe('Pruebas de base de datos en Appwrite', () => {
    const baseUrl = 'http://localhost:80';
    const email = 'admin@example.com';
    const password = 'password123';



    it('Debe iniciar sesión en Appwrite Console', () => {
        cy.visit('/console/login');

        cy.get('#email').type('admin@example.com');
        cy.get('#password').type('password123');
        cy.get('button').contains('Sign in').click();
        cy.wait(2000);


    });


    it('Debe crear un nuevo proyecto', () => {
        cy.visit('/console/login');

        cy.get('#email').type('admin@example.com');
        cy.get('#password').type('password123');
        cy.get('button').contains('Sign in').click();

        cy.contains('Create project').click();

        cy.get('input[name="name"]').clear().type('Proyecto Cypress');


        cy.get('button[type="submit"]').click();

        cy.contains('Proyecto Cypress').should('exist');
    });

    it('Debe crear una base de datos', () => {
        cy.visit('/console/login');

        cy.get('#email').type('admin@example.com');
        cy.get('#password').type('password123');
        cy.get('button').contains('Sign in').click();

        cy.contains('Create project').click();
        cy.get('input[name="name"]').clear().type('Proyecto Cypress');
        cy.get('button[type="submit"]').click();
        cy.contains('Proyecto Cypress').should('exist');


        cy.get('button.sideNavToggle').click();

        cy.wait(2000);

        cy.contains('Databases').click();
        cy.wait(2000);


        cy.contains('Create database').click();

        cy.get('input[name="name"]').type('Test Database');

        cy.get('button[type="submit"]').click();

        cy.contains('Test Database').should('exist');
        cy.url().should('include', '/databases/');
    });


    it('Debe crear una colección en la base de datos', () => {

        cy.visit('/console/login');

        cy.get('#email').type('admin@example.com');
        cy.get('#password').type('password123');
        cy.get('button').contains('Sign in').click();

        cy.contains('Create project').click();
        cy.get('input[name="name"]').clear().type('Proyecto Cypress');
        cy.get('button[type="submit"]').click();
        cy.contains('Proyecto Cypress').should('exist');


        cy.get('button.sideNavToggle').click();

        cy.wait(2000);

        cy.contains('Databases').click();
        cy.wait(2000);


        cy.contains('Create database').click();

        cy.get('input[name="name"]').type('Test Database');

        cy.get('button[type="submit"]').click();

        cy.contains('Test Database').should('exist');
        cy.url().should('include', '/databases/');

        cy.wait(1000);

        cy.contains('Create collection').click();
        cy.get('input[name="name"]').clear().type('Test Collection');
        cy.get('button[type="submit"]').click();
        cy.contains('Test Collection').should('exist');


    });


    it('Debe agregar atributos a la colección', () => {

        cy.visit('/console/login');

        cy.get('#email').type('admin@example.com');
        cy.get('#password').type('password123');
        cy.get('button').contains('Sign in').click();

        cy.contains('Create project').click();
        cy.get('input[name="name"]').clear().type('Proyecto Cypress');
        cy.get('button[type="submit"]').click();
        cy.contains('Proyecto Cypress').should('exist');


        cy.get('button.sideNavToggle').click();

        cy.wait(2000);

        cy.contains('Databases').click();
        cy.wait(2000);


        cy.contains('Create database').click();

        cy.get('input[name="name"]').type('Test Database');

        cy.get('button[type="submit"]').click();

        cy.contains('Test Database').should('exist');
        cy.url().should('include', '/databases/');

        cy.wait(1000);

        cy.contains('Create collection').click();
        cy.get('input[name="name"]').clear().type('Test Collection');
        cy.get('button[type="submit"]').click();
        cy.contains('Test Collection').should('exist');
        cy.contains('Create attribute').click();

        cy.contains('String').click();
        cy.get('input[name="key"]').type('testString');
        cy.get('input[name="size"]').type('100');
        cy.get('button[type="submit"]').last().click();





    });


    it('Debe editar un atributo de la colección', () => {

        cy.visit('/console/login');

        cy.get('#email').type('admin@example.com');
        cy.get('#password').type('password123');
        cy.get('button').contains('Sign in').click();

        cy.contains('Create project').click();
        cy.get('input[name="name"]').clear().type('Proyecto Cypress');
        cy.get('button[type="submit"]').click();
        cy.contains('Proyecto Cypress').should('exist');


        cy.get('button.sideNavToggle').click();

        cy.wait(2000);

        cy.contains('Databases').click();
        cy.wait(2000);


        cy.contains('Create database').click();

        cy.get('input[name="name"]').type('Test Database');

        cy.get('button[type="submit"]').click();

        cy.contains('Test Database').should('exist');
        cy.url().should('include', '/databases/');

        cy.wait(1000);

        cy.contains('Create collection').click();
        cy.get('input[name="name"]').clear().type('Test Collection');
        cy.get('button[type="submit"]').click();
        cy.contains('Test Collection').should('exist');
        cy.contains('Create attribute').click();

        cy.contains('String').click();
        cy.get('input[name="key"]').type('testString');
        cy.get('input[name="size"]').type('100');
        cy.get('button[type="submit"]').last().click();
        cy.wait(1000);
        cy.get('button[aria-label="more options"]').click();
        cy.contains('Update').click();
        cy.get('input[name="key"]').clear().type('testStringUpdated');
        cy.get('button[type="submit"]').last().click();

    });

});
