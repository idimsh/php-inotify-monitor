#!/usr/bin/env bash

SCRIPT_NAME="$(cd $(dirname "$0"); pwd -P)/$(basename "$0")"
SCRIPT_DIR="$(dirname "$SCRIPT_NAME")"
sleep_float="${SCRIPT_DIR}"/sleep-float.php

root=/tmp/___non___existant___dir___

rm -rf ${root}/
"$sleep_float" 0.2

mkdir ${root}
"$sleep_float" 0.2

echo hi > ${root}/file1-at-lev1
"$sleep_float" 0.2

touch ${root}/file1-at-lev1
"$sleep_float" 0.2

mkdir ${root}/dir1-at-lev1
"$sleep_float" 0.2

echo hi > ${root}/dir1-at-lev1/file11-at-lev2
"$sleep_float" 0.2

mkdir ${root}/dir1-at-lev1/dir11-at-lev2
"$sleep_float" 0.2

echo hi > ${root}/dir1-at-lev1/dir11-at-lev2/file111-at-lev3
"$sleep_float" 0.2

chmod 777 ${root}/dir1-at-lev1
"$sleep_float" 0.2

mv ${root}/dir1-at-lev1 ${root}/dir3-at-lev1
"$sleep_float" 0.2

rm -rf ${root}/dir3-at-lev1
"$sleep_float" 0.2

mkdir -p ${root}/dir2-at-lev1
"$sleep_float" 0.2

echo hi > ${root}/dir2-at-lev1/file21-at-lev2
"$sleep_float" 0.2

mkdir ${root}/stop-if-created
"$sleep_float" 0.2

rm -rf ${root}/
