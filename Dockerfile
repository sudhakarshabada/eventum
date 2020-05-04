#
# debian Dockerfile


# Pull base image.
FROM debian:stretch

# Install.
RUN \
  apt-get update && \
  apt-get install -y build-essential && \
  apt-get install -y software-properties-common && \
  apt-get install -y apache2 curl git htop man unzip vim wget && \
  apt install php libapache2-mod-php php-mysql
  rm -rf /var/lib/apt/lists/*

#Volume attachement
VOLUME /data

# Add files.
ADD ./ /var/www/html/eventum

# Define working directory.
WORKDIR /var/www/html/eventum

# Define default command.
CMD ["bash"]
