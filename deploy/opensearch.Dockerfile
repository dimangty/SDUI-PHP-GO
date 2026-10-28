# Build context: repository root. Keep the official distribution and security/TLS.
FROM opensearchproject/opensearch:2.19.7@sha256:3bfa1ec74d56928c2e018033d45c460ce2c0ea65a5cedd577de3102326c31b89

# The application uses REST document indexing and Query DSL, not these optional
# analytics/ML/SQL plugins. Remove their bundled libraries with the vendor CLI;
# dependent plugins precede their dependencies. Heap limits apply only to the CLI.
# See docs/backend-fixes/JAVA_RUNTIME_REMEDIATION.md for findings still in core.
RUN set -eu; \
    export OPENSEARCH_JAVA_OPTS="-Xms32m -Xmx128m -XX:+UseSerialGC -XX:ActiveProcessorCount=2"; \
    for plugin in \
        opensearch-performance-analyzer \
        opensearch-skills \
        opensearch-flow-framework \
        opensearch-neural-search \
        opensearch-ml \
        opensearch-security-analytics \
        opensearch-anomaly-detection \
        opensearch-observability \
        opensearch-reports-scheduler \
        opensearch-notifications \
        opensearch-notifications-core \
        opensearch-sql; \
    do bin/opensearch-plugin remove --purge "$plugin"; done

# Official OpenJDK fallback for the compromised eMudhra intermediates:
# https://openjdk.org/emudhra . The pinned Temurin store contains these two
# affected/recommended roots; the other two listed roots are already absent.
# Fail if the pinned store changes rather than ignoring keytool errors. Keep
# the vendor JDK/libraries and the explicitly configured private TLS CA intact.
# Original public truststore is recoverable from the immutable upstream image.
RUN set -eu; \
    for alias in \
        'cn_emsign_ecc_root_ca__g3,o_emudhra_technologies_limited,ou_emsign_pki,c_in [jdk]' \
        'cn_emsign_root_ca__g1,o_emudhra_technologies_limited,ou_emsign_pki,c_in [jdk]'; \
    do jdk/bin/keytool -J-Xmx64m -J-XX:ActiveProcessorCount=2 \
        -delete -cacerts -storepass changeit -alias "$alias"; done
