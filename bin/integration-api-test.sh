#!/usr/bin/env bash
set -euo pipefail

URL="${1:-http://localhost:8080/pack}"
TOTAL_LIMIT=50

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required" >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

request_idx=0
fail_count=0

post_payload() {
  local payload="$1"
  local label="$2"
  local response_file="$tmp_dir/resp_${request_idx}.json"

  local curl_exit=0
  set +e
  status=$(curl -s -o "$response_file" -w "%{http_code}" \
    -X POST "$URL" \
    -H "Content-Type: application/json" \
    -d "$payload")
  curl_exit=$?
  set -e

  if (( curl_exit != 0 )); then
    echo "[$request_idx] $label -> curl exit $curl_exit"
    ((fail_count+=1))
    return
  fi

  if [[ "$status" == "200" || "$status" == "400" || "$status" == "422" || "$status" == "500" ]]; then
    echo "[$request_idx] $label -> $status | payload=$payload"
    if [[ "$label" == invalid* ]]; then
      echo "[$request_idx] response=$(cat "$response_file")"
    fi
  else
    echo "[$request_idx] $label -> $status (unexpected) | payload=$payload"
    if [[ "$label" == invalid* ]]; then
      echo "[$request_idx] response=$(cat "$response_file")"
    fi
    ((fail_count+=1))
  fi
}

# --- Invalid payloads (always sent) ---
echo "==== Invalid payloads ===="

# Invalid payloads (string, null, missing fields) - always sent
invalid_payloads=(
  '{"products":null}'
  '{"products":"not-an-array"}'
  '{"products":[{"id":1,"width":"wide","height":2,"length":3,"weight":1}]}'
  '{"products":[{"id":1,"width":2,"height":null,"length":3,"weight":1}]}'
  '{"products":[{"id":1,"height":2,"length":3,"weight":1}]}'
  '{"products":[{"id":1,"width":2,"height":3,"length":-1,"weight":1}]}'
  '{"products":[{"id":1,"width":0,"height":3,"length":1,"weight":1}]}'
  '{"products":[{"id":1,"width":2,"height":3,"length":1,"weight":"heavy"}]}'
  '{"products":[{"id":1,"width":2,"height":3,"length":1,"weight":0}]}'
  '{"products":[{"id":1,"width":2,"height":3,"length":1}]}'
  '{"products":[{"id":1,"width":1001,"height":2,"length":3,"weight":1}]}'
  '{"products":[{"id":1,"width":2,"height":1001,"length":3,"weight":1}]}'
  '{"products":[{"id":1,"width":2,"height":3,"length":1001,"weight":1}]}'
  '{"products":[{"id":1,"width":2,"height":3,"length":1,"weight":20001}]}'
  '{}'
)

for payload in "${invalid_payloads[@]}"; do
  post_payload "$payload" "invalid"
  ((request_idx+=1))
done

echo "==== Valid payloads ===="

# Valid payloads (generate ~30)
size_values=(1 2.5 5 10 20)
weight_values=(0.5 1 5 10 25)

for w in "${size_values[@]}"; do
  for h in "${size_values[@]}"; do
    for l in "${size_values[@]}"; do
      weight="${weight_values[$((request_idx % ${#weight_values[@]}))]}"
      payload=$(cat <<JSON
{"products":[{"id":1,"width":$w,"height":$h,"length":$l,"weight":$weight}]}
JSON
)
      post_payload "$payload" "valid:1-item"
      ((request_idx+=1))
      if (( request_idx >= TOTAL_LIMIT )); then
        break 3
      fi

      # Add a 5-item variant occasionally
      if (( request_idx % 7 == 0 )); then
        payload=$(cat <<JSON
{"products":[
  {"id":1,"width":$w,"height":$h,"length":$l,"weight":$weight},
  {"id":2,"width":2.0,"height":1.5,"length":3.0,"weight":1.2},
  {"id":3,"width":1.0,"height":2.0,"length":2.5,"weight":0.8},
  {"id":4,"width":4.0,"height":3.0,"length":2.0,"weight":2.2},
  {"id":5,"width":3.5,"height":2.5,"length":4.5,"weight":3.1}
]}
JSON
)
        post_payload "$payload" "valid:5-items"
        ((request_idx+=1))
        if (( request_idx >= TOTAL_LIMIT )); then
          break 3
        fi
      fi
    done
  done
done

# Multi-item valid payloads
for i in {1..10}; do
  payload=$(cat <<JSON
{"products":[
  {"id":1,"width":2.0,"height":2.0,"length":2.0,"weight":1.0},
  {"id":2,"width":3.0,"height":1.5,"length":4.0,"weight":2.5}
]}
JSON
)
  post_payload "$payload" "valid:2-items"
  ((request_idx+=1))
  if (( request_idx >= TOTAL_LIMIT )); then
    break
  fi
done

echo "Done. Requests: $request_idx, unexpected statuses: $fail_count"
if (( fail_count > 0 )); then
  exit 1
fi
