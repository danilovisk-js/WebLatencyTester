#!/usr/bin/env bash

# Identificação da pessoa que está testando
PESSOA="danilo"
CIDADE="sp"
OPERADORA="claro"

# Lista de URLs a serem monitoradas
FQDNS=(
    "https://www.itau.com.br"
    "https://www.bradesco.com.br"
    "https://www.santander.com.br"
    "https://www.bb.com.br"
)

LOG_DIR="/mnt/c/WAF-test/logs"
INTERVALO=10

mkdir -p "$LOG_DIR"

while true; do
    TIMESTAMP=$(date +"%Y-%m-%d %H:%M:%S")

    for URL in "${FQDNS[@]}"; do
        HOST=$(echo "$URL" | awk -F/ '{print $3}' | tr '.' '_')
        ARQUIVO_SAIDA="$LOG_DIR/tempo_resposta_${HOST}__${PESSOA}_${CIDADE}_${OPERADORA}.log"

        METRICAS=$(curl -o /dev/null -s -w "%{time_namelookup} %{time_connect} %{time_pretransfer} %{time_starttransfer} %{time_total}" "$URL")
        echo "$TIMESTAMP $METRICAS" >> "$ARQUIVO_SAIDA"
    done

    sleep "$INTERVALO"
done

