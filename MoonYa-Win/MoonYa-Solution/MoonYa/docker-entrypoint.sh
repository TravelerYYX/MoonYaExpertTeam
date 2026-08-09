#!/bin/bash
set -e

# 将数据库配置从 localhost 改为 Docker 服务名
if [ -n "$DB_HOST" ]; then
    sed -i "s/'db_host' => 'localhost'/'db_host' => '$DB_HOST'/g" /var/www/html/config.php
    sed -i "s/'db_host' => 'localhost'/'db_host' => '$DB_HOST'/g" /var/www/html/admin/config.php
fi

if [ -n "$DB_NAME" ]; then
    sed -i "s/'db_name' => 'ai_system'/'db_name' => '$DB_NAME'/g" /var/www/html/config.php
    sed -i "s/'db_name' => 'ai_system'/'db_name' => '$DB_NAME'/g" /var/www/html/admin/config.php
fi

if [ -n "$DB_USER" ]; then
    sed -i "s/'db_user' => 'root'/'db_user' => '$DB_USER'/g" /var/www/html/config.php
    sed -i "s/'db_user' => 'root'/'db_user' => '$DB_USER'/g" /var/www/html/admin/config.php
fi

if [ -n "$DB_PASS" ]; then
    sed -i "s/'db_pass' => ''/'db_pass' => '$DB_PASS'/g" /var/www/html/config.php
    sed -i "s/'db_pass' => ''/'db_pass' => '$DB_PASS'/g" /var/www/html/admin/config.php
fi

exec "$@"
