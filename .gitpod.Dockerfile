FROM gitpod/workspace-full

# Disable current PHP installation
RUN sudo a2dismod php7.4
RUN sudo a2dismod mpm_prefork

# Install apache2 (PHP install requires to do this first)
RUN sudo apt --yes -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" install apache2

# Update to PHP 8.0 with unattended installation
RUN sudo apt --yes install software-properties-common && sudo add-apt-repository ppa:ondrej/php -y
RUN sudo apt update
RUN sudo apt --yes -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" install php8.0
