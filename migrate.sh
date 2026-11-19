#!/bin/bash

# This file is used by the docker container to build the database schema and seed the database with initial data

cd /var/www/html

# Validate expected environment variables
echo "AdamRMS - Checking for Environment Variables"
if [[ ! -v DB_HOSTNAME ]] || [[ ! -v DB_DATABASE ]] || [[ ! -v DB_USERNAME ]] || [[ ! -v DB_PASSWORD ]]; then
    echo "AdamRMS - Expected Environment Variables not set"
    exit 1
fi

# Database migration & seed
echo "AdamRMS - Starting Migration Script"

# Stop rather than start the app on a database that isn't migrated: the pages would fail on missing tables
if ! php vendor/bin/phinx migrate -e production; then
    echo "AdamRMS - Database migration failed, not starting"
    exit 1
fi
if ! php vendor/bin/phinx seed:run -e production; then
    echo "AdamRMS - Database seeding failed, not starting"
    exit 1
fi

if [[ -v DEV_MODE ]] && [[ "${DEV_MODE}" == 'true' ]]; then
    echo "AdamRMS - Running in DEV MODE"
fi

# Start Server
echo "AdamRMS - Starting Apache2"
apache2-foreground