#!/usr/bin/env php
<?php

$sleep_float = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 0;
$sleep_float = is_numeric($sleep_float) ? $sleep_float : 0;
usleep(1000000 * $sleep_float);
