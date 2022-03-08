FROM gitpod/workspace-full

RUN curl -fsSL https://deno.land/x/install/install.sh | sh
RUN /home/gitpod/.deno/bin/deno completions bash > /home/gitpod/.bashrc.d/90-deno &&     echo 'export DENO_INSTALL="/home/gitpod/.deno"' >> /home/gitpod/.bashrc.d/90-deno &&     echo 'export PATH="$DENO_INSTALL/bin:$PATH"' >> /home/gitpod/.bashrc.d/90-deno

RUN sudo a2dismod php7.4
RUN sudo a2dismod mpm_prefork
RUN sudo apt --yes -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" install apache2
RUN sudo apt --yes install software-properties-common && sudo add-apt-repository ppa:ondrej/php -y
RUN sudo apt update
RUN sudo apt --yes -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" install php8.0
