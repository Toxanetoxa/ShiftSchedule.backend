#!/bin/bash
set -euo pipefail

# authorized_keys forces this gateway and disables PTY/forwarding for the CI key.
if [[ ! ${SSH_ORIGINAL_COMMAND:-} =~ ^deploy\ ([0-9a-f]{40})$ ]]; then
    echo 'Only deploy <commit SHA> is allowed.' >&2
    exit 64
fi

exec sudo -n /usr/local/sbin/shiftschedule-deploy "${BASH_REMATCH[1]}"
