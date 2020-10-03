# How to deploy appwrite into DigitalOcean

## Prequisites

- OS 
- Terminal 

## Getting started

First of all you should get a DigitalOcean account, if you already got an account let's continue to setup our appwrite server.

## Step 1 - Create a Droplet

What is a Droplet? DigitalOcean Droplet are Linux-based virtual machines (VMs) that run on top of virtualized hardware. Each Droplet you create is a new server you can use, either standalone or as part of a larger, cloud-based infrastructure.
 This droplet then can be used to run our appwrite server

After you create an account or login you will get a project in the homepage like this:

![image](https://imgur.com/download/ZQ2FUjX)

my project name is panda you can change the name of your project to whatever you want it doesn't matter
the most important thing right now is you can see in the middle of your screen there is a button that say "Get started with a Droplet"
like this :

![image](https://imgur.com/download/f9DsKAw)

click that button and you will go to a new page like this:

![image](https://imgur.com/download/XVRkq8C)

The minimum requirements to run Appwrite is as little as 1 CPU core and 2GB of RAM, and an operating system that supports Docker.
 We will use the default one using ubuntu 20.04 and RAM 2GB

 ![image](https://imgur.com/download/4bqbGYg)
 
 scroll down into password section you can choose either using ssh keys or password we gonna use ssh keys because it's much more secure:

![image](https://imgur.com/download/IpDbMmJ)

click "new ssh key" and then you will get this window:

![image](https://imgur.com/download/cUNWz5w)

there is a detailed explanation on how to make ssh key on the right hand side of the window follow that instruction and then copy paste
your ssh public key into the box

![image](https://imgur.com/download/qbMltoP)

copy paste the ssh key you get from before like this:

![image](https://imgur.com/download/Mpl0SfK)

give it a name and click add SSH key now you're done set up your ssh keys scroll down until you see button "Create Droplet" like below:

![image](https://imgur.com/download/FWVwhGH)

ok now you're done creating your first Droplet you will see in your homepage something like this (i blur the image because it contain sensitive information):

![image](https://imgur.com/download/b9Sr9mz)

## Step 2 - Run The Appwrite server

So in order for us to access the ssh that we create earlier we should use Terminal for accessing it so first of all go to your Terminal and type

```bash
ssh root@<ip address> # you can see your ip address in your homepage
```

after you login into your Droplet run this command one by one to install docker:

```bash
sudo apt-get remove docker docker-engine docker.io containerd runc
sudo apt-get update
sudo apt-get install \
    apt-transport-https \
    ca-certificates \
    curl \
    gnupg-agent \
    software-properties-common
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -
sudo apt-key fingerprint 0EBFCD88
sudo add-apt-repository \
   "deb [arch=amd64] https://download.docker.com/linux/ubuntu \
   $(lsb_release -cs) \
   stable"
sudo apt-get update
sudo apt-get install docker-ce docker-ce-cli containerd.io
sudo groupadd docker
sudo usermod -aG docker $USER
newgrp docker 
```
after we install docker we now install docker-compose:

```bash
sudo curl -L "https://github.com/docker/compose/releases/download/1.27.4/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

after that we install appwrite server

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/install/appwrite:rw \
    -e version=0.6.2 \
    appwrite/install
```
![image](https://imgur.com/download/l7R5FZf)

input this according to your need for this tutorial we gonna go with the default

![image](https://imgur.com/download/ecCZ1O5)

after its all done exit your Terminal and go to your browser and type your Droplet ip address it usually take 1 minute or 
less to start your server you will see this in your browser if you successfully run your server

![image](https://imgur.com/download/OTKn3p8)

to get started sign up with your email address and password

![image](https://imgur.com//download/Wva5tOi)

after that create a project

![image](https://imgur.com/download/6LKlQoP)

after that if you go to this screen you are successfully deploying your appwrite server into DigitalOcean

![image](https://imgur.com/download/gaoBGGg)

# What's next?
Congratulations! You've just deploy appwrite into DigitalOcean.

Good luck on your future development using Appwrite! If you need any help, feel free to join the [Discord](https://appwrite.io/discord) or refer to the [Appwrite Documentation](https://appwrite.io/docs). 


