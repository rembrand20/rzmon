# Officieel multi-arch Alpine image (aarch64, amd64, armv7)
FROM alpine:3.20

RUN apk add --no-cache bash curl jq iproute2 coreutils tzdata openssl

COPY rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
COPY run.sh /run.sh
RUN chmod 755 /usr/local/bin/rzmon-agent.sh /run.sh

CMD ["/run.sh"]
