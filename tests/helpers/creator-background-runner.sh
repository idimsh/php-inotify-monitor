#!/usr/bin/env bash

SCRIPT_NAME="$(cd $(dirname "$0"); pwd -P)/$(basename "$0")"
SCRIPT_DIR="$(dirname "$SCRIPT_NAME")"

nohup bash "${SCRIPT_DIR}"/create-dir-tree.sh 1>/dev/null 2>&1 &
 [ $? -ne 0 ] && exit 1 || echo $!
