FROM rust:1.70 as builder

WORKDIR /usr/src/rust-db

# Připravíme Cargo.toml
COPY Cargo.toml .
COPY src src/

# Kompilace v release módu
RUN cargo build --release

# Finální image
FROM debian:bullseye-slim

# Instalace potřebných runtime závislostí
RUN apt-get update && apt-get install -y \
    libssl1.1 \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Kopírování pouze zkompilované knihovny
COPY --from=builder /usr/src/rust-db/target/release/librust_db.so /usr/local/lib/

# Vytvoření adresáře pro data
RUN mkdir -p /data/db

# Nastavení práv pro data
RUN chmod 777 /data/db

# Vytvoření jednoduchého entry pointu
COPY --chmod=755 <<-"EOF" /usr/local/bin/db-server
#!/bin/sh
tail -f /dev/null
EOF

# Exponování portu pro případné TCP rozhraní
EXPOSE 6379

# Spuštění "serveru"
CMD ["/usr/local/bin/db-server"]