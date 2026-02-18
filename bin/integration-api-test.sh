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
fit_request_count=0

expected_status_for_label() {
  local label="$1"

  case "$label" in
    valid:fit*)
      echo "200"
      ;;
    valid:no-fit*)
      echo "422"
      ;;
    protocol:missing-content-type)
      echo "200|400|422"
      ;;
    invalid*|malformed*|protocol*)
      echo "400"
      ;;
    valid*)
      echo "200|422"
      ;;
    *)
      echo "200|400|422|500"
      ;;
  esac
}

post_without_json_content_type() {
  local payload="$1"
  local label="$2"
  local response_file="$tmp_dir/resp_${request_idx}.json"

  local curl_exit=0
  set +e
  status=$(curl -s -o "$response_file" -w "%{http_code}" \
    -X POST "$URL" \
    -d "$payload")
  curl_exit=$?
  set -e

  if (( curl_exit != 0 )); then
    echo "[$request_idx] $label -> curl exit $curl_exit"
    ((fail_count+=1))
    return
  fi

  expected_status_regex="$(expected_status_for_label "$label")"

  if [[ "$status" =~ ^($expected_status_regex)$ ]]; then
    echo "[$request_idx] $label -> $status | payload=$payload"
    echo "[$request_idx] response=$(cat "$response_file")"
  else
    echo "[$request_idx] $label -> $status (unexpected, expected: $expected_status_regex) | payload=$payload"
    echo "[$request_idx] response=$(cat "$response_file")"
    ((fail_count+=1))
  fi
}

post_payload() {
  local payload="$1"
  local label="$2"
  local response_file="$tmp_dir/resp_${request_idx}.json"

  if [[ "$label" == valid:fit* ]]; then
    ((fit_request_count+=1))
  fi

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

  expected_status_regex="$(expected_status_for_label "$label")"

  if [[ "$status" =~ ^($expected_status_regex)$ ]]; then
    echo "[$request_idx] $label -> $status | payload=$payload"
    if [[ "$label" == invalid* || "$label" == malformed* || "$label" == protocol* || "$label" == valid:no-fit* ]]; then
      echo "[$request_idx] response=$(cat "$response_file")"
    fi
  else
    echo "[$request_idx] $label -> $status (unexpected, expected: $expected_status_regex) | payload=$payload"
    if [[ "$label" == invalid* || "$label" == malformed* || "$label" == protocol* || "$label" == valid:no-fit* ]]; then
      echo "[$request_idx] response=$(cat "$response_file")"
    fi
    ((fail_count+=1))
  fi
}

# --- Protocol/contract checks (always sent) ---
echo "==== Protocol checks ===="

get_status=$(curl -s -o "$tmp_dir/get_resp.json" -w "%{http_code}" -X GET "$URL")
if [[ "$get_status" == "405" || "$get_status" == "404" ]]; then
  echo "[$request_idx] protocol:get-not-allowed -> $get_status"
elif grep -qi "Method not allowed" "$tmp_dir/get_resp.json"; then
  echo "[$request_idx] protocol:get-not-allowed -> $get_status (accepted: body indicates method is not allowed)"
else
  echo "[$request_idx] protocol:get-not-allowed -> $get_status (unexpected, expected: 404|405 or 'Method not allowed' body)"
  echo "[$request_idx] response=$(cat "$tmp_dir/get_resp.json")"
  ((fail_count+=1))
fi
((request_idx+=1))

post_without_json_content_type '{"products":[{"width":2,"height":2,"length":2,"weight":1}]}' "protocol:missing-content-type"
((request_idx+=1))

post_payload '{"products":[' "malformed:json"
((request_idx+=1))

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

echo "==== Valid payloads (general) ===="

# Valid fit payloads only; rotate these until TOTAL_LIMIT is reached
general_fit_payloads=(
  '{"products":[{"id":1,"width":1,"height":1,"length":1,"weight":10}]}'
  '{"products":[{"id":1,"width":1,"height":1,"length":2.5,"weight":25}]}'
  '{"products":[{"id":1,"width":1,"height":1,"length":20,"weight":10}]}'
  '{"products":[{"id":1,"width":1,"height":2.5,"length":1,"weight":25}]}'
  '{"products":[{"id":1,"width":1,"height":2.5,"length":2.5,"weight":1}]}'
  '{"products":[{"id":1,"width":1,"height":2.5,"length":5,"weight":1}]}'
  '{"products":[{"id":1,"width":1,"height":2.5,"length":10,"weight":5}]}'
  '{"products":[{"id":1,"width":1,"height":2.5,"length":20,"weight":25}]}'
  '{"products":[{"id":1,"width":1,"height":5,"length":2.5,"weight":1}]}'
  '{"products":[{"id":1,"width":1,"height":5,"length":20,"weight":25}]}'
  '{"products":[{"id":1,"width":2.5,"height":1,"length":1,"weight":5}]}'
  '{"products":[{"id":1,"width":2.5,"height":1,"length":2.5,"weight":10}]}'
  '{"products":[{"id":1,"width":2.5,"height":1,"length":5,"weight":1}]}'
  '{"products":[{"id":1,"width":2.5,"height":1,"length":10,"weight":5}]}'
  '{"products":[{"id":1,"width":2.5,"height":2.5,"length":1,"weight":10}]}'
  '{"products":[{"id":1,"width":2.5,"height":5,"length":10,"weight":5}]}'
)

fit_payload_total=${#general_fit_payloads[@]}
fit_payload_index=0

while (( fit_request_count < TOTAL_LIMIT )); do
  payload="${general_fit_payloads[$((fit_payload_index % fit_payload_total))]}"
  post_payload "$payload" "valid:fit:1-item"
  ((request_idx+=1))
  ((fit_payload_index+=1))
done

echo "==== Valid payloads (expected no-fit) ===="
no_fit_payloads=(
  '{"products":[{"id":1,"width":1,"height":1,"length":5,"weight":0.5}]}'
  '{"products":[{"id":1,"width":1,"height":1,"length":10,"weight":5}]}'
  '{"products":[{"id":1,"width":1,"height":5,"length":1,"weight":0.5}]}'
  '{"products":[{"id":1,"width":1,"height":5,"length":5,"weight":5}]}'
  '{"products":[{"id":1,"width":1,"height":5,"length":10,"weight":10}]}'
  '{"products":[{"id":1,"width":1,"height":10,"length":1,"weight":1}]}'
  '{"products":[{"id":1,"width":1,"height":10,"length":10,"weight":25}]}'
  '{"products":[{"id":1,"width":1,"height":10,"length":5,"weight":10}]}'
  '{"products":[
    {"id":1,"width":2.5,"height":2.5,"length":5,"weight":0.5},
    {"id":2,"width":2.0,"height":1.5,"length":3.0,"weight":1.2},
    {"id":3,"width":1.0,"height":2.0,"length":2.5,"weight":0.8},
    {"id":4,"width":4.0,"height":3.0,"length":2.0,"weight":2.2},
    {"id":5,"width":3.5,"height":2.5,"length":4.5,"weight":3.1}
  ]}'
  '{"products":[
    {"id":1,"width":2.0,"height":2.0,"length":2.0,"weight":1.0},
    {"id":2,"width":3.0,"height":1.5,"length":4.0,"weight":2.5}
  ]}'
  '{"products":[{"id":1,"width":1000,"height":1000,"length":1000,"weight":1}]}'
)

for payload in "${no_fit_payloads[@]}"; do
  post_payload "$payload" "valid:no-fit"
  ((request_idx+=1))
done

echo "Done. Requests: $request_idx, unexpected statuses: $fail_count"
if (( fail_count > 0 )); then
  exit 1
fi
