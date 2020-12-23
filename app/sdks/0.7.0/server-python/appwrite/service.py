from .client import Client


class Service:
    def __init__(self, client: Client):
        self.client = client
