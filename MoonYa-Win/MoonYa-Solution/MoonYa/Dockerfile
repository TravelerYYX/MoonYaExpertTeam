FROM php:8.2-apache

# 安装系统依赖和 PHP 扩展
RUN apt-get update && apt-get install -y \
    ffmpeg \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_mysql \
        mysqli \
        mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 启用 Apache 模块
RUN a2enmod rewrite headers

# 安装 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 复制项目代码
COPY wwwroot/ /var/www/html/

# 安装 PHP 依赖
RUN composer install --no-dev --optimize-autoloader

# 创建必要的目录并设置权限
RUN mkdir -p downloads/temp \
    && mkdir -p admin/logs \
    && mkdir -p uploads/community/videos \
    && mkdir -p uploads/community/covers \
    && mkdir -p uploads/community/hls \
    && mkdir -p uploads/avatars \
    && mkdir -p video_gen/uploads \
    && mkdir -p creation/temp \
    && chown -R www-data:www-data /var/www/html

# 复制启动脚本
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
