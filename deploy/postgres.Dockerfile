# Keep PostgreSQL 17 and its official initialization/TLS entrypoint unchanged.
# Only the privilege-drop helper is rebuilt from its released, locked source.
FROM golang:1.26.10-alpine3.24@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 AS gosu-build
WORKDIR /src/gosu
ADD --checksum=sha256:2b70da8a5480fafedc16bb1fc91b572f064d03df736cbd91a616af2f1b66ab51 https://codeload.github.com/tianon/gosu/tar.gz/29b939aa5bd4dc588af765b8248f6e39150d2565 /src/gosu.tar.gz
RUN tar -xzf /src/gosu.tar.gz --strip-components=1 -C /src/gosu \
    && CGO_ENABLED=0 GOTOOLCHAIN=local go build -mod=readonly -trimpath -ldflags='-s -w' -o /out/gosu .

FROM postgres:17.11-alpine3.24@sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b
COPY --from=gosu-build --chmod=0755 /out/gosu /usr/local/bin/gosu
ENV GOSU_VERSION=1.20
RUN gosu --version && gosu postgres sh -ec 'test "$(id -u)" = 70; test "$(id -g)" = 70'
