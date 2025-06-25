#!/bin/bash

# Run PHP_CodeSniffer for PSR coding standards
./vendor/bin/phpcs -d memory_limit=512M --standard=PSR12 --warning-severity=0 src/
