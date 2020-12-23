package .services;

import .Client;

abstract class Service {
    final Client client;

    Service(Client client) {
        this.client = client;
    }
}
