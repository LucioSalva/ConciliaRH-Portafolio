# Servidor de PRUEBA que simula una instalación real (no es infraestructura del proyecto): Debian 13 + systemd como PID 1.
FROM debian:trixie
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates sudo curl procps tzdata \
    && ln -sf /usr/share/zoneinfo/America/Mexico_City /etc/localtime && echo America/Mexico_City > /etc/timezone
# Paquetes EXACTAMENTE como docs/DEPLOYMENT.md §1
RUN apt-get install -y apache2 php-fpm php-cli php-pgsql php-mbstring php-bcmath php-zip php-intl php-xml php-gd \
      postgresql postgresql-client unzip gnupg \
    && rm -rf /var/lib/apt/lists/*
STOPSIGNAL SIGRTMIN+3
CMD ["/lib/systemd/systemd"]
