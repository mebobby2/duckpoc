#!/usr/bin/env bash
# Calls the farm-plan MCP server the way an MCP client does, over stdio,
# and pretty-prints the answer.
#
#   bin/mcp-call.sh <farm> --list                      tools and prompts
#   bin/mcp-call.sh <farm> <tool> ['{"arg": "value"}']  call a tool
#   bin/mcp-call.sh <farm> --prompt <name> ['{...}']    get a prompt
set -euo pipefail
cd "$(dirname "$0")/.."

farm=${1:?farm id}
what=${2:?tool name, --list or --prompt}
init='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcp-call","version":"0"}}}'
ready='{"jsonrpc":"2.0","method":"notifications/initialized"}'

args=${3:-}
[ -n "$args" ] || args='{}'

case "$what" in
  --list)   calls='{"jsonrpc":"2.0","id":2,"method":"tools/list"}
{"jsonrpc":"2.0","id":3,"method":"prompts/list"}' ;;
  --prompt) prompt_args=${4:-}
            [ -n "$prompt_args" ] || prompt_args='{}'
            calls="{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"prompts/get\",\"params\":{\"name\":\"${3:?prompt name}\",\"arguments\":$prompt_args}}" ;;
  *)        calls="{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"$what\",\"arguments\":$args}}" ;;
esac

printf '%s\n%s\n%s\n' "$init" "$ready" "$calls" \
  | docker compose exec -T -e INSIGHTS_MCP_FARM="$farm" app-minio php artisan mcp:start farm-plan \
  | python3 -c '
import json, sys
for line in sys.stdin:
    if not line.strip().startswith("{"):
        print("NOT JSON-RPC:", line.rstrip()); continue
    m = json.loads(line)
    if "error" in m: print("PROTOCOL ERROR:", json.dumps(m["error"])); continue
    if m.get("id") in (None, 1): continue
    r = m["result"]
    if "tools" in r:
        for t in r["tools"]: print("tool  ", t["name"], "-", t["description"].split("\n")[0])
    elif "prompts" in r:
        for p in r["prompts"]: print("prompt", p["name"], "-", p["description"])
    elif "messages" in r:
        for msg in r["messages"]: print(msg["content"]["text"])
    else:
        text = r["content"][0]["text"]
        if r.get("isError"): print("TOOL ERROR:", text); continue
        try: print(json.dumps(json.loads(text), indent=2))
        except ValueError: print(text)
'
