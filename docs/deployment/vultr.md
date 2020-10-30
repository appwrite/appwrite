##  DEPLOY APPWRITE BACKEND SERVER ON VULTR CLOUD

### PREREQUISITES

- [Vultr](https://vultr.com) account with valid billing and/or balance.

### DEPLOYMENT

- Login to dashboard, Go to *Products* section and select specs and region of your choice (Make sure your VPS has atleast 1 IPv4)
- You can select *Additional Features* according to your requirements.
- Open new tab, Navigate to *Firewall* tab, create new rules to allow port **TCP port 80 and 443** for both IPv4 and IPv6. Set source value to *anywhere* for port to be accessible publicly.
- Select the firewall group created in previous step from dropdown while creating server instance.
- Configure your SSH keys, hostname and label and create the instance.
- After instance is running successfully, login and install install [Docker](https://docs.docker.com/engine/install/) for your OS.
- Follow steps at [Appwrite installation guide](https://github.com/appwrite/appwrite#installation).

### TROUBLESHOOT AND REFERENCES

- Make sure docker is installed correclty and your system is up-to-date.
- If your using any external instance for helper service (like redis or MariaDB) then make sure port is allowed in firewall.
- [Vultr docs](https://www.vultr.com/docs/).
- Open [new issue](https://github.com/appwrite/appwrite/issues) or reach out at [discord communtiy](https://appwrite.io/discord) for help.
