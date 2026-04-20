#!/usr/bin/env bash
# Start or stop local llama-server instances for smart-search.
# Logs go to var/log/llama-embed.log and var/log/llama-generate.log.
#
# Usage:
#   ./llama.sh          # start both servers
#   ./llama.sh stop     # stop both servers
#   ./llama.sh status   # show running state

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="${SCRIPT_DIR}/var/log"
PID_DIR="${SCRIPT_DIR}/var/run"

mkdir -p "$LOG_DIR" "$PID_DIR"

PID_EMBED="${PID_DIR}/llama-embed.pid"
PID_GENERATE="${PID_DIR}/llama-generate.pid"

# ── helpers ──────────────────────────────────────────────────────────────────

is_running() {
    local pid_file="$1"
    [[ -f "$pid_file" ]] && kill -0 "$(cat "$pid_file")" 2>/dev/null
}

stop_server() {
    local name="$1" pid_file="$2"
    if is_running "$pid_file"; then
        kill "$(cat "$pid_file")" && rm -f "$pid_file"
        echo "  stopped $name"
    else
        rm -f "$pid_file"
        echo "  $name was not running"
    fi
}

start_server() {
    local name="$1" pid_file="$2" log_file="$3"
    shift 3  # remaining args are the llama-server command

    if is_running "$pid_file"; then
        echo "  $name already running (pid $(cat "$pid_file"))"
        return
    fi

    "$@" >> "$log_file" 2>&1 &
    echo $! > "$pid_file"
    echo "  started $name (pid $!, log: ${log_file#"$SCRIPT_DIR"/})"
}

# ── commands ─────────────────────────────────────────────────────────────────

cmd_start() {
    echo "Starting llama servers..."
    start_server "llama-embed" "$PID_EMBED" "${LOG_DIR}/llama-embed.log" \
        llama-server \
            -hf nomic-ai/nomic-embed-text-v1.5-GGUF \
            --port 8080 \
            --embeddings \
            --pooling mean \
            --ctx-size 2048 \
            --ubatch-size 2048

    start_server "llama-generate" "$PID_GENERATE" "${LOG_DIR}/llama-generate.log" \
        llama-server -hf ggml-org/gemma-3-4b-it-GGUF --port 8081

    echo ""
    echo "Logs:   tail -f var/log/llama-embed.log"
    echo "        tail -f var/log/llama-generate.log"
    echo "Stop:   ./llama.sh stop"
}

cmd_stop() {
    echo "Stopping llama servers..."
    stop_server "llama-embed"    "$PID_EMBED"
    stop_server "llama-generate" "$PID_GENERATE"
}

cmd_status() {
    for name in embed generate; do
        pid_file="${PID_DIR}/llama-${name}.pid"
        if is_running "$pid_file"; then
            echo "  llama-${name}    running (pid $(cat "$pid_file"))"
        else
            echo "  llama-${name}    stopped"
        fi
    done
}

# ── main ─────────────────────────────────────────────────────────────────────

case "${1:-start}" in
    start)  cmd_start  ;;
    stop)   cmd_stop   ;;
    status) cmd_status ;;
    *)
        echo "Usage: $0 [start|stop|status]"
        exit 1
        ;;
esac
