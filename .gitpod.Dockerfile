FROM gitpod/workspace-full

RUN sudo apt update
RUN sudo add-apt-repository ppa:ondrej/php -y
# Disable current PHP installation
RUN sudo a2dismod php7.4 mpm_prefork

# Install apache2 (PHP install requires to do this first) and php8.0
RUN sudo install-packages \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    apache2 php8.0

