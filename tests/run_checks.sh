#!/usr/bin/env bash
# Black-box security checks for the repaired Campus Notes app.
# Usage:  BASE=http://127.0.0.1:8000 bash tests/run_checks.sh
# Optional: ERR_BASE=<url of a second instance started WITHOUT database credentials> to test error handling.
# Run against a FRESHLY imported database (the checks create and edit notes).
# Uses only the public synthetic lab accounts from README.md. Prints no passwords, cookies or tokens.
set -u
BASE=${BASE:-http://127.0.0.1:8000}
ORIGIN=${ORIGIN:-$BASE}
EVIL=https://evil.example
ALEX_EMAIL=alex@example.test; ALEX_PW=LabOnly-Alex-123
SAM_EMAIL=sam@example.test;   SAM_PW=LabOnly-Sam-456
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0

ok()  { PASS=$((PASS+1)); printf 'PASS  %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf 'FAIL  %s  (%s)\n' "$1" "$2"; }
expect() { # name, condition-exit-status, detail
  if [ "$2" -eq 0 ]; then ok "$1"; else bad "$1" "$3"; fi; }

page()   { curl -s -b "$1" -c "$1" "$BASE/${2:-}"; }
token()  { page "$1" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1; }
sid()    { awk '$6=="CAMPUSNOTESSID"{print $7}' "$1" 2>/dev/null | tail -1; }
post()   { # jar, then curl --data args...
  local jar=$1; shift; local tok; tok=$(token "$jar")
  curl -s -o "$T/last" -w '%{http_code}' -b "$jar" -c "$jar" --data-urlencode "csrf_token=$tok" "$@" "$BASE/"; }
login()  { post "$1" -d action=login --data-urlencode "email=$2" --data-urlencode "password=$3" >/dev/null; }
api()    { # jar, method, [json], extra curl args...
  local jar=$1 m=$2 body=${3:-}; shift 3 2>/dev/null || shift $#
  if [ -n "$body" ]; then
    curl -s -o "$T/api" -D "$T/hdr" -w '%{http_code}' -b "$jar" -X "$m" -H 'Content-Type: application/json' --data-binary "$body" "$@" "$BASE/api.php"
  else
    curl -s -o "$T/api" -D "$T/hdr" -w '%{http_code}' -b "$jar" -X "$m" "$@" "$BASE/api.php"
  fi; }
apitok() { api "$1" "$2" "$3" -H "X-CSRF-Token: $(token "$1")" -H "Origin: $ORIGIN" "${@:4}"; }
sam_note3_title() { api "$SAM" GET "" -G -d id=3 >/dev/null; sed -n 's/.*"title":"\([^"]*\)".*/\1/p' "$T/api"; }

ALEX=$T/alex; SAM=$T/sam; ANON=$T/anon; CAROL=$T/carol
touch "$ANON"

echo "== Login and sessions =="
page "$ALEX" >/dev/null; before=$(sid "$ALEX")
login "$ALEX" "$ALEX_EMAIL" "$ALEX_PW"; after=$(sid "$ALEX")
page "$ALEX" | grep -q 'Your notes'; expect "W01 valid login reaches the notes page" $? "not logged in"
[ -n "$before" ] && [ -n "$after" ] && [ "$before" != "$after" ]; expect "W02 session ID changes at login (fixation)" $? "same ID"
login "$SAM" "$SAM_EMAIL" "$SAM_PW"

login "$T/bad" "$ALEX_EMAIL" "wrong-password-000"; p=$(page "$T/bad")
echo "$p" | grep -q 'Invalid email or password' && ! echo "$p" | grep -q 'Your notes' && ! echo "$p" | grep -q "$ALEX_EMAIL"
expect "W03 invalid login is rejected with a generic message" $? "unexpected page"
login "$T/sqli" "alex@example.test' -- " "x"; page "$T/sqli" | grep -q 'Your notes'; [ $? -ne 0 ]
expect "W04 SQL injection in login email does not log in" $? "logged in"
grep -qi 'httponly' <(curl -s -D - -o /dev/null "$BASE/"); expect "W05 session cookie is HttpOnly" $? "no HttpOnly"
curl -s -D - -o /dev/null "$BASE/" | grep -qi 'samesite=lax'; expect "W06 session cookie is SameSite=Lax" $? "no SameSite"

echo "== Note ownership (web) =="
p=$(page "$ALEX"); echo "$p" | grep -q 'Alex: course' && ! echo "$p" | grep -q 'Sam:'
expect "W07 Alex's list shows only Alex's notes" $? "other notes shown"
page "$ALEX" "?edit=3" | grep -q 'Sam: study'; [ $? -ne 0 ]; expect "W08 Alex cannot open Sam's note for editing" $? "Sam's note shown"
post "$ALEX" -d action=update -d id=3 -d title=HACKED -d body=x >/dev/null
[ "$(sam_note3_title)" = "Sam: study plan" ]; expect "W09 Alex cannot update Sam's note" $? "title changed"
post "$ALEX" -d action=delete -d id=3 >/dev/null
[ "$(sam_note3_title)" = "Sam: study plan" ]; expect "W10 Alex cannot delete Sam's note" $? "note gone"
page "$SAM" "?delete=4" >/dev/null; page "$SAM" | grep -q 'Sam: meeting'; expect "W11 old GET ?delete= link no longer deletes" $? "deleted"

echo "== CSRF and methods (web) =="
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$ALEX" -H "Origin: $EVIL" -d action=save -d title=CSRF-NOTE -d body=x "$BASE/")
[ "$code" = 403 ] && ! page "$ALEX" | grep -q 'CSRF-NOTE'; expect "W12 POST without CSRF token is rejected (403, nothing saved)" $? "code $code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$ALEX" -d action=delete -d id=1 -d csrf_token=forged "$BASE/")
[ "$code" = 403 ] && page "$ALEX" | grep -q 'Alex: course'; expect "W13 POST with a forged CSRF token is rejected" $? "code $code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$ALEX" -X DELETE "$BASE/"); [ "$code" = 405 ]
expect "W14 unsupported method returns 405" $? "code $code"

echo "== Client-controlled owner and role =="
post "$ALEX" -d action=save -d user_id=2 -d title=OWNER-TEST -d body=x >/dev/null
page "$ALEX" | grep -q 'OWNER-TEST' && ! page "$SAM" | grep -q 'OWNER-TEST'; expect "W15 posted user_id is ignored (note owned by session user)" $? "owner tampered"
post "$CAROL" -d action=register -d role=admin --data-urlencode email=carol@example.test --data-urlencode password=LabOnly-Carol-789 >/dev/null
login "$CAROL" carol@example.test LabOnly-Carol-789
page "$CAROL" | grep -q 'Role: student'; expect "W16 registering with role=admin still creates a student" $? "role not student"
post "$T/weak" -d action=register --data-urlencode email=weak@example.test --data-urlencode password=short >/dev/null
page "$T/weak" | grep -q 'Password must be at least'; expect "W17 short password is refused at registration" $? "accepted"

echo "== Output escaping =="
post "$ALEX" -d action=save --data-urlencode 'title=<script>alert(1)</script>' --data-urlencode 'body=<img src=x onerror=alert(2)>' >/dev/null
p=$(page "$ALEX"); echo "$p" | grep -q '&lt;script&gt;alert(1)&lt;/script&gt;' && echo "$p" | grep -q '&lt;img src=x onerror=alert(2)&gt;' && ! echo "$p" | grep -q '<script>alert(1)'
expect "W18 HTML in title/body is rendered as text" $? "raw HTML in page"
id=$(echo "$p" | grep -o 'edit=[0-9]*' | head -1 | cut -d= -f2)
page "$ALEX" "?edit=$id" | grep -q 'value="&lt;script&gt;'; expect "W19 HTML is escaped inside the edit form attribute" $? "not escaped"
curl -s "$BASE/?message=%3Cscript%3Ealert(3)%3C/script%3E" | grep -q '<script>alert(3)'; [ $? -ne 0 ]
expect "W20 ?message= is no longer reflected" $? "reflected"
curl -s -D - -o /dev/null "$BASE/" | grep -qi "content-security-policy: default-src 'none'"; expect "W21 Content-Security-Policy header present" $? "no CSP"

echo "== API authentication and authorization =="
code=$(api "$ANON" GET ""); [ "$code" = 401 ] && ! grep -q 'Alex:' "$T/api"; expect "A01 GET without a session -> 401, no notes" $? "code $code"
code=$(api "$ANON" GET "" -G -d user_id=1); [ "$code" = 401 ] && ! grep -q 'Alex:' "$T/api"; expect "A02 GET ?user_id=1 without a session -> 401" $? "code $code"
code=$(api "$ANON" POST '{"id":3,"title":"x","body":"x"}'); [ "$code" = 401 ] && [ "$(sam_note3_title)" = "Sam: study plan" ]
expect "A03 POST without a session -> 401, unchanged" $? "code $code"
code=$(api "$ALEX" GET "" -G -d user_id=2); [ "$code" = 403 ] && ! grep -q 'Sam:' "$T/api"; expect "A04 Alex GET ?user_id=2 -> 403" $? "code $code"
code=$(api "$ALEX" GET "" -G -d id=3); [ "$code" = 404 ] && ! grep -q 'Sam:' "$T/api"; expect "A05 Alex GET Sam's note id -> 404" $? "code $code"
code=$(api "$ALEX" GET ""); [ "$code" = 200 ] && grep -q 'Alex:' "$T/api" && ! grep -q 'Sam:' "$T/api"; expect "A06 Alex GET lists only Alex's notes" $? "code $code"
code=$(apitok "$ALEX" POST '{"id":3,"title":"HACKED","body":"x"}'); [ "$code" = 404 ] && [ "$(sam_note3_title)" = "Sam: study plan" ]
expect "A07 Alex POST update of Sam's note -> 404, unchanged" $? "code $code"
code=$(apitok "$ALEX" POST '{"id":2,"title":"Alex: project idea (api)","body":"Updated via API."}'); [ "$code" = 200 ] && grep -q '"updated":true' "$T/api"
expect "A08 Alex POST update of own note -> 200" $? "code $code"

echo "== API validation =="
code=$(apitok "$ALEX" POST '{"id":2,"title":'); [ "$code" = 400 ] && grep -q 'Malformed JSON' "$T/api" && ! grep -qiE 'warning|fatal|stack|\.php' "$T/api"
expect "A09 malformed JSON -> 400 generic error" $? "code $code"
code=$(apitok "$ALEX" POST '[1,2,3]'); [ "$code" = 400 ]; expect "A10 JSON array instead of object -> 400" $? "code $code"
code=$(apitok "$ALEX" POST '{"id":"2 OR 1=1","title":"t","body":"b"}'); [ "$code" = 400 ]; expect "A11 non-integer id -> 400" $? "code $code"
code=$(apitok "$ALEX" POST '{"id":2,"title":["x"],"body":"b"}'); [ "$code" = 422 ]; expect "A12 wrong field type -> 422" $? "code $code"
code=$(apitok "$ALEX" POST '{"id":2,"title":"","body":"b"}'); [ "$code" = 422 ]; expect "A13 empty title -> 422" $? "code $code"
code=$(apitok "$ALEX" POST '{"id":2,"title":"t","body":"b","user_id":2}'); [ "$code" = 400 ]; expect "A14 unexpected field (user_id) -> 400" $? "code $code"
big=$(head -c 20000 /dev/zero | tr '\0' 'a')
code=$(apitok "$ALEX" POST "{\"id\":2,\"title\":\"t\",\"body\":\"$big\"}"); [ "$code" = 413 ]; expect "A15 oversized body -> 413" $? "code $code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$ALEX" -H "X-CSRF-Token: $(token "$ALEX")" -H 'Content-Type: text/plain' --data-binary '{"id":2,"title":"t","body":"b"}' "$BASE/api.php")
[ "$code" = 415 ]; expect "A16 text/plain body -> 415" $? "code $code"

echo "== API cross-origin and CSRF =="
code=$(api "$ALEX" GET "" -H "Origin: $EVIL"); [ "$code" = 403 ] && ! grep -qi 'access-control-allow-origin' "$T/hdr" && ! grep -q 'Alex:' "$T/api"
expect "A17 untrusted Origin GET -> 403, no ACAO, no data" $? "code $code"
code=$(curl -s -o /dev/null -D "$T/hdr" -w '%{http_code}' -X OPTIONS -H "Origin: $EVIL" -H 'Access-Control-Request-Method: POST' "$BASE/api.php")
[ "$code" = 403 ] && ! grep -qi 'access-control-allow-origin' "$T/hdr"; expect "A18 untrusted Origin preflight -> 403" $? "code $code"
code=$(api "$ALEX" GET "" -H "Origin: $ORIGIN"); grep -qi "access-control-allow-origin: $ORIGIN" "$T/hdr" && ! grep -qi 'access-control-allow-origin: \*' "$T/hdr"
expect "A19 trusted Origin gets its exact origin, never *" $? "code $code"
code=$(api "$ALEX" POST '{"id":2,"title":"no token","body":"b"}' -H "Origin: $ORIGIN"); [ "$code" = 403 ]
expect "A20 POST without X-CSRF-Token -> 403" $? "code $code"
code=$(api "$ALEX" POST '{"id":2,"title":"bad token","body":"b"}' -H "X-CSRF-Token: forged" -H "Origin: $ORIGIN"); [ "$code" = 403 ]
expect "A21 POST with a forged X-CSRF-Token -> 403" $? "code $code"
code=$(api "$ALEX" PUT '{"id":2}'); [ "$code" = 405 ]; expect "A22 unsupported method -> 405" $? "code $code"

echo "== Logout =="
old=$(sid "$ALEX")
post "$ALEX" -d action=logout >/dev/null
page "$ALEX" | grep -q 'Your notes'; [ $? -ne 0 ]; expect "W22 after logout the page asks to sign in" $? "still logged in"
code=$(curl -s -o "$T/api" -w '%{http_code}' -H "Cookie: CAMPUSNOTESSID=$old" "$BASE/api.php"); [ "$code" = 401 ]
expect "W23 replaying the old session cookie after logout -> 401" $? "code $code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$SAM" "$BASE/?logout=1"); page "$SAM" | grep -q 'Your notes'
expect "W24 GET ?logout=1 (cross-site link) no longer logs a user out" $? "logged out"

if [ -n "${ERR_BASE:-}" ]; then
  echo "== Error handling (instance without DB credentials) =="
  # A sign-in attempt forces a database connection, which must fail closed and generically.
  EJ=$T/err; etok=$(curl -s -b "$EJ" -c "$EJ" "$ERR_BASE/" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
  r=$(curl -s -w '\n%{http_code}' -b "$EJ" -c "$EJ" --data-urlencode "csrf_token=$etok" -d action=login \
       --data-urlencode "email=$ALEX_EMAIL" --data-urlencode "password=$ALEX_PW" "$ERR_BASE/"); code=${r##*$'\n'}
  [ "$code" = 500 ] && echo "$r" | grep -q 'Something went wrong' && ! echo "$r" | grep -qiE 'sqlstate|pdo|exception|stack|\.php|credential|root'
  expect "E01 DB unavailable during login: generic 500, no internals" $? "code $code"
fi

echo
echo "Passed: $PASS  Failed: $FAIL"
[ "$FAIL" -eq 0 ]
