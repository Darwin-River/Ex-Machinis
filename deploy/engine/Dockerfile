# The game engine, the events engine and the scheduler share this image.
# Build from the repository root: docker build -f deploy/engine/Dockerfile .

FROM ubuntu:24.04 AS build
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends gcc libc6-dev make libconfig-dev libmysqlclient-dev \
 && rm -rf /var/lib/apt/lists/*
COPY game-engine/src /src
WORKDIR /src
# Builds bin/game-engine, bin/events-engine and bin/eforth.blk (the FORTH
# image each drone's VM starts from).
RUN make clean all

FROM ubuntu:24.04
ARG DEBIAN_FRONTEND=noninteractive
# msmtp-mta provides the sendmail command email.sh uses, relaying to the
# mail container.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libconfig9 libmysqlclient21 msmtp-mta ca-certificates curl procps \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --create-home --home-dir /opt/exmachinis forth

ENV PLAT_HOME=/opt/exmachinis
WORKDIR /opt/exmachinis
COPY --from=build /src/bin/ bin/
COPY game-engine/conf conf/
COPY game-engine/scripts/email bin/scripts/email/
COPY deploy/engine/msmtprc /etc/msmtprc
COPY deploy/engine/run-engine.sh deploy/engine/scheduler.sh bin/
RUN chmod +x bin/scripts/email/*.sh && mkdir -p log tmp && chown -R forth:forth log tmp

# Paths inside this image; DB and mail settings come from docker-compose.yml.
# Any conf/*.conf parameter can be overridden by an environment variable.
ENV SEND_EMAIL_SCRIPT=/opt/exmachinis/bin/scripts/email/email.sh \
    FORTH_IMAGE_PATH=/opt/exmachinis/bin/eforth.blk \
    FORTH_VM_OUT_PATH=/opt/exmachinis/tmp

USER forth
