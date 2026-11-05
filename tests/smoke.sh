#!/bin/sh
# Smoke test against a running stack:  sh tests/smoke.sh http://localhost:8080  (after seeding with --demo)
B="${1:-http://localhost:8080}/api/v1"; J=$(mktemp); PW="${DEMO_PASSWORD:-demo-password-123}"
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
chk() { [ "$2" = "$3" ] && echo "PASS $1" || { echo "FAIL $1 (got $2, want $3)"; FAILED=1; }; }
chk "unauthenticated is 401" "$(code $B/courses)" 401
chk "wrong password is 401" "$(code -X POST -H 'Content-Type: application/json' -d '{"email":"maria@example.edu","password":"nope-nope-nope"}' $B/auth/login)" 401
RES=$(curl -s -c $J -X POST -H 'Content-Type: application/json' -d "{\"email\":\"maria@example.edu\",\"password\":\"$PW\"}" $B/auth/login)
CSRF=$(echo "$RES" | sed -n 's/.*"csrf":"\([a-f0-9]*\)".*/\1/p')
chk "learner login" "$([ -n "$CSRF" ] && echo 200)" 200
chk "learner sees courses" "$(code -b $J $B/courses)" 200
chk "learner cannot list users" "$(code -b $J $B/users)" 403
chk "write without CSRF is 403" "$(code -b $J -X PUT $B/lessons/1/progress)" 403
chk "learner marks lesson done" "$(code -b $J -X PUT -H "X-CSRF-Token: $CSRF" $B/lessons/1/progress)" 200
chk "learner cannot grade" "$(code -b $J -X POST -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d '{"points":10}' $B/submissions/1/grade)" 403
rm -f $J; exit ${FAILED:-0}
