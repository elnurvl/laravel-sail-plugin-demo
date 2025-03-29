FROM quay.io/keycloak/keycloak:25.0.1 AS builder

WORKDIR /opt/keycloak

ADD --chown=keycloak:keycloak --chmod=644 https://github.com/leroyguillaume/keycloak-bcrypt/releases/download/v1.6.0/keycloak-bcrypt-1.6.0.jar /opt/keycloak/providers/keycloak-bcrypt-1.6.0.jar

RUN /opt/keycloak/bin/kc.sh build

FROM quay.io/keycloak/keycloak:latest
COPY --from=builder /opt/keycloak/ /opt/keycloak/

WORKDIR /opt/keycloak

ENTRYPOINT ["/opt/keycloak/bin/kc.sh"]
