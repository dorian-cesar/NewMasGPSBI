#!/bin/sh

# ==============================================================================
# LPFPullman - Script de Ejecución Diaria de Sincronizaciones (AWS LAMP)
# Compatible con sh, dash y bash
# ==============================================================================

# Obtener la ruta base del directorio donde se encuentra este script
BASE_DIR="$(cd "$(dirname "$0")" && pwd)"

# Buscar el ejecutable de PHP en el sistema Linux
if command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
elif [ -f "/usr/bin/php" ]; then
    PHP_BIN="/usr/bin/php"
elif [ -f "/usr/local/bin/php" ]; then
    PHP_BIN="/usr/local/bin/php"
else
    PHP_BIN="php"
fi

# Directorio y archivo de Logs
LOG_DIR="$BASE_DIR/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/cron_sync_$(date +'%Y%m%d').log"

echo "======================================================================" >> "$LOG_FILE"
echo " [AWS LAMP] Inicio de Sincronizaciones Diarias: $(date)" >> "$LOG_FILE"
echo "======================================================================" >> "$LOG_FILE"

# Lista de scripts a ejecutar en orden
SCRIPTS="sync_db.php sync_km.php sync_manejo_brusco.php sync_overspeed.php sync_ralenti.php sync_speed.php"

TOTAL=6
COUNT=1

for SCRIPT in $SCRIPTS; do
    SCRIPT_PATH="$BASE_DIR/$SCRIPT"
    
    echo "" >> "$LOG_FILE"
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] -> Ejecutando ($COUNT/$TOTAL): $SCRIPT" >> "$LOG_FILE"
    
    if [ -f "$SCRIPT_PATH" ]; then
        "$PHP_BIN" "$SCRIPT_PATH" >> "$LOG_FILE" 2>&1
    else
        echo "[ERROR] El archivo no existe: $SCRIPT_PATH" >> "$LOG_FILE"
    fi
    
    # Si no es el último script, pausar 1 hora (3600 segundos)
    if [ "$COUNT" -lt "$TOTAL" ]; then
        echo "[$(date +'%Y-%m-%d %H:%M:%S')] Pausa de 1 hora (3600s) antes del siguiente script..." >> "$LOG_FILE"
        sleep 3600
    fi
    
    COUNT=$((COUNT + 1))
done

echo "" >> "$LOG_FILE"
echo "======================================================================" >> "$LOG_FILE"
echo " [AWS LAMP] Fin de Sincronizaciones Diarias: $(date)" >> "$LOG_FILE"
echo "======================================================================" >> "$LOG_FILE"
