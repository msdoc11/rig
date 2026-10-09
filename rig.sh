#!/usr/bin/env sh
# Команды фреймворка rig (Linux, macOS, Git Bash): ./rig.sh dev | check | push | help
exec php "$(dirname "$0")/rig/cli.php" "$@"
