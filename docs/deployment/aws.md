## Deploy appwrite with AWS (Linux)

1. SSH into your ec2 instance. If you do not have an ec2 instance, you can follow the offical [documentation](https://docs.aws.amazon.com/efs/latest/ug/gs-step-one-create-ec2-resources.html).  
2. Install [docker](https://www.digitalocean.com/community/tutorials/how-to-install-and-use-docker-on-ubuntu-18-04) and [docker-compose](https://www.digitalocean.com/community/tutorials/how-to-install-and-use-docker-compose-on-ubuntu-20-04) on the instance. For an existing instance, make sure the index at port 80 and port 443 are not in use.
3. Confirm docker and docker-compose have been successfully installed by running `docker --version` and `docker-compose --version`. 
4. Run `sudo chmod 666 /var/run/docker.sock`.
5. Run to install appwrite with docker
```
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/install/appwrite:rw \
    -e version=0.6.2 \
    appwrite/install
```
6. Follow the prompts.
7. After appwrite has successfully installed, copy your public IP and enter it in the browser. You should see the appwrite homepage.

